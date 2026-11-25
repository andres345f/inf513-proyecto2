<?php

namespace Tests\Integracion\Acceso;

use Illuminate\Support\Facades\DB;

/**
 * H-08 (recepción): una recepción adicional no cambia el estado de un lote BLOQUEADO o VENCIDO (R035).
 * H-44: cada línea recibida deja su fila DETALLE_COMPRA en la bitácora con la misma clave del movimiento de inventario
 * (trazabilidad de recepciones de la matriz aprobada, ck_bitacora_operacion_detalle_compra).
 */
class RecepcionCompraTest extends AccesoTestCase
{
    /** Compra 3 (RECIBIDA_PARCIAL, sin pagar): detalle 7 del lote de origen 8, 120 compradas, 80 recibidas, 10 rechazadas. */
    private const COMPRA = 3;
    private const DETALLE = 7;

    public function test_una_recepcion_adicional_no_desbloquea_un_lote_bloqueado(): void
    {
        $lote = DB::table('lote_feder')->where('detalle_compra_id', self::DETALLE)->first();
        DB::update("UPDATE lote_feder SET estado = 'BLOQUEADO' WHERE id = ?", [$lote->id]);

        $this->recibir(self::COMPRA, [['detalle_id' => self::DETALLE, 'recibida' => 90, 'rechazada' => 10, 'cancelada' => 0]])->assertOk();

        $despues = DB::table('lote_feder')->where('id', $lote->id)->first();
        $this->assertSame('BLOQUEADO', $despues->estado, 'La recepción adicional devolvió a la venta un lote bloqueado.');
        $this->assertEquals((float) $lote->cantidad_disponible + 10, (float) $despues->cantidad_disponible);
    }

    public function test_una_recepcion_adicional_no_revive_un_lote_vencido(): void
    {
        $lote = DB::table('lote_feder')->where('detalle_compra_id', self::DETALLE)->first();
        DB::update("UPDATE lote_feder SET estado = 'VENCIDO' WHERE id = ?", [$lote->id]);

        $this->recibir(self::COMPRA, [['detalle_id' => self::DETALLE, 'recibida' => 85, 'rechazada' => 10, 'cancelada' => 0]])->assertOk();

        $this->assertSame('VENCIDO', DB::table('lote_feder')->where('id', $lote->id)->value('estado'));
    }

    public function test_un_lote_agotado_vuelve_a_estar_disponible_al_recibir_mas(): void
    {
        // Compra 5 (detalle 10): primera recepción de 10 y el lote se vende entero (saldo 0, AGOTADO).
        $this->recibir(5, [['detalle_id' => 10, 'recibida' => 10, 'rechazada' => 0, 'cancelada' => 0]])->assertOk();
        $lote = DB::table('lote_feder')->where('detalle_compra_id', 10)->first();
        DB::update("UPDATE lote_feder SET cantidad_disponible = 0, estado = 'AGOTADO' WHERE id = ?", [$lote->id]);

        $this->recibir(5, [['detalle_id' => 10, 'recibida' => 18, 'rechazada' => 0, 'cancelada' => 0]])->assertOk();

        $despues = DB::table('lote_feder')->where('id', $lote->id)->first();
        $this->assertSame('DISPONIBLE', $despues->estado);
        $this->assertEquals(8, (float) $despues->cantidad_disponible);
    }

    public function test_no_se_acepta_mercaderia_de_un_lote_de_origen_ya_vencido(): void
    {
        $loteOrigen = (int) DB::table('detalle_compra')->where('id', self::DETALLE)->value('lote_origen_id');
        DB::table('lote_produccion')->where('id', $loteOrigen)->update(['fecha_vencimiento' => now()->subDay()->toDateString()]);
        $antes = (array) DB::table('detalle_compra')->where('id', self::DETALLE)->first();

        $respuesta = $this->recibir(self::COMPRA, [['detalle_id' => self::DETALLE, 'recibida' => 90, 'rechazada' => 10, 'cancelada' => 0]]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['lineas']);
        $this->assertStringContainsString('venció', $respuesta->json('errors.lineas.0'));
        $this->assertSame($antes, (array) DB::table('detalle_compra')->where('id', self::DETALLE)->first());

        // Lo vencido se registra como rechazado: eso sí se admite.
        $this->recibir(self::COMPRA, [['detalle_id' => self::DETALLE, 'recibida' => 80, 'rechazada' => 40, 'cancelada' => 0]])->assertOk();
    }

