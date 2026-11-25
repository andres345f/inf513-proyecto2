<?php

namespace Tests\Integracion\Pedidos;

use App\Services\GestionPedidoService;
use Illuminate\Support\Facades\DB;

/**
 * H-22 (parte de pedidos y entregas): avanzar, abastecer, cancelar, cobrar el efectivo al entregar y los
 * vencimientos automáticos quedan en la bitácora con una acción nativa del esquema, su autor y la IP.
 */
class BitacoraPedidosTest extends PedidosTestCase
{
    private const ACCIONES_DEL_ESQUEMA = ['CREAR', 'MODIFICAR', 'ANULAR', 'ASIGNAR_ROL', 'REVOCAR_ROL', 'INICIO_SESION',
        'CAMBIO_PRECIO', 'APROBAR_SOLICITUD', 'RECHAZAR_SOLICITUD', 'INVITAR'];

    public function test_preparar_despachar_y_entregar_dejan_bitacora_de_pedido_y_de_entrega(): void
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$producto => 2]);
        $entregaId = (int) $this->entrega($pedido)->id;

        $this->avanzarHasta($pedido, 'EN_PREPARACION', 'DESPACHADO', 'ENTREGADO');

        $filas = $this->bitacora('PEDIDO', $pedido);
        $this->assertSame('CREAR_PEDIDO', $filas->shift()->detalle['operacion']);
        $this->assertSame(['PREPARAR', 'DESPACHAR', 'ENTREGAR'], $filas->pluck('detalle.operacion')->all());
        foreach ($filas as $fila) {
            $this->assertSame('MODIFICAR', $fila->accion);
            $this->assertSame($this->idDe(self::PERSONAL), (int) $fila->usuario_id);
            $this->assertSame('WEB', $fila->detalle['canal']);
            $this->assertNotNull($fila->direccion_ip);
        }
        $this->assertSame(['CONFIRMADO', 'EN_PREPARACION'], [$filas[0]->detalle['estado_anterior'], $filas[0]->detalle['estado_nuevo']]);
        $this->assertSame(1, $filas[1]->detalle['salidas'], 'El despacho indica cuántas salidas de inventario registró.');

        $entregas = $this->bitacora('ENTREGA', $entregaId);
        $this->assertSame([['PENDIENTE', 'LISTA_PARA_RECOJO'], ['LISTA_PARA_RECOJO', 'ENTREGADA']],
            $entregas->map(fn ($f) => [$f->detalle['estado_anterior'], $f->detalle['estado_nuevo']])->all());
    }

    public function test_abastecer_deja_bitacora(): void
    {
        $producto = $this->crearProducto(['permite_encargo' => true]);
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 3], ['tipo' => 'ENCARGO']);
        $this->pagarConTarjeta(self::CLIENTE, $pedido)->assertOk();
        $this->crearLoteFeder($producto, 5, null, '2026-11-01 09:00:00');

        $this->abastecer($this->admin(), $pedido)->assertOk();

        $fila = $this->bitacora('PEDIDO', $pedido)->last();
        $this->assertSame(['MODIFICAR', 'ABASTECER', $this->idDe(self::ADMIN)], [$fila->accion, $fila->detalle['operacion'], (int) $fila->usuario_id]);
        $this->assertSame(['ESPERANDO_ABASTECIMIENTO', 'CONFIRMADO'], [$fila->detalle['estado_anterior'], $fila->detalle['estado_nuevo']]);
        $this->assertEquals(3, $fila->detalle['cantidad_reservada']);
    }

    public function test_cancelar_con_reintegro_deja_bitacora_de_pedido_entrega_y_reembolso(): void
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$producto => 2]);

        $this->cancelarComoPersonal($this->personal(), $pedido, 'Producto dañado en almacén')->assertOk();

        $fila = $this->bitacora('PEDIDO', $pedido)->last();
        $this->assertSame(['ANULAR', 'CANCELAR', 'CANCELADO'], [$fila->accion, $fila->detalle['operacion'], $fila->detalle['estado_nuevo']]);
        $this->assertSame('Producto dañado en almacén', $fila->detalle['motivo']);
        $this->assertSame($this->idDe(self::PERSONAL), (int) $fila->usuario_id);

        $entrega = $this->bitacora('ENTREGA', (int) $this->entrega($pedido)->id)->last();
        $this->assertSame('CANCELADA', $entrega->detalle['estado_nuevo']);

        $reembolso = $this->reembolsos($pedido)[0];
        $registro = $this->bitacora('REEMBOLSO', (int) $reembolso->id)->last();
        $this->assertSame('CREAR', $registro->accion);
        $this->assertEquals(100, $registro->detalle['monto']);
        $this->assertSame((int) $reembolso->pago_id, $registro->detalle['pago_id']);
    }

    public function test_el_cobro_de_efectivo_al_entregar_deja_bitacora_del_pago(): void
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 2]);
        $this->pagarEnEfectivo(self::CLIENTE, $pedido)->assertOk();
        $this->avanzarHasta($pedido, 'EN_PREPARACION', 'DESPACHADO');

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'ENTREGADO', 'efectivo_confirmado' => true])->assertOk();

        $fila = $this->bitacora('PAGO', (int) $this->pagos($pedido)[0]->id)->last();
        $this->assertSame(['MODIFICAR', 'COBRAR_EFECTIVO', 'PAGADO'], [$fila->accion, $fila->detalle['operacion'], $fila->detalle['estado_nuevo']]);
        $this->assertSame($this->idDe(self::PERSONAL), (int) $fila->usuario_id);
        $this->assertEquals(100, $fila->detalle['monto']);

        $facturaId = (int) DB::table('factura')->where('pedido_id', $pedido)->value('id');
        $registro = $this->bitacora('FACTURA', $facturaId)->sole();
        $this->assertSame($this->idDe(self::PERSONAL), (int) $registro->usuario_id);
        $this->assertNotNull($registro->direccion_ip);
    }

    public function test_el_vencimiento_automatico_deja_bitacora_sin_usuario(): void
    {
        $producto = $this->crearProducto(['permite_encargo' => true]);
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 3], ['tipo' => 'ENCARGO']);
        DB::update('UPDATE pedido SET fecha_creacion = ? WHERE id = ?', [now()->subHours(50)->toDateTimeString(), $pedido]);

        app(GestionPedidoService::class)->vencerPedidosSinPago();

        $fila = $this->bitacora('PEDIDO', $pedido)->last();
        $this->assertSame(['ANULAR', 'VENCER_PLAZO_DE_PAGO'], [$fila->accion, $fila->detalle['operacion']]);
        $this->assertNull($fila->usuario_id);
        $this->assertSame('TAREA_PROGRAMADA', $fila->detalle['origen']);
    }

    public function test_un_avance_rechazado_no_deja_bitacora(): void
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$producto => 2]);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'DESPACHADO'])->assertStatus(409);

        $this->assertSame(['CREAR_PEDIDO'], $this->bitacora('PEDIDO', $pedido)->pluck('detalle.operacion')->all());
    }

    public function test_todas_las_acciones_registradas_son_nativas_del_esquema(): void
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$producto => 2]);
        $desde = (int) DB::table('bitacora')->max('id');
        $this->avanzarHasta($pedido, 'EN_PREPARACION');
        $this->cancelarComoPersonal($this->personal(), $pedido)->assertOk();

        $acciones = DB::table('bitacora')->where('id', '>', $desde)->pluck('accion')->unique()->values()->all();

        $this->assertNotEmpty($acciones);
        $this->assertSame([], array_values(array_diff($acciones, self::ACCIONES_DEL_ESQUEMA)));
    }
}
