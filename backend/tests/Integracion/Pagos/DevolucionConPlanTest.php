<?php

namespace Tests\Integracion\Pagos;

use Illuminate\Support\Facades\DB;

/**
 * H-09: devoluciones de un pedido con plan de cuotas a medio pagar.
 * - El importe a reintegrar nunca supera lo realmente cobrado (R020).
 * - La parte del valor devuelto que aún no se había cobrado se descuenta de las cuotas pendientes (de la última a la
 *   primera): una cuota de algo ya devuelto no queda viva ni bloquea compras.
 */
class DevolucionConPlanTest extends PagosTestCase
{
    public function test_devolucion_total_con_una_cuota_pagada_reintegra_lo_pagado_y_anula_la_cuota_pendiente(): void
    {
        [$pedido, $despacho] = $this->pedidoConPrimeraCuotaEntregado([self::ACEITE_COPAIBO => 3], self::ACEITE_COPAIBO);   // 105 + 105
        $this->solicitarDevolucion(self::LUCIO, $despacho, 3)->assertCreated();
        $devolucion = $this->ultimaDevolucion($despacho);

        $this->aprobarDevolucion($devolucion, 'REEMBOLSO', 3, 3, 3)->assertOk();

        $this->assertEquals(105.0, (float) DB::table('devolucion')->where('id', $devolucion)->value('importe_reembolsable'),
            'Solo se puede reintegrar lo cobrado (Bs 105), no el valor completo (Bs 210).');
        $plan = $this->plan($pedido['id']);
        $this->assertSame('CERRADO_POR_DEVOLUCION', $plan['estado']);
        $this->assertSame(['PAGADA', 'ANULADA'], array_column($plan['cuotas'], 'estado'));
        $this->assertEquals(0.0, $plan['cuotas'][1]['monto']);
        $this->assertEquals(105.0, $plan['cuotas'][1]['monto_original']);
        $this->assertEquals(105.0, $plan['ajustes'][0]['monto']);
        $this->assertSame($devolucion, $plan['ajustes'][0]['devolucion_id']);

        $this->completarDevolucion($devolucion)->assertOk();
        $this->assertSame('COMPLETADA', DB::table('devolucion')->where('id', $devolucion)->value('estado'));
        $this->assertEquals(105.0, (float) DB::table('reembolso')->where('devolucion_id', $devolucion)->sum('monto'));

        $detalle = $this->withToken($this->cliente(self::LUCIO))->getJson("/api/mis-pedidos/{$pedido['id']}")->assertOk()->json();
        $this->assertFalse($detalle['puede_pagar'], 'Ya no queda ninguna cuota por pagar.');
        $this->assertEquals(0, $detalle['saldo']);
        $this->assertNull($detalle['factura']);
        $this->assertTrue($detalle['estado_cuenta']['liquidacion']);
        foreach (['original' => 210, 'devoluciones_aprobadas' => 210, 'cobrado' => 105, 'reembolsado' => 105,
            'reembolso_pendiente' => 0, 'reduccion_cuotas' => 105, 'neto' => 0, 'pendiente_pago' => 0] as $campo => $importe) {
            $this->assertEquals($importe, $detalle['estado_cuenta'][$campo], $campo);
        }
    }

    public function test_la_cuota_de_un_pedido_devuelto_no_bloquea_las_compras_aunque_su_fecha_haya_pasado(): void
    {
        [$pedido, $despacho] = $this->pedidoConPrimeraCuotaEntregado([self::ACEITE_COPAIBO => 3], self::ACEITE_COPAIBO);
        $fila = $this->filaPlan($pedido['id']);
        DB::update("UPDATE confirmacion_operacion SET parametros = jsonb_set(parametros, '{cuotas,1,vence}', to_jsonb('2020-01-01'::text)) WHERE id = ?", [$fila->id]);
        $token = $this->cliente(self::LUCIO);

        // Antes de devolver, la cuota vencida sí bloquea (regla del plan que se conserva).
        $this->withToken($token)->postJson('/api/carrito', ['producto_id' => self::HARINA_PLATANO, 'cantidad' => 1])->assertStatus(403);

        $this->solicitarDevolucion(self::LUCIO, $despacho, 3)->assertCreated();
        $this->aprobarDevolucion($this->ultimaDevolucion($despacho), 'REEMBOLSO', 3, 3, 3)->assertOk();

        $this->withToken($token)->getJson('/api/planes-pago')->assertOk()->assertJsonPath('tiene_cuota_vencida', false);
        $this->withToken($token)->postJson('/api/carrito', ['producto_id' => self::HARINA_PLATANO, 'cantidad' => 1])->assertOk();
    }

