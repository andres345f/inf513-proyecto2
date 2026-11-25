<?php

namespace Tests\Integracion\Pagos;

use Illuminate\Support\Facades\DB;

/**
 * H-07: una cuenta del personal que también es cliente no confirma ni rechaza el pago de su propio pedido, no aprueba
 * su propia devolución ni registra su propio reembolso. Se comprueba por identidad de cuenta (usuario_id), así que
 * cambiar de rol o abrir otra sesión no lo evita. Otro miembro del personal sí puede hacerlo.
 */
class SeparacionFuncionesPagosTest extends PagosTestCase
{
    public function test_no_confirma_ni_rechaza_el_pago_de_su_propio_pedido_con_otra_sesion(): void
    {
        $pedido = $this->crearPedido(self::JORGE, [self::ACEITE_COPAIBO => 1]);
        $qr = $this->pagar(self::JORGE, $pedido['id'], ['metodo' => 'QR'])->assertOk()->json('pago.id');
        $comoPersonal = $this->personal(self::JORGE);   // segunda sesión de la misma cuenta, con el rol PERSONAL_FEDER

        $this->withToken($comoPersonal)->postJson("/api/gestion/pagos/{$qr}/confirmar")
            ->assertStatus(403)->assertJsonPath('message', fn ($m) => str_contains($m, $pedido['codigo']));
        $this->withToken($comoPersonal)->postJson("/api/gestion/pagos/{$qr}/rechazar", ['motivo' => 'No llegó el dinero'])->assertStatus(403);

        $this->assertSame('PENDIENTE', DB::table('pago')->where('id', $qr)->value('estado'));
        $this->assertSame('PENDIENTE', $this->estadoPedido($pedido['id']));
        $this->assertSame(0, DB::table('factura')->where('pedido_id', $pedido['id'])->count());
    }

    public function test_tampoco_cambiando_de_rol_en_la_misma_sesion(): void
    {
        $pedido = $this->crearPedido(self::JORGE, [self::ACEITE_COPAIBO => 1]);
        $sesion = $this->cliente(self::JORGE);
        $qr = $this->withToken($sesion)->postJson("/api/mis-pedidos/{$pedido['id']}/pagar", ['metodo' => 'QR'])->assertOk()->json('pago.id');

        $rolPersonal = (int) DB::table('rol')->where('codigo', 'PERSONAL_FEDER')->value('id');
        $this->withToken($sesion)->postJson('/api/auth/rol-activo', ['rol_id' => $rolPersonal])->assertOk();

        $this->withToken($sesion)->postJson("/api/gestion/pagos/{$qr}/confirmar")->assertStatus(403);
        $this->assertSame('PENDIENTE', DB::table('pago')->where('id', $qr)->value('estado'));
    }

    public function test_otro_miembro_del_personal_si_confirma_ese_pago(): void
    {
        $pedido = $this->crearPedido(self::JORGE, [self::ACEITE_COPAIBO => 1]);
        $qr = $this->pagar(self::JORGE, $pedido['id'], ['metodo' => 'QR'])->assertOk()->json('pago.id');

        $this->withToken($this->personal(self::CARLA))->postJson("/api/gestion/pagos/{$qr}/confirmar")->assertOk();

        $this->assertSame('PAGADO', DB::table('pago')->where('id', $qr)->value('estado'));
        $this->assertSame('CONFIRMADO', $this->estadoPedido($pedido['id']));
        $fila = $this->bitacora('PAGO', $qr)->last();
        $this->assertSame($this->idDe(self::CARLA), (int) $fila->usuario_id);
        $this->assertSame('CONFIRMAR_PAGO', $fila->detalle['operacion']);
    }

    public function test_no_aprueba_su_propia_devolucion_ni_registra_su_propio_reembolso(): void
    {
        $pedido = $this->crearPedido(self::JORGE, [self::ACEITE_COPAIBO => 1]);
        $this->pagarConTarjeta(self::JORGE, $pedido['id'])->assertOk();
        $this->entregar($pedido['id']);
        $despacho = $this->despachoDe($pedido['id'], self::ACEITE_COPAIBO);
        $this->solicitarDevolucion(self::JORGE, $despacho, 1)->assertCreated();
        $devolucion = $this->ultimaDevolucion($despacho);

        $this->aprobarDevolucion($devolucion, 'REEMBOLSO', 1, 1, 1, self::JORGE)->assertStatus(403);
        $this->assertSame('SOLICITADA', DB::table('devolucion')->where('id', $devolucion)->value('estado'));

        $this->aprobarDevolucion($devolucion, 'REEMBOLSO', 1, 1, 1, self::CARLA)->assertOk();
        $this->completarDevolucion($devolucion, self::JORGE)->assertStatus(403);
        $this->assertSame(0, DB::table('reembolso')->where('devolucion_id', $devolucion)->count());

        $this->completarDevolucion($devolucion, self::CARLA)->assertOk();
        $this->assertEquals(70.0, (float) DB::table('reembolso')->where('devolucion_id', $devolucion)->sum('monto'));
    }

    public function test_la_reposicion_de_un_pedido_propio_no_es_uno_de_los_actos_restringidos(): void
    {
        $pedido = $this->crearPedido(self::JORGE, [self::ACEITE_COPAIBO => 1]);
        $this->pagarConTarjeta(self::JORGE, $pedido['id'])->assertOk();
        $this->entregar($pedido['id']);
        $despacho = $this->despachoDe($pedido['id'], self::ACEITE_COPAIBO);
        $this->solicitarDevolucion(self::JORGE, $despacho, 1)->assertCreated();
        $devolucion = $this->ultimaDevolucion($despacho);

        // Aprobar sigue restringido; entregar la reposición ya aprobada por otra persona no lo está.
        $this->aprobarDevolucion($devolucion, 'REPOSICION', 1, 1, 1, self::JORGE)->assertStatus(403);
        $this->aprobarDevolucion($devolucion, 'REPOSICION', 1, 1, 1, self::CARLA)->assertOk();
        $this->completarDevolucion($devolucion, self::JORGE)->assertOk();
    }
}
