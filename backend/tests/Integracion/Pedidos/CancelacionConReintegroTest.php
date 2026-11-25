<?php

namespace Tests\Integracion\Pedidos;

use Illuminate\Support\Facades\DB;

/**
 * H-05: el personal cancela un pedido pagado ANTES del despacho y el sistema reintegra lo cobrado con un
 * reembolso sin devolución (reglas R031 y R052). El cliente sigue cancelando solo pedidos PENDIENTE sin pagos.
 */
class CancelacionConReintegroTest extends PedidosTestCase
{
    public function test_el_personal_cancela_un_pedido_pagado_y_se_reintegra_sin_devolucion(): void
    {
        $producto = $this->crearProducto(['precio_venta' => '45.00']);
        $lote = $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$producto => 4]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION');
        $this->assertSame('4.000', $this->lote($lote)->cantidad_reservada);

        $respuesta = $this->cancelarComoPersonal($this->personal(), $pedido, 'El cliente llamó para cancelar');

        $respuesta->assertOk()->assertJsonPath('estado', 'CANCELADO');
        $this->assertSame('CANCELADO', $this->pedido($pedido)->estado);
        $this->assertSame('CANCELADA', $this->entrega($pedido)->estado);
        $this->assertSame('LIBERADA', $this->reservas($pedido)[0]->estado);
        $this->assertSame(['10.000', '0.000'], [$this->lote($lote)->cantidad_disponible, $this->lote($lote)->cantidad_reservada]);
        $this->assertCount(0, $this->salidas($pedido), 'Cancelar antes del despacho no mueve inventario físico.');

        $reembolsos = $this->reembolsos($pedido);
        $this->assertCount(1, $reembolsos);
        $pago = $this->pagos($pedido)[0];
        $this->assertNull($reembolsos[0]->devolucion_id);
        $this->assertSame((int) $pago->id, (int) $reembolsos[0]->pago_id);
        $this->assertSame('180.00', $reembolsos[0]->monto);
        $this->assertSame('EJECUTADO', $reembolsos[0]->estado);
        $this->assertNotNull($reembolsos[0]->fecha_ejecucion);
        $this->assertStringContainsString($this->pedido($pedido)->codigo, $reembolsos[0]->motivo);
        $this->assertSame('PAGADO', $pago->estado, 'El pago conserva su registro; el dinero vuelve con el reembolso.');

        $ultimo = $this->historial($pedido)->last();
        $this->assertSame(['EN_PREPARACION', 'CANCELADO', $this->idDe(self::PERSONAL)], [$ultimo->estado_anterior, $ultimo->estado_nuevo, (int) $ultimo->usuario_id]);
        $this->assertStringContainsString('El cliente llamó para cancelar', $ultimo->comentario);
        $this->assertSame(1, DB::table('notificacion')->where('pedido_id', $pedido)->where('tipo', 'REEMBOLSO_EJECUTADO')->count());

