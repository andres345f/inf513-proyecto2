<?php

namespace Tests\Integracion\Pedidos;

use App\Services\ReservaService;
use Illuminate\Support\Facades\DB;

/**
 * H-41 y H-08: el despacho exige cobertura exacta por línea, lotes utilizables (DISPONIBLE y no vencidos),
 * un descuento efectivo por cada reserva y un movimiento SALIDA_VENTA por reserva. Si algo falla no cambia nada.
 * El proceso de vencimiento libera y reasigna las reservas de los lotes vencidos (regla R035 del modelo aprobado).
 */
class DespachoInventarioTest extends PedidosTestCase
{
    public function test_despacho_correcto_registra_una_salida_por_reserva_y_descuenta_cada_lote(): void
    {
        $a = $this->crearProducto();
        $b = $this->crearProducto(['precio_venta' => '20.00']);
        $a1 = $this->crearLoteFeder($a, 3, $this->enDias(40), '2026-10-01 09:00:00');
        $a2 = $this->crearLoteFeder($a, 10, $this->enDias(90), '2026-10-02 09:00:00');
        $b1 = $this->crearLoteFeder($b, 6, null, '2026-10-03 09:00:00');

        $pedido = $this->pedidoPagado(self::CLIENTE, [$a => 5, $b => 6]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION', 'DESPACHADO');

        $reservas = $this->reservas($pedido);
        $this->assertCount(3, $reservas);
        $this->assertSame(['CONSUMIDA'], $reservas->pluck('estado')->unique()->values()->all());

        $salidas = $this->salidas($pedido);
        $this->assertCount(3, $salidas, 'Un movimiento SALIDA_VENTA por cada reserva consumida.');
        foreach ($reservas as $reserva) {
            $salida = $salidas->firstWhere('reserva_id', $reserva->id);
            $this->assertNotNull($salida);
            $this->assertSame($reserva->cantidad, $salida->cantidad);
            $this->assertSame((int) $reserva->lote_feder_id, (int) $salida->lote_feder_id);
            $this->assertSame($this->idDe(self::PERSONAL), (int) $salida->usuario_id);
        }

        $this->assertSame(['0.000', '0.000', 'AGOTADO'], [$this->lote($a1)->cantidad_disponible, $this->lote($a1)->cantidad_reservada, $this->lote($a1)->estado]);
        $this->assertSame(['8.000', '0.000', 'DISPONIBLE'], [$this->lote($a2)->cantidad_disponible, $this->lote($a2)->cantidad_reservada, $this->lote($a2)->estado]);
        $this->assertSame(['0.000', '0.000', 'AGOTADO'], [$this->lote($b1)->cantidad_disponible, $this->lote($b1)->cantidad_reservada, $this->lote($b1)->estado]);
        $this->assertSame('DESPACHADO', $this->pedido($pedido)->estado);
        $this->assertSame('LISTA_PARA_RECOJO', $this->entrega($pedido)->estado);
    }

    public function test_no_despacha_si_una_linea_perdio_parte_de_su_reserva(): void
    {
        $a = $this->crearProducto();
        $b = $this->crearProducto();
        $loteA = $this->crearLoteFeder($a, 10, null, '2026-10-01 09:00:00');
        $this->crearLoteFeder($b, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$a => 4, $b => 2]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION');

        // La reserva de la línea A deja de estar activa (como cuando se libera por un lote inhabilitado).
        $reservaA = $this->reservas($pedido)->firstWhere('lote_feder_id', $loteA);
        DB::update("UPDATE reserva_stock SET estado = 'LIBERADA', fecha_cierre = now() WHERE id = ?", [$reservaA->id]);
        DB::update('UPDATE lote_feder SET cantidad_reservada = cantidad_reservada - ? WHERE id = ?', [$reservaA->cantidad, $loteA]);
        $antes = $this->fotoInventario($pedido);

        $respuesta = $this->avanzar($this->personal(), $pedido, ['hacia' => 'DESPACHADO']);

        $respuesta->assertStatus(409);
        $this->assertStringContainsString('no está cubiert', $respuesta->json('message'));
        $this->assertSame($antes, $this->fotoInventario($pedido), 'Un despacho rechazado no deja cambios parciales.');
        $this->assertCount(0, $this->salidas($pedido));
    }

    public function test_no_despacha_un_pedido_sin_ninguna_reserva(): void
    {
        $a = $this->crearProducto();
        $lote = $this->crearLoteFeder($a, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$a => 4]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION');
        DB::update("UPDATE reserva_stock SET estado = 'LIBERADA', fecha_cierre = now() WHERE lote_feder_id = ?", [$lote]);
        DB::update('UPDATE lote_feder SET cantidad_reservada = 0 WHERE id = ?', [$lote]);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'DESPACHADO'])->assertStatus(409);

