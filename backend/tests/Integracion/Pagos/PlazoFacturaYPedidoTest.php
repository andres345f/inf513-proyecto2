<?php

namespace Tests\Integracion\Pagos;

use Illuminate\Support\Facades\DB;

/**
 * H-20 (plazo de 48 h comprobado al pagar, también para encargos pendientes o aceptados sin cobro, con el mismo
 * criterio que la tarea feder:vencer-pedidos),
 * H-23 (número de factura con el id de la factura, como el Proyecto 1) y H-38 (no se crea un pedido sin importe).
 */
class PlazoFacturaYPedidoTest extends PagosTestCase
{
    // ---- H-20 --------------------------------------------------------------------------------------------------

    public function test_no_se_puede_pagar_un_pedido_con_el_plazo_vencido_aunque_no_haya_corrido_el_programador(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 1]);
        $this->vencerReservas($pedido['id']);

        foreach ([['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta()], ['metodo' => 'QR'], ['metodo' => 'EFECTIVO']] as $cuerpo) {
            $this->pagar(self::LUCIO, $pedido['id'], $cuerpo)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '48 horas'));
        }

        $this->assertCount(0, $this->pagos($pedido['id']));
        $this->assertSame('PENDIENTE', $this->estadoPedido($pedido['id']));
        $detalle = $this->withToken($this->cliente(self::LUCIO))->getJson("/api/mis-pedidos/{$pedido['id']}")->assertOk()->json();
        $this->assertFalse($detalle['puede_pagar']);
        $this->assertTrue($detalle['plazo_pago_vencido']);
        $this->assertTrue($detalle['puede_cancelar'], 'El cliente debe poder cancelar el pedido vencido.');
    }

    public function test_tampoco_la_primera_cuota_de_un_plan_con_el_plazo_vencido(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 3], 2);
        $this->vencerReservas($pedido['id']);

        $this->pagarConTarjeta(self::LUCIO, $pedido['id'], 1)->assertStatus(422);

        $this->assertCount(0, $this->pagos($pedido['id']));
        $this->assertSame('PENDIENTE', $this->plan($pedido['id'])['cuotas'][0]['estado']);
    }

    public function test_dentro_del_plazo_se_paga_y_las_cuotas_siguientes_no_dependen_de_ese_plazo(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 3], 2);
        $this->pagarConTarjeta(self::LUCIO, $pedido['id'], 1)->assertOk();

        // Con la primera cuota pagada las reservas ya no vencen; la segunda cuota se paga cuando toque.
        $this->assertSame(0, DB::table('reserva_stock as rs')->join('detalle_pedido as dp', 'dp.id', '=', 'rs.detalle_pedido_id')
            ->where('dp.pedido_id', $pedido['id'])->whereNotNull('rs.fecha_expiracion')->count());
        $this->pagarConTarjeta(self::LUCIO, $pedido['id'], 2)->assertOk();
    }

    /**
     * Política de encargos (la misma que aplica la tarea feder:vencer-pedidos): un encargo PENDIENTE sin ningún pago
     * vence a las 48 h de creado. Al pagar se comprueba igual, aunque el programador no haya corrido.
     */
    public function test_un_encargo_pendiente_de_mas_de_48_horas_tampoco_se_cobra(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_TOTAI_100 => 2], null, 'ENCARGO');
        $this->envejecer($pedido['id'], 49);

        foreach ([['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta()], ['metodo' => 'QR'], ['metodo' => 'EFECTIVO']] as $cuerpo) {
            $this->pagar(self::LUCIO, $pedido['id'], $cuerpo)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '48 horas'));
        }

        $this->assertCount(0, $this->pagos($pedido['id']));
        $detalle = $this->withToken($this->cliente(self::LUCIO))->getJson("/api/mis-pedidos/{$pedido['id']}")->assertOk()->json();
        $this->assertFalse($detalle['puede_pagar']);
        $this->assertTrue($detalle['plazo_pago_vencido']);
        $this->assertTrue($detalle['puede_cancelar']);
    }

    public function test_un_encargo_pendiente_dentro_del_plazo_si_se_cobra(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_TOTAI_100 => 2], null, 'ENCARGO');
        $this->envejecer($pedido['id'], 47);

        $this->pagarConTarjeta(self::LUCIO, $pedido['id'])->assertOk()->assertJsonPath('resultado', 'PAGADO');
        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->estadoPedido($pedido['id']));
    }

    /*
     * Encargo ya aceptado (ESPERANDO_ABASTECIMIENTO) sin ningún pago, como el pedido 8 de la semilla o los que el
     * personal acepta en el Proyecto 1 antes de cobrar. Una sola regla con la tarea feder:vencer-pedidos (ciclo K1,
     * hallazgo 6 de la revisión intermedia): sin ningún pago PAGADO ni efectivo contra entrega pendiente, vence a las
     * 48 h de creado. Dentro del plazo se puede pagar; después ya no se cobra, aunque el programador no haya corrido.
     */

    /**
     * Pedido 8 de los datos iniciales (encargo del 8 de noviembre en ESPERANDO_ABASTECIMIENTO, Bs 1080, sin cobro).
     * Con la semilla de esta rama no tiene ningún pago, así que su plazo de 48 h ya venció; con la semilla del ciclo K3
     * (9f52d2d) tiene efectivo contra entrega aceptado. En los dos casos no se cobra en línea y pagar no cambia nada.
     */
    public function test_el_encargo_8_de_la_semilla_no_se_cobra_en_linea(): void
    {
        $pedido = DB::table('pedido')->where('codigo', 'PED-2026-0008')->first();
        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $pedido->estado);
        $this->assertSame($this->idDe(self::DANIELA), (int) $pedido->usuario_id);
        $this->assertCount(0, $this->pagos($pedido->id, 'PAGADO'));
        $pagosAntes = $this->pagos($pedido->id)->count();
        $conEfectivo = $this->pagos($pedido->id, 'PENDIENTE')->contains('metodo', 'EFECTIVO');

        $detalle = $this->withToken($this->cliente(self::DANIELA))->getJson("/api/mis-pedidos/{$pedido->id}")->assertOk()->json();
        $this->assertFalse($detalle['puede_pagar']);
        $this->assertSame(! $conEfectivo, $detalle['plazo_pago_vencido'], 'Vence a las 48 h salvo que tenga efectivo contra entrega aceptado.');

        foreach ([['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta()], ['metodo' => 'QR']] as $cuerpo) {
            $this->pagar(self::DANIELA, $pedido->id, $cuerpo)->assertStatus(422);
        }
        $this->assertCount($pagosAntes, $this->pagos($pedido->id));
        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->estadoPedido($pedido->id));
    }

    public function test_un_encargo_aceptado_sin_cobro_dentro_del_plazo_si_se_paga(): void
    {
        $pedido = $this->encargoAceptadoSinCobro(47);

        $detalle = $this->withToken($this->cliente(self::LUCIO))->getJson("/api/mis-pedidos/{$pedido}")->assertOk()->json();
        $this->assertTrue($detalle['puede_pagar']);
        $this->assertFalse($detalle['plazo_pago_vencido']);

        $this->pagarConTarjeta(self::LUCIO, $pedido)->assertOk()->assertJsonPath('resultado', 'PAGADO')->assertJsonPath('pedido.puede_pagar', false);
        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->estadoPedido($pedido), 'Pagar no adelanta el abastecimiento.');
        $this->assertSame(1, DB::table('factura')->where('pedido_id', $pedido)->count());
    }

    public function test_un_encargo_aceptado_sin_cobro_de_mas_de_48_horas_no_se_cobra(): void
    {
        $pedido = $this->encargoAceptadoSinCobro(49);

        $this->pagarConTarjeta(self::LUCIO, $pedido)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '48 horas'));
        $this->assertCount(0, $this->pagos($pedido));
        $this->assertTrue($this->withToken($this->cliente(self::LUCIO))->getJson("/api/mis-pedidos/{$pedido}")->json('plazo_pago_vencido'));
    }

    public function test_un_encargo_con_efectivo_contra_entrega_no_ofrece_otro_pago_y_no_vence(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_TOTAI_100 => 2], null, 'ENCARGO');
        $this->pagar(self::LUCIO, $pedido['id'], ['metodo' => 'EFECTIVO'])->assertOk()->assertJsonPath('resultado', 'PENDIENTE');
        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->estadoPedido($pedido['id']));

        $this->assertFalse($this->withToken($this->cliente(self::LUCIO))->getJson("/api/mis-pedidos/{$pedido['id']}")->json('puede_pagar'));
        $this->pagarConTarjeta(self::LUCIO, $pedido['id'])->assertStatus(422);
        $this->assertCount(1, $this->pagos($pedido['id']));

        // Con el efectivo contra entrega ya aceptado el encargo no vence (tampoco lo cancela la tarea).
        $this->envejecer($pedido['id'], 100);
        $this->assertFalse($this->withToken($this->cliente(self::LUCIO))->getJson("/api/mis-pedidos/{$pedido['id']}")->json('plazo_pago_vencido'));
    }

    // ---- H-23 --------------------------------------------------------------------------------------------------

    public function test_la_factura_se_numera_con_su_propio_id_y_no_con_el_del_pedido(): void
    {
        // Se consumen tres números de la secuencia de pedidos para que el id del pedido y el de la factura no coincidan
        // por casualidad (equivale a tres pedidos creados antes; no cambia ningún dato).
        DB::select("SELECT nextval(pg_get_serial_sequence('pedido', 'id')) FROM generate_series(1, 3)");
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 1]);

        $this->pagarConTarjeta(self::LUCIO, $pedido['id'])->assertOk();

        $factura = DB::table('factura')->where('pedido_id', $pedido['id'])->first();
        $this->assertNotSame((int) $factura->id, $pedido['id']);
        $this->assertSame(sprintf('FAC-%s-%06d', now()->format('Y'), $factura->id), $factura->numero);
        $this->assertEquals((float) $pedido['total'], (float) $factura->total);
    }

    public function test_si_el_numero_ya_existe_por_una_numeracion_antigua_se_toma_el_siguiente_libre(): void
    {
        // Factura antigua numerada con el id del pedido: ocupa el número que le tocaría a la próxima factura.
        $siguiente = (int) DB::selectOne("SELECT COALESCE(pg_sequence_last_value(pg_get_serial_sequence('factura', 'id')::regclass), 0) + 1 AS n")->n;
        $ocupado = sprintf('FAC-%s-%06d', now()->format('Y'), $siguiente);
        $pendiente = DB::table('pedido')->where('codigo', 'PED-2026-0011')->first();
        DB::table('factura')->insert(['id' => 900000, 'pedido_id' => $pendiente->id, 'numero' => $ocupado, 'fecha_emision' => now(),
            'nit_ci' => '1234567', 'razon_social' => 'Factura antigua', 'total' => $pendiente->total, 'estado' => 'VIGENTE']);

        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 1]);
        $this->pagarConTarjeta(self::LUCIO, $pedido['id'])->assertOk();

        $factura = DB::table('factura')->where('pedido_id', $pedido['id'])->first();
        $this->assertNotSame($ocupado, $factura->numero);
        $this->assertSame(sprintf('FAC-%s-%06d', now()->format('Y'), $factura->id), $factura->numero);
    }

    // ---- H-38 --------------------------------------------------------------------------------------------------

    public function test_no_se_crea_un_pedido_cuyo_importe_redondea_a_cero(): void
    {
        DB::table('producto')->where('id', self::MIEL_GRANEL)->update(['precio_venta' => '0.01']);
        $token = $this->cliente(self::LUCIO);
        DB::table('item_carrito')->where('cliente_id', $this->idDe(self::LUCIO))->delete();
        $this->withToken($token)->postJson('/api/carrito', ['producto_id' => self::MIEL_GRANEL, 'cantidad' => 0.001])->assertOk();
        $pedidosAntes = DB::table('pedido')->count();

        $this->withToken($token)->postJson('/api/pedidos', ['tipo' => 'INMEDIATO', 'modalidad' => 'RECOJO', 'nombre_receptor' => 'Cliente de Prueba',
            'telefono_contacto' => '70012345', 'forma_pago' => 'UNICO'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Bs 0,00'));

        $this->assertSame($pedidosAntes, DB::table('pedido')->count());
        $this->assertSame(1, DB::table('item_carrito')->where('cliente_id', $this->idDe(self::LUCIO))->count(), 'El carrito debe conservarse.');
        $this->assertSame(0, DB::table('reserva_stock')->whereRaw("fecha_creacion > now() - interval '1 minute'")->count());
    }

    public function test_un_importe_pequeno_pero_cobrable_si_se_acepta(): void
    {
        DB::table('producto')->where('id', self::MIEL_GRANEL)->update(['precio_venta' => '16.67']);

        $pedido = $this->crearPedido(self::LUCIO, [self::MIEL_GRANEL => 0.003]);

        $this->assertEquals(0.05, $pedido['total']);
        $this->pagarConTarjeta(self::LUCIO, $pedido['id'])->assertOk()->assertJsonPath('resultado', 'PAGADO');
    }

    /** Encargo que el personal aceptó sin cobro (ESPERANDO_ABASTECIMIENTO, sin pagos), creado hace $horas. */
    private function encargoAceptadoSinCobro(int $horas): int
    {
        $pedido = (int) $this->crearPedido(self::LUCIO, [self::ACEITE_TOTAI_100 => 2], null, 'ENCARGO')['id'];
        DB::update("UPDATE pedido SET estado = 'ESPERANDO_ABASTECIMIENTO' WHERE id = ?", [$pedido]);
        DB::table('historial_pedido')->insert(['pedido_id' => $pedido, 'usuario_id' => $this->idDe(self::CARLA),
            'estado_anterior' => 'PENDIENTE', 'estado_nuevo' => 'ESPERANDO_ABASTECIMIENTO', 'comentario' => 'Encargo aceptado sin pago (prueba)']);
        $this->envejecer($pedido, $horas);

        return $pedido;
    }

    /** Retrasa la creación del pedido (y el vencimiento de sus reservas, si las tiene) como si hubieran pasado $horas. */
    private function envejecer(int $pedidoId, int $horas): void
    {
        DB::update('UPDATE pedido SET fecha_creacion = now() - make_interval(hours => ?) WHERE id = ?', [$horas, $pedidoId]);
        DB::update("UPDATE reserva_stock SET fecha_expiracion = now() - make_interval(hours => ?) + make_interval(hours => 48)
                    WHERE estado = 'ACTIVA' AND detalle_pedido_id IN (SELECT id FROM detalle_pedido WHERE pedido_id = ?)", [$horas, $pedidoId]);
    }

    private function vencerReservas(int $pedidoId): void
    {
        $filas = DB::update("UPDATE reserva_stock SET fecha_expiracion = now() - interval '1 hour'
                              WHERE estado = 'ACTIVA' AND detalle_pedido_id IN (SELECT id FROM detalle_pedido WHERE pedido_id = ?)", [$pedidoId]);
        $this->assertGreaterThan(0, $filas);
    }
}
