<?php

namespace Tests\Integracion\Pagos;

use Illuminate\Support\Facades\DB;

/**
 * H-04 (reingreso min(apta, aprobada) y tope físico por despacho), H-43 (tope monetario por línea con reparto del
 * residuo de redondeo) y la parte secuencial de H-11 (los topes se releen al aprobar y al completar).
 * La carrera real entre dos peticiones se prueba con dos instancias HTTP.
 */
class DevolucionTopesTest extends PagosTestCase
{
    public function test_solo_reingresan_al_stock_las_unidades_aptas_que_ademas_se_aprobaron(): void
    {
        [$pedido, $despacho] = $this->pedidoEntregado(self::DANIELA, self::HARINA_PLATANO, 10);
        $lote = (int) DB::table('movimiento_inventario')->where('id', $despacho)->value('lote_feder_id');
        $stockAntes = (float) DB::table('lote_feder')->where('id', $lote)->value('cantidad_disponible');

        $this->solicitarDevolucion(self::DANIELA, $despacho, 10)->assertCreated();
        $devolucion = $this->ultimaDevolucion($despacho);
        $this->aprobarDevolucion($devolucion, 'REEMBOLSO', 10, 10, 1)->assertOk();

        $reingreso = (float) DB::table('movimiento_inventario')->where('devolucion_id', $devolucion)->where('tipo', 'ENTRADA_DEVOLUCION')->sum('cantidad');
        $this->assertEquals(1.0, $reingreso, 'Deben reingresar min(apta, aprobada) = 1 unidad.');
        $this->assertEquals($stockAntes + 1, (float) DB::table('lote_feder')->where('id', $lote)->value('cantidad_disponible'));
        $this->assertEquals(22.0, (float) DB::table('devolucion')->where('id', $devolucion)->value('importe_reembolsable'));
    }

    public function test_lo_recibido_fisicamente_ya_no_se_puede_volver_a_pedir_ni_reingresar(): void
    {
        [, $despacho] = $this->pedidoEntregado(self::DANIELA, self::HARINA_PLATANO, 10);
        $this->solicitarDevolucion(self::DANIELA, $despacho, 10)->assertCreated();
        $this->aprobarDevolucion($this->ultimaDevolucion($despacho), 'REEMBOLSO', 10, 10, 1)->assertOk();

        // Las 10 unidades ya volvieron a FEDER: el cliente no puede pedir devolver otras 9.
        $devolvibles = $this->withToken($this->cliente(self::DANIELA))->getJson('/api/mis-devoluciones')->assertOk()->json('devolvibles');
        $this->assertNotContains($despacho, array_column($devolvibles, 'despacho_id'));
        $this->solicitarDevolucion(self::DANIELA, $despacho, 9)->assertStatus(422);

        $reingresado = (float) DB::table('movimiento_inventario as m')->join('devolucion as d', 'd.id', '=', 'm.devolucion_id')
            ->where('d.movimiento_inventario_id', $despacho)->where('m.tipo', 'ENTRADA_DEVOLUCION')->sum('m.cantidad');
        $this->assertLessThanOrEqual(10.0, $reingresado);
    }

    public function test_al_aprobar_se_vuelve_a_comprobar_el_tope_del_despacho(): void
    {
        [, $despacho] = $this->pedidoEntregado(self::DANIELA, self::HARINA_PLATANO, 10);
        $this->solicitarDevolucion(self::DANIELA, $despacho, 6)->assertCreated();
        $primera = $this->ultimaDevolucion($despacho);
        // Estado que dejaría una carrera en la versión anterior: una segunda solicitud que, sumada, supera lo despachado.
        DB::table('devolucion')->insert(['movimiento_inventario_id' => $despacho, 'codigo' => 'DEV-PRUEBA-TOPE', 'cantidad' => 6,
            'motivo' => 'Solicitud en exceso', 'estado' => 'SOLICITADA']);
        $segunda = $this->ultimaDevolucion($despacho);

        // Entre las dos piden 12 de 10 despachadas: no se pueden recibir 6 de cada una.
        $this->aprobarDevolucion($primera, 'REPOSICION', 6, 6, 6)->assertStatus(422);
        $this->aprobarDevolucion($primera, 'REPOSICION', 4, 4, 4)->assertOk();
        $this->aprobarDevolucion($segunda, 'REPOSICION', 6, 6, 6)->assertOk();

        $reingresado = (float) DB::table('movimiento_inventario as m')->join('devolucion as d', 'd.id', '=', 'm.devolucion_id')
            ->where('d.movimiento_inventario_id', $despacho)->where('m.tipo', 'ENTRADA_DEVOLUCION')->sum('m.cantidad');
        $this->assertEquals(10.0, $reingresado);
    }

    public function test_los_importes_de_varias_devoluciones_de_una_linea_suman_exactamente_su_subtotal(): void
    {
        DB::table('producto')->where('id', self::MIEL_GRANEL)->update(['precio_venta' => '16.67']);
        [$pedido, $despacho] = $this->pedidoEntregado(self::DANIELA, self::MIEL_GRANEL, 0.003);   // línea de Bs 0,05
        $this->assertEquals(0.05, $pedido['total']);

        $importes = [];
        foreach ([1, 2, 3] as $n) {
            $this->solicitarDevolucion(self::DANIELA, $despacho, 0.001)->assertCreated();
            $devolucion = $this->ultimaDevolucion($despacho);
            $this->aprobarDevolucion($devolucion, 'REEMBOLSO', 0.001, 0, 0.001)->assertOk();
            $this->completarDevolucion($devolucion)->assertOk();
            $importes[] = (float) DB::table('devolucion')->where('id', $devolucion)->value('importe_reembolsable');
        }

        $this->assertSame([0.02, 0.01, 0.02], $importes, 'Cada importe es el acumulado redondeado menos lo ya atribuido.');
        $pagoId = (int) DB::table('pago')->where('pedido_id', $pedido['id'])->where('estado', 'PAGADO')->value('id');
        $this->assertEquals(0.05, (float) DB::table('reembolso')->where('pago_id', $pagoId)->sum('monto'), 'Lo reintegrado debe igualar el subtotal de la línea.');
    }