    public function test_devolucion_parcial_que_cabe_en_lo_pagado_se_reintegra_y_no_toca_las_cuotas(): void
    {
        // Bs 140 de aceite de copaibo + Bs 60 de aceite de motacú = Bs 200 → cuotas de 100 y 100; pagada la primera.
        [$pedido] = $this->pedidoConPrimeraCuotaEntregado([self::ACEITE_COPAIBO => 2, self::ACEITE_MOTACU => 1], self::ACEITE_COPAIBO);
        $despachoMotacu = $this->despachoDe($pedido['id'], self::ACEITE_MOTACU);
        $this->solicitarDevolucion(self::LUCIO, $despachoMotacu, 1)->assertCreated();
        $devolucion = $this->ultimaDevolucion($despachoMotacu);

        $this->aprobarDevolucion($devolucion, 'REEMBOLSO', 1, 1, 1)->assertOk();

        $this->assertEquals(60.0, (float) DB::table('devolucion')->where('id', $devolucion)->value('importe_reembolsable'));
        $plan = $this->plan($pedido['id']);
        $this->assertSame('ACTIVO', $plan['estado']);
        $this->assertSame(['PAGADA', 'PENDIENTE'], array_column($plan['cuotas'], 'estado'));
        $this->assertEquals(100.0, $plan['cuotas'][1]['monto'], 'El cliente sigue debiendo lo que conserva.');
        $this->assertArrayNotHasKey('ajustes', $plan);
    }

    public function test_devolver_despues_el_resto_reintegra_solo_lo_que_queda_cobrado_y_descuenta_lo_demas_de_la_cuota(): void
    {
        [$pedido, $despachoCopaibo] = $this->pedidoConPrimeraCuotaEntregado([self::ACEITE_COPAIBO => 2, self::ACEITE_MOTACU => 1], self::ACEITE_COPAIBO);
        $despachoMotacu = $this->despachoDe($pedido['id'], self::ACEITE_MOTACU);
        $this->solicitarDevolucion(self::LUCIO, $despachoMotacu, 1)->assertCreated();
        $this->aprobarDevolucion($this->ultimaDevolucion($despachoMotacu), 'REEMBOLSO', 1, 1, 1)->assertOk();   // reintegra 60 de los 100 cobrados

        $this->solicitarDevolucion(self::LUCIO, $despachoCopaibo, 2)->assertCreated();
        $segunda = $this->ultimaDevolucion($despachoCopaibo);
        $this->aprobarDevolucion($segunda, 'REEMBOLSO', 2, 2, 2)->assertOk();   // vale 140: 40 en dinero y 100 de la cuota

        $this->assertEquals(40.0, (float) DB::table('devolucion')->where('id', $segunda)->value('importe_reembolsable'));
        $plan = $this->plan($pedido['id']);
        $this->assertSame('CERRADO_POR_DEVOLUCION', $plan['estado']);
        $this->assertSame('ANULADA', $plan['cuotas'][1]['estado']);
        $this->assertEquals(100.0, $plan['ajustes'][0]['monto']);

        // Lo comprometido en reintegros nunca supera lo cobrado.
        $comprometido = (float) DB::table('devolucion as d')->join('movimiento_inventario as m', 'm.id', '=', 'd.movimiento_inventario_id')
            ->join('reserva_stock as rs', 'rs.id', '=', 'm.reserva_id')->join('detalle_pedido as dp', 'dp.id', '=', 'rs.detalle_pedido_id')
            ->where('dp.pedido_id', $pedido['id'])->sum('d.importe_reembolsable');
        $this->assertEquals(100.0, $comprometido);
        $this->assertEquals(100.0, (float) DB::table('pago')->where('pedido_id', $pedido['id'])->where('estado', 'PAGADO')->sum('monto'));
    }

