<?php

namespace Tests\Integracion\Pagos;

use Illuminate\Support\Facades\DB;

/**
 * H-15: se conserva la decisión del proyecto 2 (el pedido se confirma y se entrega al pagar la PRIMERA cuota), pero
 * solo con un plan coherente con el cliente y el pedido, y con un pago realmente PAGADO de esa cuota. El saldo sigue
 * siendo el real y la factura se emite una sola vez, al pagar la última cuota.
 */
class PlanPrimeraCuotaTest extends PagosTestCase
{
    public function test_la_primera_cuota_pagada_confirma_el_pedido_y_conserva_el_saldo_real_sin_factura(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 3], 2);   // Bs 210 → 105 + 105

        $respuesta = $this->pagarConTarjeta(self::LUCIO, $pedido['id'], 1)->assertOk()->json();

        $this->assertSame('CONFIRMADO', $respuesta['pedido']['estado']);
        $this->assertEquals(105.0, $respuesta['pedido']['pagado'], 'No se debe marcar cobrado el total con una sola cuota.');
        $this->assertEquals(105.0, $respuesta['pedido']['saldo']);
        $this->assertNull($respuesta['pedido']['factura']);
        $this->assertSame(0, DB::table('factura')->where('pedido_id', $pedido['id'])->count());

        $plan = $this->plan($pedido['id']);
        $this->assertSame('ACTIVO', $plan['estado']);
        $this->assertSame('PAGADA', $plan['cuotas'][0]['estado']);
        $pago = DB::table('pago')->find($plan['cuotas'][0]['pago_id']);
        $this->assertSame('PAGADO', $pago->estado);
        $this->assertSame($pedido['id'], (int) $pago->pedido_id);
        $this->assertEquals(105.0, (float) $pago->monto);

        $historial = DB::table('historial_pedido')->where('pedido_id', $pedido['id'])->where('estado_nuevo', 'CONFIRMADO')->value('comentario');
        $this->assertStringContainsString('Cuota 1 de 2', $historial);
    }

    public function test_una_cuota_rechazada_o_un_qr_pendiente_no_confirman_el_pedido(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 3], 2);

        $this->pagar(self::LUCIO, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(self::TARJETA_SIN_FONDOS), 'cuota_numero' => 1])
            ->assertOk()->assertJsonPath('resultado', 'RECHAZADO');
        $this->assertSame('PENDIENTE', $this->estadoPedido($pedido['id']));

        $this->pagar(self::LUCIO, $pedido['id'], ['metodo' => 'QR', 'cuota_numero' => 1])->assertOk()->assertJsonPath('resultado', 'PENDIENTE');
        $this->assertSame('PENDIENTE', $this->estadoPedido($pedido['id']));
        $this->assertSame(['PENDIENTE', 'PENDIENTE'], array_column($this->plan($pedido['id'])['cuotas'], 'estado'));
        $this->assertEquals(0.0, (float) DB::table('pago')->where('pedido_id', $pedido['id'])->where('estado', 'PAGADO')->sum('monto'));
    }

    public function test_la_factura_se_emite_una_sola_vez_al_pagar_la_ultima_cuota_por_el_total_del_pedido(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 3], 2);
        $this->pagarConTarjeta(self::LUCIO, $pedido['id'], 1)->assertOk();

        $ultima = ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(), 'cuota_numero' => 2, 'clave_operacion' => $this->uuid()];
        $respuesta = $this->pagar(self::LUCIO, $pedido['id'], $ultima)->assertOk()->json();
        $this->pagar(self::LUCIO, $pedido['id'], $ultima)->assertOk();   // reintento del mismo envío

        $this->assertSame('COMPLETADO', $respuesta['pedido']['plan']['estado']);
        $this->assertEquals(0.0, $respuesta['pedido']['saldo']);
        $facturas = DB::table('factura')->where('pedido_id', $pedido['id'])->get();
        $this->assertCount(1, $facturas);
        $this->assertEquals(210.0, (float) $facturas[0]->total);
        $factura = $respuesta['pedido']['factura'];
        $this->assertSame((int) $facturas[0]->id, $factura['id']);
        $this->withToken($this->cliente(self::LUCIO))->getJson("/api/mis-facturas/{$factura['id']}")
            ->assertOk()->assertJsonPath('numero', $factura['numero']);
        $this->assertCount(2, $this->pagos($pedido['id'], 'PAGADO'));
    }

    public function test_un_plan_que_no_coincide_con_el_pedido_no_cobra_ni_confirma(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 3], 2);
        $fila = $this->filaPlan($pedido['id']);
        $original = $fila->parametros;

        $alteraciones = [
            'otro dueño' => fn () => DB::table('confirmacion_operacion')->where('id', $fila->id)->update(['usuario_id' => $this->idDe(self::MARCO)]),
            'cuotas que no suman el total' => fn () => DB::update(
                "UPDATE confirmacion_operacion SET parametros = jsonb_set(parametros, '{cuotas,0,monto}', '1'::jsonb) WHERE id = ?", [$fila->id]),
            'total distinto al del pedido' => fn () => DB::update(
                "UPDATE confirmacion_operacion SET parametros = jsonb_set(parametros, '{total}', '999'::jsonb) WHERE id = ?", [$fila->id]),
            'primera cuota marcada como pagada sin pago' => fn () => DB::update(
                "UPDATE confirmacion_operacion SET parametros = jsonb_set(jsonb_set(parametros, '{cuotas,0,estado}', '\"PAGADA\"'::jsonb), '{cuotas,0,pago_id}', '1'::jsonb) WHERE id = ?", [$fila->id]),
        ];

        foreach ($alteraciones as $caso => $alterar) {
            $alterar();
            $numero = $caso === 'primera cuota marcada como pagada sin pago' ? 2 : 1;

            $this->pagar(self::LUCIO, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(), 'cuota_numero' => $numero])
                ->assertStatus(409);
            $this->assertCount(0, $this->pagos($pedido['id']), "Se registró un pago con el plan alterado ({$caso}).");
            $this->assertSame('PENDIENTE', $this->estadoPedido($pedido['id']), "El pedido se confirmó con el plan alterado ({$caso}).");

            DB::table('confirmacion_operacion')->where('id', $fila->id)->update(['usuario_id' => $this->idDe(self::LUCIO), 'parametros' => $original]);
        }
    }

    public function test_un_qr_cuyo_importe_no_es_el_de_la_cuota_no_se_confirma(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 3], 2);
        $qr = $this->pagar(self::LUCIO, $pedido['id'], ['metodo' => 'QR', 'cuota_numero' => 1])->assertOk()->json('pago.id');
        // Se fuerza un importe distinto al de la cuota (por ejemplo, un pago registrado por otro canal).
        DB::table('pago')->where('id', $qr)->update(['monto' => '50.00']);

        $this->withToken($this->personal())->postJson("/api/gestion/pagos/{$qr}/confirmar")->assertStatus(409);

        $this->assertSame('PENDIENTE', DB::table('pago')->where('id', $qr)->value('estado'));
        $this->assertSame('PENDIENTE', $this->estadoPedido($pedido['id']));
        $this->assertSame('PENDIENTE', $this->plan($pedido['id'])['cuotas'][0]['estado']);
    }

    public function test_el_personal_confirma_el_qr_de_la_primera_cuota_y_recien_entonces_se_confirma_el_pedido(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 3], 2);
        $qr = $this->pagar(self::LUCIO, $pedido['id'], ['metodo' => 'QR', 'cuota_numero' => 1])->assertOk()->json('pago.id');

        $this->withToken($this->personal())->postJson("/api/gestion/pagos/{$qr}/confirmar")->assertOk()->assertJsonPath('estado', 'PAGADO');

        $this->assertSame('CONFIRMADO', $this->estadoPedido($pedido['id']));
        $plan = $this->plan($pedido['id']);
        $this->assertSame(['PAGADA', 'PENDIENTE'], array_column($plan['cuotas'], 'estado'));
        $this->assertSame($qr, $plan['cuotas'][0]['pago_id']);
        $this->assertSame(0, DB::table('factura')->where('pedido_id', $pedido['id'])->count());
    }
}
