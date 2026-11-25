<?php

namespace Tests\Integracion\Pedidos;

use App\Services\GestionPedidoService;
use App\Services\PedidoService;
use Illuminate\Support\Facades\DB;

/**
 * H-20 (parte de encargos): «Pedido sin pagar en 48 h → libera reserva → CANCELADO» (PROMPT 7.2) también
 * se aplica a los ENCARGO pendientes, que no tienen reservas y antes no vencían nunca.
 */
class VencimientoPedidosTest extends PedidosTestCase
{
    private function vencer(): mixed
    {
        return app(GestionPedidoService::class)->vencerPedidosSinPago();
    }

    private function envejecer(int $pedidoId, int $horas): void
    {
        DB::update('UPDATE pedido SET fecha_creacion = ? WHERE id = ?', [now()->subHours($horas)->toDateTimeString(), $pedidoId]);
        DB::update('UPDATE reserva_stock SET fecha_expiracion = ? WHERE detalle_pedido_id IN (SELECT id FROM detalle_pedido WHERE pedido_id = ?)',
            [now()->subHours($horas)->addHours(PedidoService::HORAS_PARA_PAGAR)->toDateTimeString(), $pedidoId]);
    }

    public function test_un_encargo_pendiente_sin_pago_vence_a_las_48_horas(): void
    {
        $producto = $this->crearProducto(['permite_encargo' => true]);
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 3], ['tipo' => 'ENCARGO']);
        $this->envejecer($pedido, 49);

        $this->vencer();

        $this->assertSame('CANCELADO', $this->pedido($pedido)->estado);
        $this->assertSame('CANCELADA', $this->entrega($pedido)->estado);
        $ultimo = $this->historial($pedido)->last();
        $this->assertSame(['PENDIENTE', 'CANCELADO'], [$ultimo->estado_anterior, $ultimo->estado_nuevo]);
        $this->assertNull($ultimo->usuario_id, 'El vencimiento automático no tiene responsable.');
        $this->assertStringContainsString('48', $ultimo->comentario);
        $this->assertSame(1, DB::table('notificacion')->where('pedido_id', $pedido)->where('titulo', 'like', '%cancelado%')->count());
    }

    public function test_un_encargo_pendiente_reciente_no_vence(): void
    {
        $producto = $this->crearProducto(['permite_encargo' => true]);
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 3], ['tipo' => 'ENCARGO']);
        $this->envejecer($pedido, 47);

        $this->vencer();

        $this->assertSame('PENDIENTE', $this->pedido($pedido)->estado);
    }

    public function test_un_encargo_ya_pagado_o_con_efectivo_no_vence(): void
    {
        $producto = $this->crearProducto(['permite_encargo' => true]);
        $pagado = $this->pedidoDe(self::CLIENTE, [$producto => 3], ['tipo' => 'ENCARGO']);
        $this->pagarConTarjeta(self::CLIENTE, $pagado)->assertOk();
        $conEfectivo = $this->pedidoDe(self::OTRO_CLIENTE, [$producto => 3], ['tipo' => 'ENCARGO']);
        $this->pagarEnEfectivo(self::OTRO_CLIENTE, $conEfectivo)->assertOk();
        $this->envejecer($pagado, 100);
        $this->envejecer($conEfectivo, 100);

        $this->vencer();

        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido($pagado)->estado);
        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido($conEfectivo)->estado);
    }

    public function test_un_encargo_pendiente_con_plan_sin_pagar_vence_y_cierra_el_plan(): void
    {
        $producto = $this->crearProducto(['permite_encargo' => true, 'precio_venta' => '80.00']);
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 3], ['tipo' => 'ENCARGO', 'forma_pago' => 'CUOTAS', 'num_cuotas' => 2]);
        $this->envejecer($pedido, 60);

        $this->vencer();

        $this->assertSame('CANCELADO', $this->pedido($pedido)->estado);
        $this->assertSame('CANCELADO', $this->plan($pedido)['estado']);
    }

    // ------------------------------------------------------------------ encargos impagos que ya esperan abastecimiento

    /*
     * el pedido 8 de la semilla (ENCARGO, ESPERANDO_ABASTECIMIENTO, del
     * 8 de noviembre y sin pagos) nunca vencía porque la tarea solo miraba pedidos PENDIENTE. Las 48 h de PROMPT 7.2
     * también se aplican a un encargo en espera sin ningún pago PAGADO ni pendiente. El efectivo contra entrega ya
     * aceptado y los pagos de pasarela por conciliar no se cancelan.
     */

    /** Encargo aceptado sin pago, como el pedido 8 de los datos iniciales. */
    private function encargoEnEsperaSinPago(string $correo = self::CLIENTE): int
    {
        $pedido = $this->pedidoDe($correo, [$this->crearProducto(['permite_encargo' => true]) => 3], ['tipo' => 'ENCARGO']);
        DB::update("UPDATE pedido SET estado = 'ESPERANDO_ABASTECIMIENTO' WHERE id = ?", [$pedido]);
        DB::table('historial_pedido')->insert(['pedido_id' => $pedido, 'usuario_id' => $this->idDe(self::PERSONAL),
            'estado_anterior' => 'PENDIENTE', 'estado_nuevo' => 'ESPERANDO_ABASTECIMIENTO', 'comentario' => 'Encargo aceptado sin pago']);

        return $pedido;
    }

    public function test_un_encargo_en_espera_sin_ningun_pago_vence_a_las_48_horas(): void
    {
        $pedido = $this->encargoEnEsperaSinPago();
        $this->envejecer($pedido, 49);

        $resultado = $this->vencer();

        $this->assertSame('CANCELADO', $this->pedido($pedido)->estado);
        $this->assertSame('CANCELADA', $this->entrega($pedido)->estado);
        $ultimo = $this->historial($pedido)->last();
        $this->assertSame(['ESPERANDO_ABASTECIMIENTO', 'CANCELADO'], [$ultimo->estado_anterior, $ultimo->estado_nuevo]);
        $this->assertNull($ultimo->usuario_id, 'El vencimiento automático no tiene responsable.');
        $this->assertStringContainsString('48', $ultimo->comentario);
        $this->assertGreaterThanOrEqual(1, $resultado['cancelados']);
        $anulacion = $this->bitacora('PEDIDO', $pedido)->last();
        $this->assertSame(['VENCER_PLAZO_DE_PAGO', 'ESPERANDO_ABASTECIMIENTO'], [$anulacion->detalle['operacion'], $anulacion->detalle['estado_anterior']]);
    }

    public function test_un_encargo_en_espera_reciente_no_vence(): void
    {
        $pedido = $this->encargoEnEsperaSinPago();
        $this->envejecer($pedido, 47);

        $this->vencer();

        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido($pedido)->estado);
    }

    public function test_un_encargo_en_espera_con_un_qr_por_conciliar_no_se_cancela(): void
    {
        $pedido = $this->encargoEnEsperaSinPago();
        $qr = $this->pagoPendienteDirecto($pedido, 'QR');
        $this->envejecer($pedido, 60);

        $resultado = $this->vencer();

        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido($pedido)->estado);
        $this->assertSame('PENDIENTE', $this->estadoPago($qr));
        $this->assertNotNull(collect($resultado['por_conciliar'])->firstWhere('pago_id', $qr), 'Se informa para conciliarlo.');
    }

    public function test_un_encargo_en_espera_con_efectivo_contra_entrega_o_con_un_pago_no_vence(): void
    {
        $conEfectivo = $this->encargoEnEsperaSinPago();
        $efectivo = $this->pagoPendienteDirecto($conEfectivo, 'EFECTIVO');
        $pagado = $this->encargoEnEsperaSinPago(self::OTRO_CLIENTE);
        DB::table('pago')->insert(['pedido_id' => $pagado, 'metodo' => 'TARJETA', 'monto' => $this->pedido($pagado)->total, 'estado' => 'PAGADO',
            'fecha_pago' => now(), 'referencia_externa' => 'PRUEBA-PAGADO-'.$pagado, 'clave_idempotencia' => hash('sha256', 'PRUEBA|PAGADO|'.$pagado)]);
        $this->envejecer($conEfectivo, 100);
        $this->envejecer($pagado, 100);

        $this->vencer();

        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido($conEfectivo)->estado);
        $this->assertSame('PENDIENTE', $this->estadoPago($efectivo), 'El efectivo contra entrega aceptado no se rechaza.');
        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido($pagado)->estado);
    }

    /*
     * Ciclo de verificación del hallazgo 6: «abastecer» dejaba CONFIRMADO un encargo en espera sin ningún cobro, con
     * reservas sin vencimiento. La tarea ya no lo veía y preparar lo rechazaba por falta de cobro, así que quedaba impago
     * y con stock reservado para siempre. Un encargo solo pasa a ESPERANDO_ABASTECIMIENTO al pagar o al aceptar efectivo
     * contra entrega; sin ese cobro no se abastece: sigue en espera, sin reservas, y vence a las 48 h.
     */

    private function productoDe(int $pedido): int
    {
        return (int) DB::table('detalle_pedido')->where('pedido_id', $pedido)->value('producto_id');
    }

    public function test_un_encargo_en_espera_sin_ningun_cobro_no_se_abastece_y_vence_a_las_48_horas(): void
    {
        $pedido = $this->encargoEnEsperaSinPago();
        $lote = $this->crearLoteFeder($this->productoDe($pedido), 10, null, '2026-10-01 09:00:00'); // llega el stock
        $this->envejecer($pedido, 49);

        $respuesta = $this->abastecer($this->personal(), $pedido);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('no tiene ningún pago', $respuesta->json('message'));
        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido($pedido)->estado);
        $this->assertCount(0, $this->reservas($pedido), 'No se reserva stock para un encargo impago.');
        $this->assertSame('0.000', $this->lote($lote)->cantidad_reservada);
        $this->withToken($this->personal())->getJson("/api/gestion/pedidos/{$pedido}")->assertOk()
            ->assertJsonPath('acciones.abastecer', false)->assertJsonPath('acciones.cancelar', true);

        $this->vencer();

        $this->assertSame('CANCELADO', $this->pedido($pedido)->estado);
        $this->assertSame('0.000', $this->lote($lote)->cantidad_reservada);
    }

    public function test_el_pedido_8_de_los_datos_iniciales_no_se_abastece_sin_cobro_y_la_tarea_lo_vence(): void
    {
        // Simula el encargo histórico sin cobro. El caso con efectivo se prueba en EncargoDatosInicialesTest.
        $this->assertSame(1, DB::update("UPDATE pago SET estado = 'RECHAZADO' WHERE pedido_id = 8 AND metodo = 'EFECTIVO' AND estado = 'PENDIENTE'"));
        $this->envejecer(8, 49);
        $lote = $this->crearLoteFeder(2, 12, '2027-11-05', '2026-11-20 09:00:00');

        $this->abastecer($this->personal(), 8)->assertStatus(422);
        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido(8)->estado);
        $this->vencer();
        $this->vencer();

        $this->assertSame('CANCELADO', $this->pedido(8)->estado);
        $this->assertCount(0, $this->reservas(8));
        $this->assertSame('0.000', $this->lote($lote)->cantidad_reservada);
    }

    public function test_un_encargo_en_espera_con_un_qr_por_conciliar_se_abastece_cuando_el_pago_se_confirma(): void
    {
        $pedido = $this->encargoEnEsperaSinPago();
        $qr = $this->pagoPendienteDirecto($pedido, 'QR');
        $lote = $this->crearLoteFeder($this->productoDe($pedido), 10, null, '2026-10-01 09:00:00');

        $respuesta = $this->abastecer($this->personal(), $pedido);

        $respuesta->assertStatus(409);
        $this->assertStringContainsString('Gestión de pagos', $respuesta->json('message'));
        $this->assertSame('0.000', $this->lote($lote)->cantidad_reservada);

        $this->withToken($this->personal())->postJson("/api/gestion/pagos/{$qr}/confirmar")->assertOk();
        $this->abastecer($this->personal(), $pedido)->assertOk()->assertJsonPath('estado', 'CONFIRMADO');

        $this->assertSame('3.000', $this->lote($lote)->cantidad_reservada);
        $this->assertNull($this->reservas($pedido)[0]->fecha_expiracion);
    }

    // ------------------------------------------------------------------ R053: pagos de pasarela por conciliar

    /*
     * Antes, la tarea rechazaba el QR pendiente y cancelaba el pedido (la prueba anterior lo daba por bueno).
     * R053 del modelo aprobado (CancelacionPedido.expirar del P1): vencer el plazo comercial no demuestra que el
     * proveedor no haya cobrado. Un QR o TARJETA PENDIENTE se deja como está y se informa para conciliarlo.
     */

    /** El cliente genera un QR: queda un pago QR PENDIENTE cuyo resultado todavía no se conoce. */
    private function qrPendiente(int $pedido, string $correo = self::CLIENTE): object
    {
        $this->withToken($this->cliente($correo))->postJson("/api/mis-pedidos/{$pedido}/pagar", ['metodo' => 'QR'])->assertOk();

        return $this->pagos($pedido)->firstWhere('estado', 'PENDIENTE');
    }

    private function pagoPendienteDirecto(int $pedido, string $metodo): int
    {
        return (int) DB::table('pago')->insertGetId([
            'pedido_id' => $pedido, 'metodo' => $metodo, 'monto' => $this->pedido($pedido)->total, 'estado' => 'PENDIENTE',
            'referencia_externa' => 'PRUEBA-'.$metodo.'-'.$pedido, 'clave_idempotencia' => hash('sha256', 'PRUEBA|'.$metodo.'|'.$pedido),
        ]);
    }

    private function estadoPago(int|string $pagoId): string
    {
        return DB::table('pago')->where('id', $pagoId)->value('estado');
    }

    public function test_el_vencimiento_no_rechaza_un_qr_pendiente_ni_cancela_el_pedido(): void
    {
        $producto = $this->crearProducto();
        $lote = $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $inmediato = $this->pedidoDe(self::CLIENTE, [$producto => 4]);
        $qrInmediato = $this->qrPendiente($inmediato);
        $encargo = $this->pedidoDe(self::OTRO_CLIENTE, [$this->crearProducto(['permite_encargo' => true]) => 3], ['tipo' => 'ENCARGO']);
        $qrEncargo = $this->qrPendiente($encargo, self::OTRO_CLIENTE);
        $this->envejecer($inmediato, 49);
        $this->envejecer($encargo, 49);

        $this->vencer();

        foreach ([[$inmediato, $qrInmediato], [$encargo, $qrEncargo]] as [$pedido, $qr]) {
            $this->assertSame('PENDIENTE', $this->pedido($pedido)->estado);
            $this->assertSame('PENDIENTE', $this->estadoPago($qr->id), 'El QR sigue pendiente de conciliar: no se convierte en rechazo.');
            $this->assertSame('PENDIENTE', $this->entrega($pedido)->estado);
        }
        $this->assertSame('ACTIVA', $this->reservas($inmediato)[0]->estado, 'La reserva se conserva hasta conciliar el pago.');
        $this->assertSame('4.000', $this->lote($lote)->cantidad_reservada);
    }

    public function test_una_tarjeta_pendiente_tambien_queda_para_conciliar(): void
    {
        $pedido = $this->pedidoDe(self::CLIENTE, [$this->crearProducto(['permite_encargo' => true]) => 3], ['tipo' => 'ENCARGO']);
        $tarjeta = $this->pagoPendienteDirecto($pedido, 'TARJETA');
        $this->envejecer($pedido, 60);

        $this->vencer();

        $this->assertSame('PENDIENTE', $this->pedido($pedido)->estado);
        $this->assertSame('PENDIENTE', $this->estadoPago($tarjeta));
    }

    public function test_un_qr_que_se_confirma_despues_del_plazo_confirma_el_pedido(): void
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 2]);
        $qr = $this->qrPendiente($pedido);
        $this->envejecer($pedido, 49);
        $this->vencer();

        $this->withToken($this->personal())->postJson("/api/gestion/pagos/{$qr->id}/confirmar")->assertOk();
        $this->vencer();

        $this->assertSame('PAGADO', $this->estadoPago($qr->id));
        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);
        $this->assertNull($this->reservas($pedido)[0]->fecha_expiracion, 'Con el pago confirmado la reserva deja de vencer.');
    }

    public function test_rechazado_el_qr_con_su_motivo_la_tarea_cancela_el_pedido_impago(): void
    {
        $producto = $this->crearProducto();
        $lote = $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 2]);
        $qr = $this->qrPendiente($pedido);
        $this->envejecer($pedido, 49);
        $this->vencer();

        $this->withToken($this->personal())->postJson("/api/gestion/pagos/{$qr->id}/rechazar", ['motivo' => 'El banco no registra el cobro'])->assertOk();
        $this->vencer();

        $this->assertSame('RECHAZADO', $this->estadoPago($qr->id));
        $this->assertSame('CANCELADO', $this->pedido($pedido)->estado);
        $this->assertSame('EXPIRADA', $this->reservas($pedido)[0]->estado);
        $this->assertSame('0.000', $this->lote($lote)->cantidad_reservada);
        $rechazo = $this->bitacora('PAGO', (int) $qr->id)->last();
        $this->assertSame(['RECHAZAR_PAGO', $this->idDe(self::PERSONAL)], [$rechazo->detalle['operacion'], (int) $rechazo->usuario_id],
            'El rechazo lo decide una persona con su motivo, no la tarea programada.');
    }

    public function test_la_tarea_informa_los_pedidos_con_pago_por_conciliar(): void
    {
        $pedido = $this->pedidoDe(self::CLIENTE, [$this->crearProducto(['permite_encargo' => true]) => 3], ['tipo' => 'ENCARGO']);
        $qr = $this->qrPendiente($pedido);
        $this->envejecer($pedido, 49);
        $codigo = $this->pedido($pedido)->codigo;

        $resultado = $this->vencer();

        // La semilla también tiene pedidos vencidos (alguno con un QR por conciliar): se busca el de esta prueba.
        $informe = collect($resultado['por_conciliar'])->firstWhere('pedido', $codigo);
        $this->assertNotNull($informe, 'La tarea informa el pedido con el QR pendiente.');
        $this->assertSame([(int) $qr->id, 'QR'], [$informe['pago_id'], $informe['metodo']]);
        $this->assertStringContainsString('confírmalo si se cobró', $informe['mensaje']);
        $this->assertSame('PENDIENTE', $this->pedido($pedido)->estado);
        $this->artisan('feder:vencer-pedidos')->expectsOutputToContain($codigo)->assertSuccessful();
        $this->assertSame('PENDIENTE', $this->estadoPago($qr->id));
    }

    public function test_un_efectivo_pendiente_sin_aceptacion_comercial_termina_sin_cobro_al_vencer(): void
    {
        // R049 (ReintegroPendiente del P1): el efectivo no pasa por una pasarela; sin aceptación comercial termina con el pedido.
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 2]);
        $efectivo = $this->pagoPendienteDirecto($pedido, 'EFECTIVO');
        $this->envejecer($pedido, 49);

        $this->vencer();

        $this->assertSame('CANCELADO', $this->pedido($pedido)->estado);
        $this->assertSame('RECHAZADO', $this->estadoPago($efectivo));
        $this->assertSame('RECHAZADO', $this->bitacora('PAGO', $efectivo)->last()->detalle['estado_nuevo']);
    }

    public function test_el_pedido_inmediato_sin_pagar_sigue_venciendo_y_libera_su_reserva(): void
    {
        $producto = $this->crearProducto();
        $lote = $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $vencido = $this->pedidoDe(self::CLIENTE, [$producto => 4]);
        $vigente = $this->pedidoDe(self::OTRO_CLIENTE, [$producto => 1]);
        $this->envejecer($vencido, 49);

        $this->vencer();

        $this->assertSame('CANCELADO', $this->pedido($vencido)->estado);
        $this->assertSame('EXPIRADA', $this->reservas($vencido)[0]->estado);
        $this->assertSame('PENDIENTE', $this->pedido($vigente)->estado);
        $this->assertSame('1.000', $this->lote($lote)->cantidad_reservada);
    }

    public function test_el_comando_programado_informa_lo_que_hizo(): void
    {
        $producto = $this->crearProducto(['permite_encargo' => true]);
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 3], ['tipo' => 'ENCARGO']);
        $this->envejecer($pedido, 49);

        $this->artisan('feder:vencer-pedidos')->assertSuccessful();

        $this->assertSame('CANCELADO', $this->pedido($pedido)->estado);
    }
}