    public function test_el_valor_ya_descontado_de_una_linea_no_se_reintegra_otra_vez_en_una_devolucion_posterior(): void
    {
        [$pedido, $despacho] = $this->pedidoConPrimeraCuotaEntregado([self::ACEITE_COPAIBO => 3], self::ACEITE_COPAIBO);   // 105 pagados, 105 pendientes
        $this->solicitarDevolucion(self::LUCIO, $despacho, 2)->assertCreated();
        $primera = $this->ultimaDevolucion($despacho);
        $this->aprobarDevolucion($primera, 'REEMBOLSO', 2, 2, 2)->assertOk();   // vale 140: 105 en dinero y 35 de la cuota

        $this->assertEquals(105.0, (float) DB::table('devolucion')->where('id', $primera)->value('importe_reembolsable'));
        $this->assertEquals(70.0, $this->plan($pedido['id'])['cuotas'][1]['monto']);

        $this->withToken($this->cliente(self::LUCIO))->getJson("/api/mis-pedidos/{$pedido['id']}")->assertOk()
            ->assertJsonPath('saldo', 70)->assertJsonPath('plan.saldo', 70);

        // El cliente paga la cuota ya reducida (Bs 70) y devuelve la última unidad (vale Bs 70).
        $this->assertEquals(70.0, $this->pagarConTarjeta(self::LUCIO, $pedido['id'], 2)->assertOk()->json('pago.monto'));
        $this->solicitarDevolucion(self::LUCIO, $despacho, 1)->assertCreated();
        $segunda = $this->ultimaDevolucion($despacho);
        $this->aprobarDevolucion($segunda, 'REEMBOLSO', 1, 1, 1)->assertOk();

        $this->assertEquals(70.0, (float) DB::table('devolucion')->where('id', $segunda)->value('importe_reembolsable'),
            'La última unidad vale Bs 70: los Bs 35 ya descontados de la cuota no se reintegran de nuevo.');
        $this->completarDevolucion($primera)->assertOk();
        $this->completarDevolucion($segunda)->assertOk();
        $this->assertEquals(175.0, (float) DB::table('pago')->where('pedido_id', $pedido['id'])->where('estado', 'PAGADO')->sum('monto'));
        $this->assertEquals(175.0, (float) DB::table('reembolso as r')->join('pago as g', 'g.id', '=', 'r.pago_id')->where('g.pedido_id', $pedido['id'])->sum('r.monto'));
        $this->assertSame(0, DB::table('factura')->where('pedido_id', $pedido['id'])->count());
        $this->withToken($this->cliente(self::LUCIO))->getJson("/api/mis-pedidos/{$pedido['id']}")->assertOk()
            ->assertJsonCount(2, 'estado_cuenta.devoluciones')->assertJsonPath('estado_cuenta.neto', 0)
            ->assertJsonPath('estado_cuenta.devoluciones_aprobadas', 210)->assertJsonPath('estado_cuenta.reembolso_pendiente', 0);
    }

