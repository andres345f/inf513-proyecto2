<?php

namespace Tests\Integracion\Pedidos;

use App\Services\AbastecimientoService;
use Illuminate\Support\Facades\DB;

class AbastecimientoAutomaticoTest extends PedidosTestCase
{
    private function esperar(array $lineas): int
    {
        $id = $this->pedidoDe(self::CLIENTE, $lineas, ['tipo' => 'ENCARGO']);
        $this->pagarEnEfectivo(self::CLIENTE, $id)->assertOk();

        return $id;
    }

    private function ingresar(int $producto, int $cantidad): int
    {
        return $this->crearLoteFeder($producto, $cantidad, null, now()->toDateTimeString());
    }

    public function test_prioridad_por_fecha_e_id_salta_multilinea_incompleta_sin_dejar_reserva_parcial(): void
    {
        $a = $this->crearProducto(['permite_encargo' => true]);
        $b = $this->crearProducto(['permite_encargo' => true]);
        $incompleto = $this->esperar([$a => 1, $b => 1]);
        $reciente = $this->esperar([$a => 1]);
        $anterior = $this->esperar([$a => 1]);
        $empate = $this->esperar([$a => 1]);
        $ayer = now()->subDay();
        DB::table('pedido')->where('id', $incompleto)->update(['fecha_creacion' => $ayer->copy()->subMinute()]);
        DB::table('pedido')->whereIn('id', [$anterior, $empate])->update(['fecha_creacion' => $ayer]);
        $lote = $this->ingresar($a, 1);

        // Incluso el control manual del más reciente respeta a los dos anteriores con existencias.
        $this->abastecer($this->personal(), $reciente)->assertStatus(409);
        $this->assertSame('CONFIRMADO', $this->pedido($anterior)->estado);
        foreach ([$incompleto, $empate, $reciente] as $id) {
            $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido($id)->estado);
            $this->assertCount(0, $this->reservas($id));
        }
        $this->assertSame('1.000', $this->lote($lote)->cantidad_disponible);
        $this->assertSame('1.000', $this->lote($lote)->cantidad_reservada);
        $this->assertCount(0, $this->salidas($anterior));
        $this->assertNull($this->historial($anterior)->firstWhere('estado_nuevo', 'CONFIRMADO')->usuario_id);
        $auditoria = $this->bitacora('PEDIDO', $anterior)->first(fn ($fila) => ($fila->detalle['operacion'] ?? '') === 'ABASTECER');
        $this->assertSame('AUTOMATICO', $auditoria->detalle['origen']);
        $this->assertNull($auditoria->usuario_id);
    }

    public function test_reintento_periodico_recupera_lote_habilitado_y_aviso_pendiente_una_sola_vez(): void
    {
        $p = $this->crearProducto(['permite_encargo' => true]);
        $id = $this->esperar([$p => 2]);
        $bloqueado = $this->ingresar($p, 2);
        DB::table('lote_feder')->where('id', $bloqueado)->update(['estado' => 'BLOQUEADO']);
        $vencido = $this->ingresar($p, 2);
        $this->fijarVencimiento($vencido, $this->ayer());
        $this->artisan('feder:abastecer-encargos')->assertSuccessful();
        $this->assertCount(0, $this->reservas($id));
        DB::table('lote_feder')->where('id', $bloqueado)->update(['estado' => 'DISPONIBLE']);
        $this->artisan('feder:abastecer-encargos')->assertSuccessful();
        $this->assertSame('CONFIRMADO', $this->pedido($id)->estado);
        $this->assertSame($bloqueado, (int) $this->reservas($id)->sole()->lote_feder_id);
        $this->assertSame('0.000', $this->lote($vencido)->cantidad_reservada);

        $aviso = DB::table('notificacion')->where('pedido_id', $id)->where('tipo', 'ENCARGO_ABASTECIDO');
        $this->assertSame(1, $aviso->count());
        $aviso->update(['estado' => 'PENDIENTE', 'fecha_envio' => null]); // Interrupción entre commit y publicación.
        $this->artisan('feder:abastecer-encargos')->assertSuccessful();
        $this->assertSame('ENVIADA', $aviso->sole()->estado);
        $this->assertNotNull($aviso->sole()->fecha_envio);
        $this->assertSame(1, $this->historial($id)->where('estado_nuevo', 'CONFIRMADO')->count());
        $this->assertCount(1, $this->reservas($id));
    }

    public function test_pago_de_primera_cuota_despierta_encargo_con_stock_y_no_cobra_el_saldo(): void
    {
        $p = $this->crearProducto(['permite_encargo' => true, 'precio_venta' => '100.00']);
        $id = $this->pedidoDe(self::CLIENTE, [$p => 3], ['tipo' => 'ENCARGO', 'forma_pago' => 'CUOTAS', 'num_cuotas' => 3]);
        $this->ingresar($p, 3);
        app(AbastecimientoService::class)->revisar();
        $this->assertCount(0, $this->reservas($id));
        $this->pagarConTarjeta(self::CLIENTE, $id)->assertOk();
        $this->assertSame('CONFIRMADO', $this->pedido($id)->estado);
        $this->assertSame('3.000', $this->reservas($id)->sole()->cantidad);
        $this->assertSame('100.00', $this->pagos($id)->sole()->monto);
        $this->assertSame(['PAGADA', 'PENDIENTE', 'PENDIENTE'], array_column($this->plan($id)['cuotas'], 'estado'));
    }

    public function test_liberacion_confirmada_reasigna_saldo_y_un_rollback_no_dispara_el_evento(): void
    {
        $p = $this->crearProducto(['permite_encargo' => true]);
        $this->ingresar($p, 2);
        $inmediato = $this->pedidoDe(self::CLIENTE, [$p => 2]);
        $espera = $this->esperar([$p => 2]);
        DB::beginTransaction();
        app(\App\Repositories\InventarioRepository::class)->liberarReservas($inmediato);
        $this->assertCount(0, $this->reservas($espera));
        DB::rollBack();
        $this->assertSame('ACTIVA', $this->reservas($inmediato)->sole()->estado);
        $this->cancelarComoPersonal($this->personal(), $inmediato)->assertOk();
        $this->assertSame('CONFIRMADO', $this->pedido($espera)->estado);
        $this->assertSame('2.000', $this->reservas($espera)->sole()->cantidad);
    }

    public function test_reingreso_apto_en_lote_agotado_despierta_el_encargo_despues_de_la_aprobacion(): void
    {
        $p = $this->crearProducto(['permite_encargo' => true]);
        $lote = $this->ingresar($p, 1);
        $venta = $this->pedidoPagado(self::CLIENTE, [$p => 1]);
        $this->avanzarHasta($venta, 'EN_PREPARACION', 'DESPACHADO', 'ENTREGADO');
        $espera = $this->esperar([$p => 1]);
        $this->assertSame('AGOTADO', $this->lote($lote)->estado);
        $despacho = (int) $this->salidas($venta)->sole()->id;
        $this->withToken($this->cliente())->postJson('/api/mis-devoluciones', [
            'despacho_id' => $despacho, 'cantidad' => 1, 'motivo' => 'Devolución de prueba',
        ])->assertCreated();
        $devolucion = DB::table('devolucion')->where('movimiento_inventario_id', $despacho)->sole()->id;
        $this->withToken($this->personal())->postJson("/api/gestion/devoluciones/{$devolucion}/aprobar", [
            'resolucion' => 'REEMBOLSO', 'cantidad_recibida' => 1, 'cantidad_apta' => 1, 'cantidad_aprobada' => 1,
        ])->assertOk();
        $this->assertSame('CONFIRMADO', $this->pedido($espera)->estado);
        $this->assertSame('DISPONIBLE', $this->lote($lote)->estado);
        $this->assertSame('1.000', $this->lote($lote)->cantidad_reservada);
        $this->assertSame($lote, (int) $this->reservas($espera)->sole()->lote_feder_id);
        $this->assertSame(1, DB::table('movimiento_inventario')->where('devolucion_id', $devolucion)
            ->where('tipo', 'ENTRADA_DEVOLUCION')->count());
        $this->assertCount(0, $this->salidas($espera));
    }
}
