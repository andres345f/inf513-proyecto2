<?php

namespace Tests\Unit;

use App\Exceptions\NegocioException;
use App\Models\ConfirmacionOperacion;
use App\Repositories\DatosWebUsuarioRepository;
use App\Repositories\PagoRepository;
use App\Services\CobroPedidoService;
use App\Services\PlanPagoService;
use PHPUnit\Framework\TestCase;

/**
 * Regla de cobro para preparar, despachar y entregar (R047 y R057) con la excepción del plan de cuotas
 * (PROMPT 7.5): pagado por completo, efectivo pendiente por el saldo exacto o plan válido con la primera cuota pagada.
 */
class CobroPedidoServiceTest extends TestCase
{
    private function pedido(float $total = 210.0): object
    {
        return (object) ['id' => 7, 'usuario_id' => 11, 'codigo' => 'PED-PRUEBA-7', 'total' => number_format($total, 2, '.', '')];
    }

    /** @param  array<int, object>  $pagos  pagos del pedido 7 por id */
    private function cobros(float $pagado, ?object $pendiente = null, array $pagos = [], int $cantidadPlanes = 1): CobroPedidoService
    {
        $repositorio = new class($pagado, $pendiente, $pagos) extends PagoRepository
        {
            public function __construct(private float $pagado, private ?object $pendiente, private array $pagos) {}

            public function totalPagado(int $pedidoId): float
            {
                return $this->pagado;
            }

            public function pendienteDelPedido(int $pedidoId, bool $bloquear = true): ?object
            {
                return $this->pendiente;
            }

            public function buscar(int $id, bool $bloquear = false): ?object
            {
                return $this->pagos[$id] ?? null;
            }
        };
        $datos = $this->createStub(DatosWebUsuarioRepository::class);
        $datos->method('contarPlanesDePedido')->willReturn($cantidadPlanes);

        return new CobroPedidoService($repositorio, new PlanPagoService($datos));
    }

    /**
     * Plan con la forma que guarda PlanPagoService::crear (num_cuotas, total y cuotas con vencimiento semanal).
     * $extra sobrescribe cualquier campo para armar un plan mal formado.
     */
    private function plan(array $cuotas, string $estado = 'ACTIVO', int $pedidoId = 7, array $extra = []): ConfirmacionOperacion
    {
        $plan = new ConfirmacionOperacion;
        $plan->usuario_id = 11;
        $plan->parametros = $extra + [
            'pedido_id' => $pedidoId, 'pedido_codigo' => 'PED-PRUEBA-'.$pedidoId, 'estado' => $estado,
            'total' => round(array_sum(array_column($cuotas, 'monto')), 2), 'num_cuotas' => count($cuotas), 'cuotas' => $cuotas,
        ];

        return $plan;
    }

    /** Cuota n con vencimiento semanal desde el 20/11/2026 (como la crea PlanPagoService). */
    private function cuota(int $numero, float $monto, string $estado, ?int $pagoId = null, ?string $vence = null): array
    {
        return ['numero' => $numero, 'monto' => $monto, 'estado' => $estado, 'pago_id' => $pagoId,
            'vence' => $vence ?? date('Y-m-d', strtotime('2026-11-20 +'.(7 * ($numero - 1)).' days'))];
    }

    private function pago(int $id, float $monto, string $estado = 'PAGADO', int $pedidoId = 7): object
    {
        return (object) ['id' => $id, 'pedido_id' => $pedidoId, 'monto' => number_format($monto, 2, '.', ''), 'estado' => $estado];
    }

    public function test_pagado_por_completo_resuelve_el_cobro(): void
    {
        $this->assertTrue($this->cobros(210.0)->resuelto($this->pedido(), null));
        $this->assertFalse($this->cobros(209.99)->resuelto($this->pedido(), null));
    }