        $this->assertSame('EN_PREPARACION', $this->pedido($pedido)->estado);
        $this->assertSame('10.000', $this->lote($lote)->cantidad_disponible);
        $this->assertCount(0, $this->salidas($pedido));
    }

    public function test_no_despacha_desde_un_lote_vencido(): void
    {
        $a = $this->crearProducto();
        $lote = $this->crearLoteFeder($a, 10, $this->enDias(5), '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$a => 2]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION');
        $this->fijarVencimiento($lote, $this->ayer());
        $antes = $this->fotoInventario($pedido);

        $respuesta = $this->avanzar($this->personal(), $pedido, ['hacia' => 'DESPACHADO']);

        $respuesta->assertStatus(409);
        $this->assertStringContainsString('vencido', $respuesta->json('message'));
        $this->assertSame($antes, $this->fotoInventario($pedido));
    }

    public function test_un_lote_que_vence_hoy_todavia_se_despacha(): void
    {
        $a = $this->crearProducto();
        $lote = $this->crearLoteFeder($a, 10, $this->enDias(5), '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$a => 2]);
        $this->fijarVencimiento($lote, now()->toDateString());

        $this->avanzarHasta($pedido, 'EN_PREPARACION', 'DESPACHADO');

        $this->assertSame('8.000', $this->lote($lote)->cantidad_disponible);
    }

    public function test_no_despacha_desde_un_lote_bloqueado(): void
    {
        $a = $this->crearProducto();
        $lote = $this->crearLoteFeder($a, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$a => 2]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION');
        DB::update("UPDATE lote_feder SET estado = 'BLOQUEADO' WHERE id = ?", [$lote]);
        $antes = $this->fotoInventario($pedido);

        $respuesta = $this->avanzar($this->personal(), $pedido, ['hacia' => 'DESPACHADO']);

        $respuesta->assertStatus(409);
        $this->assertStringContainsString('bloqueado', mb_strtolower($respuesta->json('message')));
        $this->assertSame($antes, $this->fotoInventario($pedido));
    }

    public function test_si_el_descuento_de_un_lote_no_se_aplica_se_revierte_todo_el_despacho(): void
    {
        $a = $this->crearProducto();
        $b = $this->crearProducto();
        $this->crearLoteFeder($a, 10, null, '2026-10-01 09:00:00');
        $loteB = $this->crearLoteFeder($b, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$a => 3, $b => 4]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION');
        // Dato incoherente: el lote B dice tener reservado menos de lo que indica su reserva activa.
        DB::update('UPDATE lote_feder SET cantidad_reservada = 1 WHERE id = ?', [$loteB]);
        $antes = $this->fotoInventario($pedido);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'DESPACHADO'])->assertStatus(409);

        $this->assertSame($antes, $this->fotoInventario($pedido), 'La primera línea no debe quedar despachada si la segunda falla.');
        $this->assertCount(0, $this->salidas($pedido));
    }

    public function test_repetir_el_despacho_no_vuelve_a_descontar(): void
    {
        $a = $this->crearProducto();
        $lote = $this->crearLoteFeder($a, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$a => 4]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION', 'DESPACHADO');

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'DESPACHADO'])->assertStatus(409);

        $this->assertSame('6.000', $this->lote($lote)->cantidad_disponible);
        $this->assertCount(1, $this->salidas($pedido));
        $this->assertSame('DESPACHADO', $this->pedido($pedido)->estado);
    }

    public function test_no_prepara_un_pedido_sin_cobertura_completa(): void
    {
        $a = $this->crearProducto();
        $lote = $this->crearLoteFeder($a, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$a => 4]);
        DB::update("UPDATE reserva_stock SET estado = 'LIBERADA', fecha_cierre = now() WHERE lote_feder_id = ?", [$lote]);
        DB::update('UPDATE lote_feder SET cantidad_reservada = 0 WHERE id = ?', [$lote]);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'EN_PREPARACION'])->assertStatus(409);

        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);
    }

    // ------------------------------------------------------------------ vencimiento de lotes con reservas

    public function test_el_proceso_de_vencimiento_libera_y_reasigna_las_reservas_del_lote_vencido(): void
    {
        $a = $this->crearProducto();
        $pronto = $this->crearLoteFeder($a, 10, $this->enDias(3), '2026-10-05 09:00:00');
        $tarde = $this->crearLoteFeder($a, 10, $this->enDias(200), '2026-10-01 09:00:00');
        $pedido = $this->pedidoDe(self::CLIENTE, [$a => 4]);
        $original = $this->reservas($pedido)[0];
        $this->assertSame($pronto, (int) $original->lote_feder_id);
        $this->fijarVencimiento($pronto, $this->ayer());
        $movimientos = DB::table('movimiento_inventario')->count();

        $resultado = app(ReservaService::class)->vencerLotes();

        $this->assertContains($pronto, array_column($resultado, 'lote_id'));
        $this->assertSame('VENCIDO', $this->lote($pronto)->estado);
        $this->assertSame('0.000', $this->lote($pronto)->cantidad_reservada);
        $this->assertSame('10.000', $this->lote($pronto)->cantidad_disponible, 'Vencer el lote no cambia su saldo físico.');
        $reservas = $this->reservas($pedido);
        $this->assertCount(2, $reservas);
        $this->assertSame('LIBERADA', $reservas[0]->estado);
        $this->assertSame('ACTIVA', $reservas[1]->estado);
        $this->assertSame($tarde, (int) $reservas[1]->lote_feder_id);
        $this->assertSame('4.000', $reservas[1]->cantidad);
        $this->assertSame($original->fecha_expiracion, $reservas[1]->fecha_expiracion, 'La reasignación conserva el plazo de pago.');
        $this->assertSame('4.000', $this->lote($tarde)->cantidad_reservada);
        $this->assertSame($movimientos, DB::table('movimiento_inventario')->count(), 'No se crean ni recalculan movimientos.');

        $fila = $this->bitacora('LOTE_FEDER', $pronto)->last();
        $this->assertSame('MODIFICAR', $fila->accion);
        $this->assertNull($fila->usuario_id);
        $this->assertSame('VENCIDO', $fila->detalle['estado_nuevo']);
        $this->assertEquals(4, $fila->detalle['cantidad_reasignada']);
    }

    public function test_sin_otro_lote_el_pedido_queda_sin_cobertura_hasta_reabastecerlo(): void
    {
        $a = $this->crearProducto();
        $unico = $this->crearLoteFeder($a, 10, $this->enDias(3), '2026-10-05 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$a => 4]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION');
        $this->fijarVencimiento($unico, $this->ayer());

        app(ReservaService::class)->vencerLotes();

        $this->assertSame('VENCIDO', $this->lote($unico)->estado);
        $this->assertSame('LIBERADA', $this->reservas($pedido)[0]->estado);
        $this->assertSame('EN_PREPARACION', $this->pedido($pedido)->estado, 'El pedido conserva su estado; solo pierde la cobertura.');
        $fila = $this->bitacora('LOTE_FEDER', $unico)->last();
        $this->assertSame([$this->pedido($pedido)->codigo], $fila->detalle['pedidos_sin_cobertura']);

        // No se despacha sin cobertura; el detalle ofrece volver a abastecer.
        $this->avanzar($this->personal(), $pedido, ['hacia' => 'DESPACHADO'])->assertStatus(409);
        $this->withToken($this->personal())->getJson("/api/gestion/pedidos/{$pedido}")->assertOk()->assertJsonPath('acciones.abastecer', true);

        // Llega stock nuevo: se vuelve a reservar y el pedido sigue su curso sin cambiar de estado.
        $nuevo = $this->crearLoteFeder($a, 5, $this->enDias(60), '2026-11-20 09:00:00');
        $this->abastecer($this->personal(), $pedido)->assertOk()->assertJsonPath('estado', 'EN_PREPARACION');
        $this->assertSame('4.000', $this->lote($nuevo)->cantidad_reservada);
        $this->avanzarHasta($pedido, 'DESPACHADO');
        $this->assertSame('1.000', $this->lote($nuevo)->cantidad_disponible);
    }

    public function test_abastecer_reasigna_las_reservas_que_quedaron_en_un_lote_no_utilizable(): void
    {
        $a = $this->crearProducto();
        $bloqueado = $this->crearLoteFeder($a, 10, $this->enDias(10), '2026-10-01 09:00:00');
        $bueno = $this->crearLoteFeder($a, 10, $this->enDias(200), '2026-10-02 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$a => 3]);
        $this->assertSame($bloqueado, (int) $this->reservas($pedido)[0]->lote_feder_id);
        DB::update("UPDATE lote_feder SET estado = 'BLOQUEADO' WHERE id = ?", [$bloqueado]);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'EN_PREPARACION'])->assertStatus(409);
        $this->abastecer($this->personal(), $pedido)->assertOk()->assertJsonPath('estado', 'CONFIRMADO');

        $reservas = $this->reservas($pedido);
        $this->assertSame(['LIBERADA', 'ACTIVA'], $reservas->pluck('estado')->all());
        $this->assertSame($bueno, (int) $reservas[1]->lote_feder_id);
        $this->assertSame('0.000', $this->lote($bloqueado)->cantidad_reservada);
        $this->assertSame('BLOQUEADO', $this->lote($bloqueado)->estado, 'Abastecer no cambia el estado del lote.');
        $this->avanzarHasta($pedido, 'EN_PREPARACION', 'DESPACHADO');
        $this->assertSame('7.000', $this->lote($bueno)->cantidad_disponible);
    }

    public function test_el_proceso_de_vencimiento_no_toca_lotes_vigentes_ni_bloqueados(): void
    {
        $a = $this->crearProducto();
        $vigente = $this->crearLoteFeder($a, 10, $this->enDias(1), '2026-10-01 09:00:00');
        $bloqueado = $this->crearLoteFeder($a, 10, $this->ayer(), '2026-10-02 09:00:00');
        DB::update("UPDATE lote_feder SET estado = 'BLOQUEADO' WHERE id = ?", [$bloqueado]);

        $ids = array_column(app(ReservaService::class)->vencerLotes(), 'lote_id');

        $this->assertNotContains($vigente, $ids);
        $this->assertNotContains($bloqueado, $ids);
        $this->assertSame('DISPONIBLE', $this->lote($vigente)->estado);
        $this->assertSame('BLOQUEADO', $this->lote($bloqueado)->estado);
    }
}