        $detalle = $this->withToken($this->cliente())->getJson("/api/mis-pedidos/{$pedido}")->assertOk();
        $detalle->assertJsonPath('estado', 'CANCELADO');
    }

    public function test_cancela_un_encargo_pagado_que_espera_abastecimiento(): void
    {
        $producto = $this->crearProducto(['permite_encargo' => true, 'precio_venta' => '90.00']);
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 35], ['tipo' => 'ENCARGO']);
        $this->pagarConTarjeta(self::CLIENTE, $pedido)->assertOk();
        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido($pedido)->estado);

        $this->cancelarComoPersonal($this->personal(), $pedido, 'No se consiguió el producto')->assertOk();

        $this->assertSame('CANCELADO', $this->pedido($pedido)->estado);
        $this->assertSame(['3150.00'], $this->reembolsos($pedido)->pluck('monto')->all());
        $this->assertSame('CANCELADA', $this->entrega($pedido)->estado);
    }

    public function test_cancela_un_pedido_con_plan_y_una_cuota_pagada_cierra_el_plan_y_reintegra_la_cuota(): void
    {
        $producto = $this->crearProducto(['precio_venta' => '70.00']);
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 3], ['forma_pago' => 'CUOTAS', 'num_cuotas' => 3]);
        $this->pagarConTarjeta(self::CLIENTE, $pedido)->assertOk();

        $this->cancelarComoPersonal($this->personal(), $pedido)->assertOk();

        $this->assertSame('CANCELADO', $this->plan($pedido)['estado']);
        $this->assertNotNull(DB::table('confirmacion_operacion')->where('comando', 'PLAN_PAGO')
            ->whereRaw("(parametros->>'pedido_id')::bigint = ?", [$pedido])->value('usada_en'), 'El plan queda dado de baja.');
        $this->assertSame(['70.00'], $this->reembolsos($pedido)->pluck('monto')->all());
        $this->withToken($this->cliente())->getJson("/api/mis-pedidos/{$pedido}")->assertOk()->assertJsonPath('puede_pagar', false);
    }

    public function test_cancela_un_pedido_con_efectivo_pendiente_sin_reintegro(): void
    {
        $producto = $this->crearProducto();
        $lote = $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 2]);
        $this->pagarEnEfectivo(self::CLIENTE, $pedido)->assertOk();

        $this->cancelarComoPersonal($this->personal(), $pedido)->assertOk();

        $this->assertSame('RECHAZADO', $this->pagos($pedido)[0]->estado, 'El efectivo que no se cobró termina sin cobro.');
        $this->assertCount(0, $this->reembolsos($pedido));
        $this->assertSame('0.000', $this->lote($lote)->cantidad_reservada);
    }

    // ------------------------------------------------------------------ R053: pago de pasarela sin conciliar

    public function test_no_cancela_mientras_un_qr_pendiente_no_se_concilia(): void
    {
        $producto = $this->crearProducto();
        $lote = $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 2]);
        $this->withToken($this->cliente())->postJson("/api/mis-pedidos/{$pedido}/pagar", ['metodo' => 'QR'])->assertOk();
        $qr = $this->pagos($pedido)[0];
        $this->withToken($this->personal())->getJson("/api/gestion/pedidos/{$pedido}")->assertOk()
            ->assertJsonPath('acciones.pago_por_conciliar.id', (int) $qr->id)
            ->assertJsonPath('acciones.pago_por_conciliar.metodo', 'QR');
        $antes = $this->fotoInventario($pedido);

        $respuesta = $this->cancelarComoPersonal($this->personal(), $pedido, 'El cliente ya no lo quiere');

        $respuesta->assertStatus(409);
        $this->assertStringContainsString('QR', $respuesta->json('message'));
        $this->assertStringContainsString('confírmalo', $respuesta->json('message'));
        $this->assertSame($antes, $this->fotoInventario($pedido), 'El pago no se rechaza en silencio y nada cambia.');
        $this->assertSame('PENDIENTE', DB::table('pago')->where('id', $qr->id)->value('estado'));
        $this->assertSame('2.000', $this->lote($lote)->cantidad_reservada);

        // Con el resultado conocido (rechazado por una persona y con motivo) ya se puede cancelar.
        $this->withToken($this->personal())->postJson("/api/gestion/pagos/{$qr->id}/rechazar", ['motivo' => 'El banco no registra el cobro'])->assertOk();
        $this->cancelarComoPersonal($this->personal(), $pedido, 'El cliente ya no lo quiere')->assertOk();

        $this->assertSame('CANCELADO', $this->pedido($pedido)->estado);
        $this->assertSame('0.000', $this->lote($lote)->cantidad_reservada);
        $this->assertCount(0, $this->reembolsos($pedido));
    }

    public function test_si_el_qr_se_confirma_la_cancelacion_reintegra_lo_cobrado(): void
    {
        $producto = $this->crearProducto(['precio_venta' => '40.00']);
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 2]);
        $this->withToken($this->cliente())->postJson("/api/mis-pedidos/{$pedido}/pagar", ['metodo' => 'QR'])->assertOk();
        $qr = $this->pagos($pedido)[0];

        $this->withToken($this->personal())->postJson("/api/gestion/pagos/{$qr->id}/confirmar")->assertOk();
        $this->cancelarComoPersonal($this->personal(), $pedido, 'Sin stock para entregar')->assertOk();

        $this->assertSame('PAGADO', DB::table('pago')->where('id', $qr->id)->value('estado'));
        $this->assertSame(['80.00'], $this->reembolsos($pedido)->pluck('monto')->all());
    }

    public function test_una_tarjeta_pendiente_tambien_impide_cancelar_sin_conciliar(): void
    {
        $producto = $this->crearProducto(['permite_encargo' => true]);
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 3], ['tipo' => 'ENCARGO']);
        DB::table('pago')->insert(['pedido_id' => $pedido, 'metodo' => 'TARJETA', 'monto' => $this->pedido($pedido)->total, 'estado' => 'PENDIENTE',
            'referencia_externa' => 'TRJ-PRUEBA-'.$pedido, 'clave_idempotencia' => hash('sha256', 'PRUEBA|TRJ|'.$pedido)]);

        $this->cancelarComoPersonal($this->personal(), $pedido)->assertStatus(409);

        $this->assertSame('PENDIENTE', $this->pedido($pedido)->estado);
        $this->assertSame(['PENDIENTE'], $this->pagos($pedido)->pluck('estado')->all());
    }

    public function test_no_se_cancela_un_pedido_despachado_ni_entregado(): void
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$producto => 2]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION', 'DESPACHADO');
        $antes = $this->fotoInventario($pedido);

        $this->cancelarComoPersonal($this->personal(), $pedido)->assertStatus(422);
        $this->assertSame($antes, $this->fotoInventario($pedido));

        $this->avanzarHasta($pedido, 'ENTREGADO');
        $this->cancelarComoPersonal($this->personal(), $pedido)->assertStatus(422);
        $this->assertCount(0, $this->reembolsos($pedido));
    }

    public function test_repetir_la_cancelacion_no_duplica_el_reintegro(): void
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$producto => 2]);

        $this->cancelarComoPersonal($this->personal(), $pedido)->assertOk();
        $this->cancelarComoPersonal($this->admin(), $pedido)->assertStatus(422);

        $this->assertCount(1, $this->reembolsos($pedido));
        $this->assertSame(1, $this->historial($pedido)->where('estado_nuevo', 'CANCELADO')->count());
    }

    public function test_el_esquema_solo_admite_el_reintegro_sin_devolucion_con_el_pedido_ya_cancelado(): void
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$producto => 2]);
        $pago = $this->pagos($pedido)[0];

        // Regla del esquema aprobado (rg_reembolso_sin_devolucion_pedido_cancelado): por eso el reintegro se inserta tras cancelar.
        $this->expectExceptionMessageMatches('/reintegro sin devoluci/u');
        DB::transaction(fn () => DB::table('reembolso')->insert(['pago_id' => $pago->id, 'devolucion_id' => null, 'monto' => '10.00',
            'estado' => 'PENDIENTE', 'motivo' => 'Intento con el pedido sin cancelar', 'clave_idempotencia' => hash('sha256', 'PRUEBA|'.$pago->id)]));
    }

    public function test_el_cliente_solo_cancela_un_pedido_pendiente_sin_pagos(): void
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pagado = $this->pedidoPagado(self::CLIENTE, [$producto => 2]);
        $pendiente = $this->pedidoDe(self::OTRO_CLIENTE, [$producto => 1]);

        $this->withToken($this->cliente())->postJson("/api/mis-pedidos/{$pagado}/cancelar", ['motivo' => 'Ya no lo quiero'])->assertStatus(422);
        $this->assertSame('CONFIRMADO', $this->pedido($pagado)->estado);
        $this->assertCount(0, $this->reembolsos($pagado));

        $this->withToken($this->cliente(self::OTRO_CLIENTE))->postJson("/api/mis-pedidos/{$pendiente}/cancelar", ['motivo' => 'Ya no lo quiero'])->assertOk();
        $this->assertSame('CANCELADO', $this->pedido($pendiente)->estado);
    }

    public function test_el_personal_no_registra_el_reintegro_de_su_propio_pedido(): void
    {
        $producto = $this->crearProducto();
        $lote = $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::PERSONAL_Y_CLIENTE, [$producto => 2]);
        $antes = $this->fotoInventario($pedido);

        $propio = $this->cancelarComoPersonal($this->tokenDe(self::PERSONAL_Y_CLIENTE, 'PERSONAL_FEDER'), $pedido);

        $propio->assertStatus(403);
        $this->assertStringContainsString('tu propio pedido', $propio->json('message'));
        $this->assertSame($antes, $this->fotoInventario($pedido), 'El rechazo no deja cambios.');
        $this->assertSame('2.000', $this->lote($lote)->cantidad_reservada);

        // Otro miembro del personal sí puede.
        $this->cancelarComoPersonal($this->personal(), $pedido)->assertOk();
        $this->assertCount(1, $this->reembolsos($pedido));
    }

    public function test_el_personal_si_cancela_su_propio_pedido_cuando_no_hay_dinero_que_reintegrar(): void
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoDe(self::PERSONAL_Y_CLIENTE, [$producto => 2]);

        $this->cancelarComoPersonal($this->tokenDe(self::PERSONAL_Y_CLIENTE, 'PERSONAL_FEDER'), $pedido)->assertOk();

        $this->assertSame('CANCELADO', $this->pedido($pedido)->estado);
        $this->assertCount(0, $this->reembolsos($pedido));
    }
}