    public function test_un_cobro_mayor_que_el_total_no_es_un_cobro_completo(): void
    {
        // Bs 315 cobrados en un pedido de Bs 210 pasaban como cobro completo.
        $this->assertFalse($this->cobros(315.0)->pagadoPorCompleto($this->pedido()));
        $this->assertFalse($this->cobros(315.0)->resuelto($this->pedido(), null));
        $this->assertFalse($this->cobros(210.01)->resuelto($this->pedido(), null), 'R057: la suma de pagos PAGADO debe igualar el total.');
        $this->assertTrue($this->cobros(210.0)->pagadoPorCompleto($this->pedido()));
    }

    public function test_el_mensaje_avisa_cuando_lo_cobrado_supera_el_total(): void
    {
        try {
            $this->cobros(315.0)->exigirResuelto($this->pedido(), null, 'despachar');
            $this->fail('Debió rechazar el avance con un cobro mayor que el total.');
        } catch (NegocioException $e) {
            $this->assertStringContainsString('No se puede despachar el pedido PED-PRUEBA-7', $e->getMessage());
            $this->assertStringContainsString('Bs 315,00', $e->getMessage());
            $this->assertStringContainsString('supera el total', $e->getMessage());
        }
    }

    public function test_efectivo_pendiente_cuenta_solo_si_cubre_el_saldo_exacto(): void
    {
        $efectivo = fn (float $monto, string $metodo = 'EFECTIVO') => (object) ['id' => 1, 'metodo' => $metodo, 'monto' => number_format($monto, 2, '.', '')];

        $this->assertNotNull($this->cobros(0.0, $efectivo(210))->efectivoContraEntrega($this->pedido()));
        $this->assertNull($this->cobros(0.0, $efectivo(200))->efectivoContraEntrega($this->pedido()), 'No cubre el saldo.');
        $this->assertNull($this->cobros(0.0, $efectivo(210, 'QR'))->efectivoContraEntrega($this->pedido()), 'Un QR pendiente no es contra entrega.');
        $this->assertNull($this->cobros(210.0, $efectivo(210))->efectivoContraEntrega($this->pedido()), 'Sin saldo no hay nada que cobrar.');
        $this->assertNull($this->cobros(300.0, $efectivo(210))->efectivoContraEntrega($this->pedido()), 'Con cobro de más no hay saldo.');
    }

    public function test_plan_valido_con_la_primera_cuota_pagada_es_la_excepcion_del_proyecto_2(): void
    {
        $plan = $this->plan([$this->cuota(1, 105, 'PAGADA', 31), $this->cuota(2, 105, 'PENDIENTE')]);

        $this->assertTrue($this->cobros(105.0, null, [31 => $this->pago(31, 105)])->resuelto($this->pedido(), $plan));
    }

    public function test_plan_valido_de_tres_cuotas_con_redondeo_y_dos_pagadas(): void
    {
        // PlanPagoService::crear reparte Bs 250 en 83,33 + 83,33 + 83,34.
        $plan = $this->plan([$this->cuota(1, 83.33, 'PAGADA', 31), $this->cuota(2, 83.33, 'PAGADA', 32), $this->cuota(3, 83.34, 'PENDIENTE')]);
        $pagos = [31 => $this->pago(31, 83.33), 32 => $this->pago(32, 83.33)];

        $this->assertTrue($this->cobros(166.66, null, $pagos)->planConPrimeraCuotaPagada($this->pedido(250), $plan));
    }

    public function test_el_plan_parcial_requiere_un_solo_titular_y_el_pago_propio_de_cada_cuota(): void
    {
        $pedido = $this->pedido(300);
        $plan = $this->plan([$this->cuota(1, 100, 'PAGADA', 31), $this->cuota(2, 100, 'PAGADA', 32), $this->cuota(3, 100, 'PENDIENTE')]);
        $pagos = [31 => $this->pago(31, 100), 32 => $this->pago(32, 100)];

        $this->assertFalse($this->cobros(200, null, $pagos, cantidadPlanes: 2)->resuelto($pedido, $plan));
        $plan->usuario_id = 12;
        $this->assertFalse($this->cobros(200, null, $pagos)->resuelto($pedido, $plan));
        $plan->usuario_id = 11;

        foreach ([$this->pago(32, 100, 'PAGADO', 99), $this->pago(32, 100, 'RECHAZADO'), $this->pago(32, 95)] as $otroPago) {
            $pagos[32] = $otroPago;
            $this->assertFalse($this->cobros(200, null, $pagos)->resuelto($pedido, $plan));
        }
    }

