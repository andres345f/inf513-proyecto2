<?php

namespace Tests\Unit;

use App\Exceptions\NegocioException;
use App\Models\ConfirmacionOperacion;
use App\Repositories\DatosWebUsuarioRepository;
use App\Services\PlanPagoService;
use PHPUnit\Framework\TestCase;

/**
 * Reglas del plan de cuotas que no dependen de la base: qué cuota se puede pagar (H-06), con qué pago se marca
 * (H-15), cuándo el plan coincide con su pedido (H-15) y cómo se descuentan las cuotas por una devolución (H-09).
 */
class PlanPagoCuotasTest extends TestCase
{
    public function test_exige_indicar_la_cuota_y_que_sea_la_siguiente_pendiente(): void
    {
        $servicio = $this->servicio();
        $plan = $this->plan([['PAGADA', 70, 31], ['PENDIENTE', 70], ['PENDIENTE', 70]]);

        $this->assertSame(2, $servicio->exigirSiguienteCuota($plan, 2)['numero']);
        $this->assertSame(422, $this->estadoDe(fn () => $servicio->exigirSiguienteCuota($plan, null)));
        $this->assertSame(409, $this->estadoDe(fn () => $servicio->exigirSiguienteCuota($plan, 1)));
        $this->assertSame(409, $this->estadoDe(fn () => $servicio->exigirSiguienteCuota($plan, 3)));
    }

    public function test_solo_marca_la_cuota_con_un_pago_pagado_de_ese_pedido_y_por_su_importe(): void
    {
        $servicio = $this->servicio();
        $plan = $this->plan([['PENDIENTE', 105], ['PENDIENTE', 105]]);
        $bueno = (object) ['id' => 40, 'estado' => 'PAGADO', 'pedido_id' => 12, 'monto' => '105.00'];

        foreach ([
            'pago pendiente' => (object) ['id' => 40, 'estado' => 'PENDIENTE', 'pedido_id' => 12, 'monto' => '105.00'],
            'pago rechazado' => (object) ['id' => 40, 'estado' => 'RECHAZADO', 'pedido_id' => 12, 'monto' => '105.00'],
            'pago de otro pedido' => (object) ['id' => 40, 'estado' => 'PAGADO', 'pedido_id' => 99, 'monto' => '105.00'],
            'importe distinto' => (object) ['id' => 40, 'estado' => 'PAGADO', 'pedido_id' => 12, 'monto' => '50.00'],
        ] as $caso => $pago) {
            $this->assertSame(409, $this->estadoDe(fn () => $servicio->registrarPagoDeCuota($plan, 1, $pago)), $caso);
        }
        $this->assertSame(409, $this->estadoDe(fn () => $servicio->registrarPagoDeCuota($plan, 2, $bueno)), 'no se salta la cuota 1');

        $parametros = $servicio->registrarPagoDeCuota($plan, 1, $bueno);
        $this->assertSame('PAGADA', $parametros['cuotas'][0]['estado']);
        $this->assertSame(40, $parametros['cuotas'][0]['pago_id']);
        $this->assertSame('ACTIVO', $parametros['estado']);

        $plan->parametros = $parametros;
        $this->assertSame(409, $this->estadoDe(fn () => $servicio->registrarPagoDeCuota($plan, 2, $bueno)), 'un pago no cubre dos cuotas');
        $otro = (object) ['id' => 41, 'estado' => 'PAGADO', 'pedido_id' => 12, 'monto' => '105.00'];
        $this->assertSame('COMPLETADO', $servicio->registrarPagoDeCuota($plan, 2, $otro)['estado']);
    }

    public function test_el_plan_debe_coincidir_con_el_cliente_el_pedido_y_el_total(): void
    {
        $servicio = $this->servicio();
        $pedido = (object) ['id' => 12, 'usuario_id' => 7, 'total' => '210.00', 'codigo' => 'PED-2026-000012'];

        $this->assertTrue($servicio->esCoherente($this->plan([['PENDIENTE', 105], ['PENDIENTE', 105]]), $pedido));
        $this->assertFalse($servicio->esCoherente($this->plan([['PENDIENTE', 105], ['PENDIENTE', 105]], usuarioId: 8), $pedido), 'otro dueño');
        $this->assertFalse($servicio->esCoherente($this->plan([['PENDIENTE', 105], ['PENDIENTE', 105]], pedidoId: 13), $pedido), 'otro pedido');
        $this->assertFalse($servicio->esCoherente($this->plan([['PENDIENTE', 105], ['PENDIENTE', 100]]), $pedido), 'no suma el total');
        $this->assertFalse($servicio->esCoherente($this->plan([['PAGADA', 105], ['PENDIENTE', 105]]), $pedido), 'cuota pagada sin pago');
        $this->assertFalse($servicio->esCoherente($this->plan([['PENDIENTE', 105], ['PENDIENTE', 105]], total: 300), $pedido), 'total distinto');
        $this->assertFalse($this->servicio(planesDelPedido: 2)->esCoherente($this->plan([['PENDIENTE', 105], ['PENDIENTE', 105]]), $pedido), 'dos planes');
        $this->assertSame(409, $this->estadoDe(fn () => $servicio->exigirCoherente($this->plan([['PENDIENTE', 105], ['PENDIENTE', 100]]), $pedido)));
    }