    public function test_un_reintegro_nunca_supera_lo_que_queda_de_cada_pago(): void
    {
        [$pedido, $despacho] = $this->pedidoEntregado(self::DANIELA, self::ACEITE_COPAIBO, 1);   // un pago de Bs 70
        $this->solicitarDevolucion(self::DANIELA, $despacho, 1)->assertCreated();
        $legitima = $this->ultimaDevolucion($despacho);
        $this->aprobarDevolucion($legitima, 'REEMBOLSO', 1, 0, 1)->assertOk();
        // Estado incoherente provocado a propósito (como el que dejaba la versión anterior): otra devolución aprobada
        // por el mismo importe sobre el mismo pago.
        DB::table('devolucion')->insert(['movimiento_inventario_id' => $despacho, 'codigo' => 'DEV-PRUEBA-PAGO', 'cantidad' => 1,
            'motivo' => 'Aprobación en exceso', 'estado' => 'APROBADA', 'resolucion' => 'REEMBOLSO', 'cantidad_aprobada' => 1,
            'importe_reembolsable' => '70.00', 'fecha_resolucion' => DB::raw('now()')]);
        $excedente = $this->ultimaDevolucion($despacho);

        $this->completarDevolucion($legitima)->assertOk();
        $this->completarDevolucion($excedente)->assertStatus(422);

        $pagoId = (int) DB::table('pago')->where('pedido_id', $pedido['id'])->value('id');
        $this->assertEquals(70.0, (float) DB::table('reembolso')->where('pago_id', $pagoId)->where('estado', '<>', 'FALLIDO')->sum('monto'));
        $this->assertSame('APROBADA', DB::table('devolucion')->where('id', $excedente)->value('estado'));
    }

    public function test_sin_plan_no_se_aprueba_un_reembolso_mayor_que_lo_cobrado(): void
    {
        // Pedido contra entrega entregado sin registrar el cobro: no hay nada pagado que reintegrar.
        $pedido = $this->crearPedido(self::DANIELA, [self::ACEITE_COPAIBO => 1]);
        $this->pagar(self::DANIELA, $pedido['id'], ['metodo' => 'EFECTIVO'])->assertOk();
        $this->entregar($pedido['id']);
        $despacho = $this->despachoDe($pedido['id'], self::ACEITE_COPAIBO);
        $this->solicitarDevolucion(self::DANIELA, $despacho, 1)->assertCreated();
        $devolucion = $this->ultimaDevolucion($despacho);

        $this->aprobarDevolucion($devolucion, 'REEMBOLSO', 1, 1, 1)->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'superaría lo cobrado'));
        $this->assertSame('SOLICITADA', DB::table('devolucion')->where('id', $devolucion)->value('estado'));

        $this->aprobarDevolucion($devolucion, 'REPOSICION', 1, 1, 1)->assertOk();
    }

    public function test_el_orden_de_bloqueo_empieza_por_el_pedido_y_las_sumas_se_leen_despues(): void
    {
        [, $despacho] = $this->pedidoEntregado(self::DANIELA, self::HARINA_PLATANO, 2);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->solicitarDevolucion(self::DANIELA, $despacho, 1)->assertCreated();

        $sentencias = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $bloqueoPedido = $this->posicion($sentencias, 'from "pedido" where "id" = ? limit 1 for update');
        $bloqueoDespacho = $this->posicion($sentencias, 'from "movimiento_inventario" where "id" = ? limit 1 for update');
        $alta = $this->posicion($sentencias, 'insert into "devolucion"');
        $lecturaDeSumas = null;
        foreach ($sentencias as $i => $sql) {
            if ($i > $bloqueoDespacho && $i < $alta && str_contains($sql, 'FROM devolucion d') && ! str_contains($sql, 'for update')) {
                $lecturaDeSumas = $i;
            }
        }

        $this->assertNotNull($bloqueoPedido, 'No se bloquea la fila del pedido.');
        $this->assertNotNull($bloqueoDespacho, 'No se bloquea la fila del despacho.');
        $this->assertTrue($bloqueoPedido < $bloqueoDespacho, 'Primero el pedido y después el despacho.');
        $this->assertNotNull($lecturaDeSumas, 'Lo ya devuelto debe leerse en una sentencia posterior a los bloqueos, sin bloquear en ella.');
    }

    /** @return array{0: array, 1: int} pedido pagado y entregado, y el id de su despacho */
    private function pedidoEntregado(string $correo, int $productoId, float|int $cantidad): array
    {
        $pedido = $this->crearPedido($correo, [$productoId => $cantidad]);
        $this->pagarConTarjeta($correo, $pedido['id'])->assertOk();
        $this->entregar($pedido['id']);

        return [$pedido, $this->despachoDe($pedido['id'], $productoId)];
    }

    private function posicion(array $sentencias, string $fragmento): ?int
    {
        foreach ($sentencias as $i => $sql) {
            if (str_contains(mb_strtolower($sql), mb_strtolower($fragmento))) {
                return $i;
            }
        }

        return null;
    }
}