    public function test_la_recepcion_deja_una_fila_por_detalle_con_la_clave_del_movimiento(): void
    {
        $personalId = $this->usuarioId(self::PERSONAL);

        $this->recibir(self::COMPRA, [['detalle_id' => self::DETALLE, 'recibida' => 95, 'rechazada' => 12, 'cancelada' => 0]])->assertOk();

        $fila = $this->ultimaBitacora('DETALLE_COMPRA', self::DETALLE);
        $this->assertNotNull($fila, 'La recepción no dejó fila DETALLE_COMPRA en la bitácora.');
        $this->assertSame('MODIFICAR', $fila->accion);
        $this->assertSame($personalId, (int) $fila->usuario_id);
        $this->assertSame('RECEPCION', $fila->detalle['operacion']);
        $this->assertSame(self::COMPRA, (int) $fila->detalle['compra_id']);
        $this->assertEquals(15, $fila->detalle['cantidad_aceptada']);
        $this->assertEquals(2, $fila->detalle['cantidad_rechazada']);
        $this->assertSame('number', DB::table('bitacora')->where('id', $fila->id)->selectRaw("jsonb_typeof(detalle -> 'cantidad_aceptada') AS tipo")->value('tipo'));

        $movimiento = DB::table('movimiento_inventario')->where('tipo', 'ENTRADA_COMPRA')->orderByDesc('id')->first();
        $this->assertEquals(15, (float) $movimiento->cantidad);
        $this->assertSame($movimiento->clave_operacion, $fila->detalle['clave_operacion']);
        $this->assertSame((int) $movimiento->lote_feder_id, (int) $fila->detalle['lote_feder_id']);

        // La fila de la compra se conserva.
        $compra = $this->ultimaBitacora('COMPRA_FEDER', self::COMPRA);
        $this->assertSame('RECEPCION', $compra->detalle['operacion']);
    }

    public function test_primera_recepcion_con_aceptado_rechazado_y_cancelado(): void
    {
        // Compra 5 (REGISTRADA): detalle 10, 30 unidades del lote de origen 11.
        $this->recibir(5, [['detalle_id' => 10, 'recibida' => 20, 'rechazada' => 6, 'cancelada' => 4]])->assertOk();

        $filas = DB::table('bitacora')->where('entidad', 'DETALLE_COMPRA')->where('entidad_id', 10)->orderBy('id')->get()
            ->map(fn ($f) => ['accion' => $f->accion] + json_decode((string) $f->detalle, true));
        $this->assertCount(2, $filas);

        $recepcion = $filas->firstWhere('operacion', 'RECEPCION');
        $this->assertSame('MODIFICAR', $recepcion['accion']);
        $this->assertEquals(20, $recepcion['cantidad_aceptada']);
        $this->assertEquals(6, $recepcion['cantidad_rechazada']);
        $loteFeder = DB::table('lote_feder')->where('detalle_compra_id', 10)->first();
        $this->assertSame('DISPONIBLE', $loteFeder->estado);
        $this->assertSame(
            DB::table('movimiento_inventario')->where('lote_feder_id', $loteFeder->id)->where('tipo', 'ENTRADA_COMPRA')->value('clave_operacion'),
            $recepcion['clave_operacion']
        );

        $cancelacion = $filas->firstWhere('operacion', 'CANCELACION');
        $this->assertSame('ANULAR', $cancelacion['accion']);
        $this->assertEquals(4, $cancelacion['cantidad_cancelada']);
        $this->assertNotSame($recepcion['clave_operacion'], $cancelacion['clave_operacion']);
        $this->assertSame('RECIBIDA', DB::table('compra_feder')->where('id', 5)->value('estado'));
    }

    public function test_un_rechazo_sin_aceptacion_tambien_queda_registrado_y_no_mueve_inventario(): void
    {
        $movimientos = DB::table('movimiento_inventario')->count();

        $this->recibir(self::COMPRA, [['detalle_id' => self::DETALLE, 'recibida' => 80, 'rechazada' => 25, 'cancelada' => 0]])->assertOk();

        $fila = $this->ultimaBitacora('DETALLE_COMPRA', self::DETALLE);
        $this->assertSame('RECEPCION', $fila->detalle['operacion']);
        $this->assertEquals(0, $fila->detalle['cantidad_aceptada']);
        $this->assertEquals(15, $fila->detalle['cantidad_rechazada']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fila->detalle['clave_operacion']);
        $this->assertSame($movimientos, DB::table('movimiento_inventario')->count());
    }

    public function test_repetir_la_misma_recepcion_no_duplica_movimientos_ni_filas_de_detalle(): void
    {
        $lineas = [['detalle_id' => self::DETALLE, 'recibida' => 90, 'rechazada' => 10, 'cancelada' => 0]];
        $this->recibir(self::COMPRA, $lineas)->assertOk();
        $movimientos = DB::table('movimiento_inventario')->count();
        $filas = DB::table('bitacora')->where('entidad', 'DETALLE_COMPRA')->count();

        $this->recibir(self::COMPRA, $lineas)->assertOk();

        $this->assertSame($movimientos, DB::table('movimiento_inventario')->count());
        $this->assertSame($filas, DB::table('bitacora')->where('entidad', 'DETALLE_COMPRA')->count());
    }

    private function recibir(int $compraId, array $lineas)
    {
        return $this->api('POST', "/api/gestion/compras/{$compraId}/recibir", ['lineas' => $lineas], $this->tokenDe(self::PERSONAL));
    }
}