    public function test_el_descuento_por_devolucion_reduce_las_cuotas_desde_la_ultima_y_cierra_el_plan_si_no_queda_ninguna(): void
    {
        $servicio = $this->servicio();
        $plan = $this->plan([['PAGADA', 70, 31], ['PENDIENTE', 70], ['PENDIENTE', 70]]);

        $tocadas = $servicio->descontarPorDevolucion($plan, 5, 44, 10000);   // Bs 100: toda la cuota 3 y Bs 30 de la 2

        $p = $plan->parametros;
        $this->assertSame([3, 2], array_column($tocadas, 'numero'));
        $this->assertSame(['PAGADA', 'PENDIENTE', 'ANULADA'], array_column($p['cuotas'], 'estado'));
        $this->assertEquals(40.0, $p['cuotas'][1]['monto']);
        $this->assertEquals(70.0, $p['cuotas'][1]['monto_original']);
        $this->assertEquals(0.0, $p['cuotas'][2]['monto']);
        $this->assertSame('ACTIVO', $p['estado']);
        $this->assertSame(4000, $servicio->saldoPendienteCentavos($plan));
        $this->assertSame(10000, $servicio->descontadoDeLineaCentavos($plan, 44));
        $this->assertSame(0, $servicio->descontadoDeLineaCentavos($plan, 45));

        $pedido = (object) ['id' => 12, 'usuario_id' => 7, 'total' => '210.00', 'codigo' => 'PED-2026-000012'];
        $this->assertTrue($servicio->esCoherente($plan, $pedido), 'Cuotas más descuentos siguen sumando el total del pedido.');

        $servicio->descontarPorDevolucion($plan, 6, 44, 4000);
        $this->assertSame('CERRADO_POR_DEVOLUCION', $plan->parametros['estado']);
        $this->assertSame(409, $this->estadoDe(fn () => $servicio->descontarPorDevolucion($plan, 7, 44, 1)), 'ya no hay cuotas que descontar');
    }

    public function test_no_descuenta_mas_que_el_saldo_pendiente(): void
    {
        $servicio = $this->servicio();
        $plan = $this->plan([['PAGADA', 105, 31], ['PENDIENTE', 105]]);

        $this->assertSame(409, $this->estadoDe(fn () => $servicio->descontarPorDevolucion($plan, 5, 44, 10501)));
        $this->assertSame(['PAGADA', 'PENDIENTE'], array_column($plan->parametros['cuotas'], 'estado'));
    }

    public function test_una_cuota_anulada_no_cuenta_como_vencida(): void
    {
        $plan = $this->plan([['PAGADA', 105, 31], ['PENDIENTE', 105]]);
        $parametros = $plan->parametros;
        $parametros['cuotas'][1]['vence'] = '2020-01-08';
        $plan->parametros = $parametros;
        $repositorio = $this->createMock(DatosWebUsuarioRepository::class);
        $repositorio->method('vigentes')->willReturn(collect([$plan]));
        $repositorio->method('actualizarParametros')->willReturnCallback(function (ConfirmacionOperacion $fila, array $nuevos) {
            $fila->parametros = $nuevos;
        });
        $servicio = new PlanPagoService($repositorio);

        $this->assertTrue($servicio->tieneCuotaVencida(7));
        $servicio->descontarPorDevolucion($plan, 5, 44, 10500);
        $this->assertFalse($servicio->tieneCuotaVencida(7));
    }

    private function servicio(int $planesDelPedido = 1): PlanPagoService
    {
        $repositorio = $this->createMock(DatosWebUsuarioRepository::class);
        $repositorio->method('contarPlanesDePedido')->willReturn($planesDelPedido);
        $repositorio->method('actualizarParametros')->willReturnCallback(function (ConfirmacionOperacion $fila, array $parametros) {
            $fila->parametros = $parametros;
        });

        return new PlanPagoService($repositorio);
    }

    /** @param  array<int, array{0:string, 1:int|float, 2?:int}>  $cuotas  [estado, monto, pago_id] */
    private function plan(array $cuotas, int $usuarioId = 7, int $pedidoId = 12, float $total = 210): ConfirmacionOperacion
    {
        $plan = new ConfirmacionOperacion();
        $plan->usuario_id = $usuarioId;
        $plan->parametros = [
            'pedido_id' => $pedidoId, 'pedido_codigo' => 'PED-2026-000012', 'total' => $total, 'num_cuotas' => count($cuotas), 'estado' => 'ACTIVO',
            'cuotas' => array_map(fn ($c, $i) => ['numero' => $i + 1, 'monto' => $c[1], 'vence' => '2099-01-01', 'estado' => $c[0],
                'pago_id' => $c[2] ?? null, 'pagada_en' => null], $cuotas, array_keys($cuotas)),
        ];

        return $plan;
    }

    private function estadoDe(callable $accion): ?int
    {
        try {
            $accion();
        } catch (NegocioException $e) {
            return $e->estadoHttp();
        }

        return null;
    }
}
