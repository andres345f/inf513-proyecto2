<?php

namespace Tests\Integracion\Pedidos;

use App\Repositories\InventarioRepository;
use Illuminate\Support\Facades\DB;

/**
 * H-19: los lotes se eligen por FEFO (reglas R026 y R037, AsignadorFefo del Proyecto 1):
 * primero el que vence antes, los lotes sin vencimiento al final, y después fecha de ingreso e id.
 */
class FefoTest extends PedidosTestCase
{
    public function test_la_reserva_toma_primero_el_lote_que_vence_antes_aunque_haya_ingresado_despues(): void
    {
        $producto = $this->crearProducto();
        $ingresoPrimeroVenceTarde = $this->crearLoteFeder($producto, 10, '2028-01-01', '2026-01-10 09:00:00');
        $ingresoDespuesVencePronto = $this->crearLoteFeder($producto, 10, $this->enDias(20), '2026-06-01 09:00:00');
        $sinVencimiento = $this->crearLoteFeder($producto, 10, null, '2025-12-01 09:00:00');

        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 12]);

        $reservas = $this->reservas($pedido);
        $this->assertSame(
            [[$ingresoDespuesVencePronto, '10.000'], [$ingresoPrimeroVenceTarde, '2.000']],
            $reservas->map(fn ($r) => [(int) $r->lote_feder_id, $r->cantidad])->all(),
            'FEFO: primero el lote que vence antes; el que ingresó primero pero vence más tarde va después.'
        );
        $this->assertSame('0.000', $this->lote($sinVencimiento)->cantidad_reservada, 'El lote sin vencimiento va al final.');
    }

    public function test_los_lotes_sin_vencimiento_van_al_final_y_empatan_por_ingreso_y_por_id(): void
    {
        $producto = $this->crearProducto();
        $sinFechaTardio = $this->crearLoteFeder($producto, 2, null, '2026-09-01 09:00:00');
        $sinFechaTemprano = $this->crearLoteFeder($producto, 2, null, '2026-03-01 09:00:00');
        $mismaFechaA = $this->crearLoteFeder($producto, 2, '2027-12-31', '2026-05-01 09:00:00');
        $mismaFechaB = $this->crearLoteFeder($producto, 2, '2027-12-31', '2026-05-01 09:00:00');
        $mismaFechaIngresoAnterior = $this->crearLoteFeder($producto, 2, '2027-12-31', '2026-04-01 09:00:00');

        $orden = app(InventarioRepository::class)->lotesVendibles($producto)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([$mismaFechaIngresoAnterior, $mismaFechaA, $mismaFechaB, $sinFechaTemprano, $sinFechaTardio], $orden);
    }

    public function test_no_reserva_lotes_vencidos_bloqueados_ni_sin_saldo_libre(): void
    {
        $producto = $this->crearProducto();
        $vencido = $this->crearLoteFeder($producto, 10, $this->ayer(), '2026-01-01 09:00:00');
        $bloqueado = $this->crearLoteFeder($producto, 10, $this->enDias(2), '2026-01-02 09:00:00');
        DB::update("UPDATE lote_feder SET estado = 'BLOQUEADO' WHERE id = ?", [$bloqueado]);
        $comprometido = $this->crearLoteFeder($producto, 10, $this->enDias(3), '2026-01-03 09:00:00');
        DB::update('UPDATE lote_feder SET cantidad_reservada = cantidad_disponible WHERE id = ?', [$comprometido]);
        $venceHoy = $this->crearLoteFeder($producto, 10, now()->toDateString(), '2026-01-04 09:00:00');
        $bueno = $this->crearLoteFeder($producto, 10, $this->enDias(60), '2026-01-05 09:00:00');

        $orden = app(InventarioRepository::class)->lotesVendibles($producto)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([$venceHoy, $bueno], $orden, 'Un lote es vendible hasta el día de su vencimiento, inclusive.');
        $this->assertNotContains($vencido, $orden);
    }

    public function test_el_pedido_con_varios_productos_reserva_cada_linea_por_fefo(): void
    {
        $a = $this->crearProducto();
        $b = $this->crearProducto();
        $aTarde = $this->crearLoteFeder($a, 5, '2028-05-01', '2026-02-01 09:00:00');
        $aPronto = $this->crearLoteFeder($a, 5, $this->enDias(15), '2026-08-01 09:00:00');
        $bTarde = $this->crearLoteFeder($b, 5, null, '2026-02-01 09:00:00');
        $bPronto = $this->crearLoteFeder($b, 5, $this->enDias(15), '2026-08-01 09:00:00');

        $pedido = $this->pedidoDe(self::CLIENTE, [$a => 2, $b => 6]);

        $porLote = $this->reservas($pedido)->pluck('cantidad', 'lote_feder_id')->all();
        $this->assertSame([$aPronto => '2.000', $bPronto => '5.000', $bTarde => '1.000'], $porLote);
        $this->assertSame('0.000', $this->lote($aTarde)->cantidad_reservada);
    }

    public function test_la_reposicion_de_una_devolucion_sale_por_fefo(): void
    {
        $producto = $this->crearProducto();
        $original = $this->crearLoteFeder($producto, 2, $this->enDias(30), '2026-01-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$producto => 2]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION', 'DESPACHADO', 'ENTREGADO');
        $salida = $this->salidas($pedido)[0];

        // Devolución ya aprobada con reposición (se registra directamente para aislar la salida de inventario).
        $devolucion = (int) DB::table('devolucion')->insertGetId([
            'movimiento_inventario_id' => $salida->id, 'codigo' => 'DEV-PRUEBA-FEFO', 'cantidad' => 2, 'motivo' => 'Prueba de reposición',
            'estado' => 'APROBADA', 'resolucion' => 'REPOSICION', 'cantidad_aprobada' => 2, 'fecha_resolucion' => now(),
        ]);
        $ingresoPrimeroVenceTarde = $this->crearLoteFeder($producto, 5, '2028-01-01', '2026-02-01 09:00:00');
        $ingresoDespuesVencePronto = $this->crearLoteFeder($producto, 1, $this->enDias(10), '2026-09-01 09:00:00');

        $hecho = app(InventarioRepository::class)->salidaReposicion($producto, 2.0, $devolucion, $this->idDe(self::PERSONAL), 'DEV-PRUEBA-FEFO');

        $this->assertTrue($hecho);
        $movimientos = DB::table('movimiento_inventario')->where('devolucion_id', $devolucion)->where('tipo', 'SALIDA_REPOSICION')->orderBy('id')
            ->get(['lote_feder_id', 'cantidad'])->map(fn ($m) => [(int) $m->lote_feder_id, $m->cantidad])->all();
        $this->assertSame([[$ingresoDespuesVencePronto, '1.000'], [$ingresoPrimeroVenceTarde, '1.000']], $movimientos);
        $this->assertSame('AGOTADO', $this->lote($ingresoDespuesVencePronto)->estado);
        $this->assertSame('4.000', $this->lote($ingresoPrimeroVenceTarde)->cantidad_disponible);
        $this->assertSame('0.000', $this->lote($original)->cantidad_disponible);
    }
}
