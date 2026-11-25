<?php

namespace Tests\Integracion\Pagos;

use Illuminate\Support\Facades\DB;

/**
 * H-27: un QR pendiente que todavía puede cobrarse no se rechaza por su cuenta (reglas R048, R051 y R053 del modelo).
 * - Repetir «pagar con QR» devuelve el mismo QR.
 * - Cambiar de medio exige que el cliente declare que anula su QR; si la tarjeta falla, el QR sigue vivo.
 * - Cancelar el pedido no rechaza el QR: si el cobro llega después, se registra y se reintegra.
 */
class QrPendienteTest extends PagosTestCase
{
    public function test_repetir_el_pago_con_qr_devuelve_el_mismo_qr_pendiente(): void
    {
        $pedido = $this->crearPedido(self::MARCO, [self::ACEITE_COPAIBO => 1]);

        $primero = $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'QR'])->assertOk()->json();
        $segundo = $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'QR'])->assertOk()->json();

        $this->assertSame($primero['pago']['id'], $segundo['pago']['id']);
        $this->assertSame($primero['qr']['contenido'], $segundo['qr']['contenido']);
        $this->assertTrue($segundo['repetido']);
        $this->assertCount(1, $this->pagos($pedido['id']));
    }

    public function test_cambiar_de_medio_con_un_qr_pendiente_exige_anularlo_expresamente(): void
    {
        $pedido = $this->crearPedido(self::MARCO, [self::ACEITE_COPAIBO => 1]);
        $qr = $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'QR'])->assertOk()->json('pago.id');

        $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta()])
            ->assertStatus(409)->assertJsonValidationErrors('anular_qr_pendiente');
        $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'EFECTIVO'])
            ->assertStatus(409)->assertJsonValidationErrors('anular_qr_pendiente');

        $this->assertSame('PENDIENTE', DB::table('pago')->where('id', $qr)->value('estado'));
        $this->assertCount(1, $this->pagos($pedido['id']));
        $this->assertSame('PENDIENTE', $this->estadoPedido($pedido['id']));
    }

    public function test_si_la_tarjeta_falla_el_qr_sigue_pendiente_y_el_personal_puede_confirmarlo_despues(): void
    {
        $pedido = $this->crearPedido(self::MARCO, [self::ACEITE_COPAIBO => 1]);
        $qr = $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'QR'])->assertOk()->json('pago.id');

        $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(self::TARJETA_SIN_FONDOS), 'anular_qr_pendiente' => true])
            ->assertOk()->assertJsonPath('resultado', 'RECHAZADO');
        $this->assertSame('PENDIENTE', DB::table('pago')->where('id', $qr)->value('estado'), 'La tarjeta falló: el QR no debía rechazarse.');

        $this->withToken($this->personal())->postJson("/api/gestion/pagos/{$qr}/confirmar")->assertOk()->assertJsonPath('estado', 'PAGADO');
        $this->assertSame('CONFIRMADO', $this->estadoPedido($pedido['id']));
        $this->assertSame(1, DB::table('factura')->where('pedido_id', $pedido['id'])->count());
    }

    public function test_si_la_tarjeta_se_aprueba_el_qr_se_anula_por_decision_del_cliente_y_queda_en_bitacora(): void
    {
        $pedido = $this->crearPedido(self::MARCO, [self::ACEITE_COPAIBO => 1]);
        $qr = $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'QR'])->assertOk()->json('pago.id');

        $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(), 'anular_qr_pendiente' => true])
            ->assertOk()->assertJsonPath('resultado', 'PAGADO');

        $this->assertSame('RECHAZADO', DB::table('pago')->where('id', $qr)->value('estado'));
        $this->assertCount(1, $this->pagos($pedido['id'], 'PAGADO'));
        $anulacion = $this->bitacora('PAGO', $qr)->firstWhere('detalle.operacion', 'ANULAR_QR');
        $this->assertNotNull($anulacion, 'La anulación del QR no quedó en la bitácora.');
        $this->assertSame('MODIFICAR', $anulacion->accion);
        $this->assertSame($this->idDe(self::MARCO), (int) $anulacion->usuario_id);
        $this->assertNotEmpty($anulacion->detalle['motivo']);

        // Un QR ya anulado no se puede confirmar después: su estado final no cambia.
        $this->withToken($this->personal())->postJson("/api/gestion/pagos/{$qr}/confirmar")->assertStatus(422);
        $this->assertEquals(70.0, (float) DB::table('pago')->where('pedido_id', $pedido['id'])->where('estado', 'PAGADO')->sum('monto'));
    }

    public function test_cancelar_el_pedido_no_rechaza_el_qr_y_el_cobro_tardio_se_registra_y_se_reintegra(): void
    {
        $pedido = $this->crearPedido(self::MARCO, [self::ACEITE_COPAIBO => 1]);
        $qr = $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'QR'])->assertOk()->json('pago.id');

        $this->withToken($this->cliente(self::MARCO))->postJson("/api/mis-pedidos/{$pedido['id']}/cancelar", ['motivo' => 'Ya no lo necesito'])
            ->assertOk()->assertJsonPath('estado', 'CANCELADO');
        $this->assertSame('PENDIENTE', DB::table('pago')->where('id', $qr)->value('estado'), 'El QR no debía rechazarse al cancelar.');

        $this->withToken($this->personal())->postJson("/api/gestion/pagos/{$qr}/confirmar")
            ->assertOk()->assertJsonPath('estado', 'PAGADO')->assertJsonPath('cobro_tardio', true);

        $this->assertSame('CANCELADO', $this->estadoPedido($pedido['id']), 'Un cobro tardío no reactiva el pedido.');
        $reembolso = DB::table('reembolso')->where('pago_id', $qr)->first();
        $this->assertNotNull($reembolso);
        $this->assertNull($reembolso->devolucion_id);
        $this->assertSame('EJECUTADO', $reembolso->estado);
        $this->assertEquals(70.0, (float) $reembolso->monto);
        $this->assertSame(0, DB::table('factura')->where('pedido_id', $pedido['id'])->count(), 'Un pedido cancelado no se factura.');
        $this->assertSame('CREAR', $this->bitacora('REEMBOLSO', (int) $reembolso->id)->first()->accion);
        $this->assertSame(1, DB::table('notificacion')->where('pedido_id', $pedido['id'])->where('tipo', 'REEMBOLSO_EJECUTADO')->count());
    }

    public function test_el_personal_puede_cerrar_con_motivo_el_qr_de_un_pedido_cancelado_que_nunca_se_pago(): void
    {
        $pedido = $this->crearPedido(self::MARCO, [self::ACEITE_COPAIBO => 1]);
        $qr = $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'QR'])->assertOk()->json('pago.id');
        $this->withToken($this->cliente(self::MARCO))->postJson("/api/mis-pedidos/{$pedido['id']}/cancelar", ['motivo' => 'Ya no lo necesito'])->assertOk();

        $this->withToken($this->personal())->postJson("/api/gestion/pagos/{$qr}/rechazar", ['motivo' => 'El banco no registra el cobro'])->assertOk();

        $this->assertSame('RECHAZADO', DB::table('pago')->where('id', $qr)->value('estado'));
        $fila = $this->bitacora('PAGO', $qr)->firstWhere('detalle.operacion', 'RECHAZAR_PAGO');
        $this->assertSame('El banco no registra el cobro', $fila->detalle['motivo']);
        $this->assertSame(0, DB::table('reembolso')->where('pago_id', $qr)->count());
    }

    public function test_el_qr_simulado_de_un_pedido_cancelado_tambien_se_reintegra(): void
    {
        config(['feder.qr_simulado' => true]);
        $pedido = $this->crearPedido(self::MARCO, [self::ACEITE_COPAIBO => 1]);
        $qr = $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'QR'])->assertOk()->json('pago.id');
        $this->withToken($this->cliente(self::MARCO))->postJson("/api/mis-pedidos/{$pedido['id']}/cancelar", ['motivo' => 'Ya no lo necesito'])->assertOk();

        $this->withToken($this->cliente(self::MARCO))->postJson("/api/mis-pagos/{$qr}/simular-qr")->assertOk()->assertJsonPath('pedido.estado', 'CANCELADO');

        $this->assertEquals(70.0, (float) DB::table('reembolso')->where('pago_id', $qr)->where('estado', 'EJECUTADO')->sum('monto'));
    }

    public function test_una_tarjeta_pendiente_heredada_solo_se_resuelve_por_conciliacion(): void
    {
        $pedido = $this->crearPedido(self::MARCO, [self::ACEITE_COPAIBO => 1]);
        $pago = DB::table('pago')->insertGetId([
            'pedido_id' => $pedido['id'], 'metodo' => 'TARJETA', 'monto' => 70, 'estado' => 'PENDIENTE',
            'referencia_externa' => 'TRJ-laboratorio', 'clave_idempotencia' => $this->uuid(),
        ]);
        $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'QR'])
            ->assertStatus(409)->assertJsonMissingPath('errors.anular_qr_pendiente');
        $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'EFECTIVO', 'anular_qr_pendiente' => true])->assertStatus(409);
        $this->assertSame('PENDIENTE', DB::table('pago')->where('id', $pago)->value('estado'));

        $this->withToken($this->personal())->postJson("/api/gestion/pagos/{$pago}/rechazar", ['motivo' => 'El banco no registra el cobro'])
            ->assertOk()->assertJsonPath('estado', 'RECHAZADO');
        $this->assertSame('TARJETA', $this->bitacora('PAGO', $pago)->firstWhere('detalle.operacion', 'RECHAZAR_PAGO')->detalle['metodo']);
    }

    public function test_la_tarjeta_heredada_conciliada_tras_cancelar_se_reintegra_con_el_mismo_pago(): void
    {
        $pedido = $this->crearPedido(self::MARCO, [self::ACEITE_COPAIBO => 1]);
        $pago = DB::table('pago')->insertGetId([
            'pedido_id' => $pedido['id'], 'metodo' => 'TARJETA', 'monto' => 70, 'estado' => 'PENDIENTE',
            'referencia_externa' => 'TRJ-laboratorio', 'clave_idempotencia' => $this->uuid(),
        ]);
        $this->withToken($this->cliente(self::MARCO))->postJson("/api/mis-pedidos/{$pedido['id']}/cancelar", ['motivo' => 'Ya no lo necesito'])
            ->assertOk()->assertJsonPath('estado', 'CANCELADO');
        $this->withToken($this->personal())->postJson("/api/gestion/pagos/{$pago}/confirmar")
            ->assertOk()->assertJsonPath('cobro_tardio', true);
        $this->assertEquals(70.0, (float) DB::table('reembolso')->where('pago_id', $pago)->where('estado', 'EJECUTADO')->sum('monto'));
        $this->assertSame('CANCELADO', $this->estadoPedido($pedido['id']));
        $this->assertSame(0, DB::table('factura')->where('pedido_id', $pedido['id'])->count());
    }

    public function test_el_efectivo_pendiente_no_se_puede_cambiar_por_otro_medio(): void
    {
        $pedido = $this->crearPedido(self::MARCO, [self::ACEITE_COPAIBO => 1]);
        $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'EFECTIVO'])->assertOk()->assertJsonPath('resultado', 'PENDIENTE');

        $this->pagar(self::MARCO, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(), 'anular_qr_pendiente' => true])->assertStatus(422);

        $this->assertCount(1, $this->pagos($pedido['id']));
    }
}