    public function test_la_excepcion_no_aplica_sin_plan_ni_con_la_primera_cuota_pendiente_o_rechazada(): void
    {
        $pedido = $this->pedido();
        $pagada = $this->cuota(1, 105, 'PAGADA', 31);
        $pendiente = $this->cuota(2, 105, 'PENDIENTE');

        $this->assertFalse($this->cobros(105.0)->planConPrimeraCuotaPagada($pedido, null), 'Sin plan.');
        $this->assertFalse($this->cobros(0.0)->planConPrimeraCuotaPagada($pedido, $this->plan([$this->cuota(1, 105, 'PENDIENTE'), $pendiente])), 'Primera cuota pendiente.');
        $this->assertFalse($this->cobros(0.0, null, [31 => $this->pago(31, 105, 'RECHAZADO')])
            ->planConPrimeraCuotaPagada($pedido, $this->plan([$pagada, $pendiente])), 'Pago de la primera cuota rechazado.');
        $this->assertFalse($this->cobros(105.0, null, [31 => $this->pago(31, 105)])
            ->planConPrimeraCuotaPagada($pedido, $this->plan([$pagada, $pendiente], 'CANCELADO')), 'Plan cancelado.');
        $this->assertFalse($this->cobros(105.0, null, [31 => $this->pago(31, 105)])
            ->planConPrimeraCuotaPagada($pedido, $this->plan([$pagada, $pendiente], 'ACTIVO', 99)), 'Plan de otro pedido.');
        $this->assertFalse($this->cobros(105.0, null, [31 => $this->pago(31, 105, 'PAGADO', 99)])
            ->planConPrimeraCuotaPagada($pedido, $this->plan([$pagada, $pendiente])), 'El pago es de otro pedido.');
        $this->assertFalse($this->cobros(105.0, null, [31 => $this->pago(31, 105)])
            ->planConPrimeraCuotaPagada($pedido, $this->plan([$pagada, $this->cuota(2, 50, 'PENDIENTE')])), 'Las cuotas no suman el total.');
        $this->assertFalse($this->cobros(105.0, null, [31 => $this->pago(31, 105)])
            ->planConPrimeraCuotaPagada($pedido, $this->plan([$pagada, $this->cuota(2, 105, 'PAGADA', 32)])), 'Cuotas dadas por pagadas sin cobro que las respalde.');
    }