    /** Caso B. El cliente conserva una unidad y paga la cuota reducida. */
    public function test_la_ultima_cuota_reducida_liquida_la_deuda_con_resumen(): void
    {
        [$pedido, $despacho] = $this->pedidoConPrimeraCuotaEntregado([self::ACEITE_COPAIBO => 3], self::ACEITE_COPAIBO);   // 105 + 105
        $this->solicitarDevolucion(self::LUCIO, $despacho, 2)->assertCreated();
        $devolucion = $this->ultimaDevolucion($despacho);
        $this->aprobarDevolucion($devolucion, 'REEMBOLSO', 2, 2, 2)->assertOk();   // vale 140: 105 en dinero y 35 de la cuota 2
        $this->completarDevolucion($devolucion)->assertOk();
        $this->assertSame(0, DB::table('factura')->where('pedido_id', $pedido['id'])->count(), 'Aún queda una cuota por pagar.');

        $this->pagarConTarjeta(self::LUCIO, $pedido['id'], 2)->assertOk()->assertJsonPath('pago.monto', 70)
            ->assertJsonPath('pedido.puede_pagar', false)
            ->assertJsonPath('pedido.saldo', 0)->assertJsonPath('pedido.estado_cuenta.original', 210)
            ->assertJsonPath('pedido.estado_cuenta.devoluciones_aprobadas', 140)->assertJsonPath('pedido.estado_cuenta.cobrado', 175)
            ->assertJsonPath('pedido.estado_cuenta.reembolsado', 105)->assertJsonPath('pedido.estado_cuenta.reduccion_cuotas', 35)
            ->assertJsonPath('pedido.estado_cuenta.reembolso_pendiente', 0)->assertJsonPath('pedido.estado_cuenta.neto', 70)
            ->assertJsonPath('pedido.estado_cuenta.pendiente_pago', 0)->assertJsonPath('pedido.estado_cuenta.liquidacion', true);

        $this->assertSame('COMPLETADO', $this->plan($pedido['id'])['estado']);
        $this->assertSame(0, DB::table('factura')->where('pedido_id', $pedido['id'])->count());
        $this->assertSame('ENTREGADO', $this->estadoPedido($pedido['id']));
    }

    /** Igual con tres cuotas: la devolución anula la última y el cliente salda el plan pagando la del medio. */
    public function test_tres_cuotas_con_una_anulada_se_liquidan_con_resumen(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 3], 3);   // 70 + 70 + 70
        $this->pagarConTarjeta(self::LUCIO, $pedido['id'], 1)->assertOk();
        $this->entregar($pedido['id']);
        $despacho = $this->despachoDe($pedido['id'], self::ACEITE_COPAIBO);
        $this->solicitarDevolucion(self::LUCIO, $despacho, 2)->assertCreated();
        $this->aprobarDevolucion($this->ultimaDevolucion($despacho), 'REEMBOLSO', 2, 2, 2)->assertOk();   // 70 en dinero, 70 de la cuota 3
        $this->assertSame(['PAGADA', 'PENDIENTE', 'ANULADA'], array_column($this->plan($pedido['id'])['cuotas'], 'estado'));

        $this->pagarConTarjeta(self::LUCIO, $pedido['id'], 2)->assertOk();

        $this->assertNotSame('ACTIVO', $this->plan($pedido['id'])['estado']);
        $this->assertSame(0, DB::table('factura')->where('pedido_id', $pedido['id'])->count());
    }

    public function test_con_un_qr_pendiente_no_se_aprueba_una_devolucion_que_cambie_las_cuotas(): void
    {
        [$pedido, $despacho] = $this->pedidoConPrimeraCuotaEntregado([self::ACEITE_COPAIBO => 3], self::ACEITE_COPAIBO);
        $this->pagar(self::LUCIO, $pedido['id'], ['metodo' => 'QR', 'cuota_numero' => 2])->assertOk();
        $this->solicitarDevolucion(self::LUCIO, $despacho, 3)->assertCreated();
        $devolucion = $this->ultimaDevolucion($despacho);

        $this->aprobarDevolucion($devolucion, 'REEMBOLSO', 3, 3, 3)->assertStatus(409);

        $this->assertSame('SOLICITADA', DB::table('devolucion')->where('id', $devolucion)->value('estado'));
        $this->assertSame('ACTIVO', $this->plan($pedido['id'])['estado']);
    }

    /** @return array{0: array, 1: int} pedido con plan de 2 cuotas, la primera pagada y entregado; y el despacho pedido */
    private function pedidoConPrimeraCuotaEntregado(array $items, int $productoDelDespacho): array
    {
        $pedido = $this->crearPedido(self::LUCIO, $items, 2);
        $this->pagarConTarjeta(self::LUCIO, $pedido['id'], 1)->assertOk();
        $this->entregar($pedido['id']);

        return [$pedido, $this->despachoDe($pedido['id'], $productoDelDespacho)];
    }
}