    public function test_un_plan_mal_formado_no_es_la_excepcion(): void
    {
        // un PLAN_PAGO de cuatro cuotas de Bs 52,50, sin fechas y con la
        // primera cobrada, hacía que resuelto() devolviera true.
        $pedido = $this->pedido();
        $sinFechas = array_map(fn ($n) => ['numero' => $n, 'monto' => 52.5, 'estado' => $n === 1 ? 'PAGADA' : 'PENDIENTE', 'pago_id' => $n === 1 ? 31 : null], [1, 2, 3, 4]);
        $this->assertFalse($this->cobros(52.5, null, [31 => $this->pago(31, 52.5)])->resuelto($pedido, $this->plan($sinFechas)),
            'Cuatro cuotas sin fechas.');

        $pago = [31 => $this->pago(31, 105)];
        $pagada = $this->cuota(1, 105, 'PAGADA', 31);
        $pendiente = $this->cuota(2, 105, 'PENDIENTE');
        $casos = [
            'Una sola cuota.' => $this->plan([$this->cuota(1, 210, 'PAGADA', 31)]),
            'Cuatro cuotas con fechas semanales.' => $this->plan([$this->cuota(1, 52.5, 'PAGADA', 31), $this->cuota(2, 52.5, 'PENDIENTE'),
                $this->cuota(3, 52.5, 'PENDIENTE'), $this->cuota(4, 52.5, 'PENDIENTE')]),
            'Numeración desde 0.' => $this->plan([['numero' => 0] + $pagada, ['numero' => 1] + $pendiente]),
            'Numeración repetida.' => $this->plan([$pagada, ['numero' => 1] + $pendiente]),
            'Numeración con salto.' => $this->plan([$pagada, ['numero' => 3] + $pendiente]),
            'Una cuota de importe cero.' => $this->plan([$pagada, $this->cuota(2, 0, 'PENDIENTE'), $this->cuota(3, 105, 'PENDIENTE')]),
            'Una cuota de importe negativo.' => $this->plan([$pagada, $this->cuota(2, 155, 'PENDIENTE'), $this->cuota(3, -50, 'PENDIENTE')]),
            'Vencimientos no semanales.' => $this->plan([$pagada, $this->cuota(2, 105, 'PENDIENTE', null, '2026-12-20')]),
            'Vencimiento que no es una fecha.' => $this->plan([$pagada, $this->cuota(2, 105, 'PENDIENTE', null, 'la otra semana')]),
            'Sin vencimiento.' => $this->plan([$pagada, ['vence' => null] + $pendiente]),
            'num_cuotas no coincide con las cuotas.' => $this->plan([$pagada, $pendiente], extra: ['num_cuotas' => 3]),
            'El total del plan no es el del pedido.' => $this->plan([$pagada, $pendiente], extra: ['total' => 200]),
            'Estado de cuota desconocido.' => $this->plan([$pagada, ['estado' => 'VENCIDA'] + $pendiente]),
            'Plan completado con una cuota pendiente.' => $this->plan([$pagada, $pendiente], 'COMPLETADO'),
            'Cuota pagada sin pago asociado.' => $this->plan([['pago_id' => null] + $pagada, $pendiente]),
        ];
        foreach ($casos as $motivo => $plan) {
            $this->assertFalse($this->cobros(105.0, null, $pago)->planConPrimeraCuotaPagada($pedido, $plan), $motivo);
        }

        $this->assertFalse($this->cobros(105.0, null, [31 => $this->pago(31, 100)])->planConPrimeraCuotaPagada($pedido, $this->plan([$pagada, $pendiente])),
            'El pago de la primera cuota no es por su importe.');
        $this->assertFalse($this->cobros(150.0, null, $pago)->planConPrimeraCuotaPagada($pedido, $this->plan([$pagada, $pendiente])),
            'Lo cobrado no coincide con las cuotas pagadas.');
        $this->assertFalse($this->cobros(90.0, null, [31 => $this->pago(31, 90)])->planConPrimeraCuotaPagada($this->pedido(180),
            $this->plan([$this->cuota(1, 90, 'PAGADA', 31), $this->cuota(2, 90, 'PENDIENTE')])), 'Pedido por debajo de Bs 200 (PROMPT 7.5).');
    }

    public function test_el_mensaje_de_rechazo_indica_lo_pagado_y_el_total(): void
    {
        try {
            $this->cobros(50.0)->exigirResuelto($this->pedido(), null, 'preparar');
            $this->fail('Debió rechazar el avance sin cobro.');
        } catch (NegocioException $e) {
            $this->assertSame(422, $e->estadoHttp());
            $this->assertStringContainsString('No se puede preparar el pedido PED-PRUEBA-7', $e->getMessage());
            $this->assertStringContainsString('Bs 50,00 de Bs 210,00', $e->getMessage());
        }
    }

    public function test_el_mensaje_distingue_un_plan_mal_formado(): void
    {
        $plan = $this->plan([$this->cuota(1, 105, 'PAGADA', 31), $this->cuota(2, 105, 'PENDIENTE', null, '2026-12-20')]);

        try {
            $this->cobros(105.0, null, [31 => $this->pago(31, 105)])->exigirResuelto($this->pedido(), $plan, 'preparar');
            $this->fail('Debió rechazar el avance con un plan mal formado.');
        } catch (NegocioException $e) {
            $this->assertStringContainsString('plan de cuotas no es válido', $e->getMessage());
        }
    }
}
