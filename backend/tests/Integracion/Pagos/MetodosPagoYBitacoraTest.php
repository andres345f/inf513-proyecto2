<?php

namespace Tests\Integracion\Pagos;

use Illuminate\Support\Facades\DB;

/**
 * H-37 (reglas de las tarjetas guardadas; la carrera real entre dos altas se prueba con dos instancias HTTP) y
 * H-22 (bitácora con la acción nativa en pedidos, pagos, facturas y reembolsos hechos desde la web).
 */
class MetodosPagoYBitacoraTest extends PagosTestCase
{
    private const NUMEROS = ['4242 4242 4242 4242', '4111 1111 1111 1111', '5555 5555 5555 4444', '4012 8888 8888 1881', '3782 822463 10005', '6011 1111 1111 1117'];

    // ---- H-37 --------------------------------------------------------------------------------------------------

    public function test_hay_una_sola_tarjeta_predeterminada_y_como_maximo_cinco(): void
    {
        $token = $this->cliente(self::LUCIO);
        $this->darDeBajaTarjetas(self::LUCIO);

        foreach (array_slice(self::NUMEROS, 0, 5) as $numero) {
            $this->withToken($token)->postJson('/api/metodos-pago', $this->tarjeta($numero))->assertCreated();
        }
        $this->withToken($token)->postJson('/api/metodos-pago', $this->tarjeta(self::NUMEROS[5]))->assertStatus(422);

        $metodos = $this->withToken($token)->getJson('/api/metodos-pago')->assertOk()->json('metodos');
        $this->assertCount(5, $metodos);
        $this->assertCount(1, array_filter($metodos, fn ($m) => $m['predeterminado']));
        $this->assertTrue($metodos[0]['predeterminado'], 'La primera tarjeta guardada es la predeterminada.');
    }

    public function test_el_alta_bloquea_la_fila_del_usuario_antes_de_leer_sus_tarjetas(): void
    {
        $token = $this->cliente(self::LUCIO);
        $this->darDeBajaTarjetas(self::LUCIO);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->withToken($token)->postJson('/api/metodos-pago', $this->tarjeta())->assertCreated();

        $sentencias = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $bloqueo = $this->posicion($sentencias, 'from "usuario" where "id" = ? limit 1 for no key update');
        $lectura = $this->posicion($sentencias, '"comando" = ? and "usada_en" is null order by "creada_en"');
        $alta = $this->posicion($sentencias, 'insert into "confirmacion_operacion"');

        $this->assertNotNull($bloqueo, 'El alta no bloquea la fila del usuario.');
        $this->assertTrue($bloqueo < $lectura && $lectura < $alta, 'El orden debe ser: bloquear al usuario, leer sus tarjetas e insertar.');
    }

    public function test_al_eliminar_la_predeterminada_otra_ocupa_su_lugar_y_se_puede_cambiar(): void
    {
        $token = $this->cliente(self::LUCIO);
        $this->darDeBajaTarjetas(self::LUCIO);
        $primera = $this->withToken($token)->postJson('/api/metodos-pago', $this->tarjeta(self::NUMEROS[0]))->assertCreated()->json('id');
        $segunda = $this->withToken($token)->postJson('/api/metodos-pago', $this->tarjeta(self::NUMEROS[1]))->assertCreated()->json('id');
        $tercera = $this->withToken($token)->postJson('/api/metodos-pago', $this->tarjeta(self::NUMEROS[2]))->assertCreated()->json('id');

        $this->withToken($token)->postJson("/api/metodos-pago/{$tercera}/predeterminado")->assertOk();
        $this->assertSame([$tercera], $this->predeterminadas(self::LUCIO));

        $this->withToken($token)->deleteJson("/api/metodos-pago/{$tercera}")->assertOk();
        $this->assertSame([$primera], $this->predeterminadas(self::LUCIO));
        $this->assertNotContains($segunda, $this->predeterminadas(self::LUCIO));
    }

    // ---- H-22 --------------------------------------------------------------------------------------------------

    public function test_crear_pagar_y_facturar_un_pedido_dejan_bitacora_con_usuario_e_ip(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 1]);
        $pagoId = $this->pagarConTarjeta(self::LUCIO, $pedido['id'])->assertOk()->json('pago.id');
        $lucio = $this->idDe(self::LUCIO);

        $alta = $this->bitacora('PEDIDO', $pedido['id'])->first();
        $this->assertSame('CREAR', $alta->accion);
        $this->assertSame($lucio, (int) $alta->usuario_id);
        $this->assertNotNull($alta->direccion_ip);
        $this->assertSame($pedido['codigo'], $alta->detalle['codigo']);
        $this->assertEquals(70.0, $alta->detalle['total']);

        $pago = $this->bitacora('PAGO', $pagoId)->first();
        $this->assertSame('CREAR', $pago->accion);
        $this->assertSame('REGISTRAR_PAGO', $pago->detalle['operacion']);
        $this->assertSame('PAGADO', $pago->detalle['estado']);
        $this->assertSame($lucio, (int) $pago->usuario_id);

        $factura = DB::table('factura')->where('pedido_id', $pedido['id'])->first();
        $emision = $this->bitacora('FACTURA', (int) $factura->id)->first();
        $this->assertSame('CREAR', $emision->accion);
        $this->assertSame($factura->numero, $emision->detalle['numero']);
        $this->assertSame($lucio, (int) $emision->usuario_id);
    }

    public function test_un_pago_rechazado_y_la_cancelacion_del_cliente_tambien_quedan_registrados(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 1]);
        $rechazado = $this->pagar(self::LUCIO, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(self::TARJETA_SIN_FONDOS)])->assertOk()->json('pago.id');
        $this->withToken($this->cliente(self::LUCIO))->postJson("/api/mis-pedidos/{$pedido['id']}/cancelar", ['motivo' => 'Me equivoqué de producto'])->assertOk();

        $this->assertSame('RECHAZADO', $this->bitacora('PAGO', $rechazado)->first()->detalle['estado']);
        // Misma acción y operación que la cancelación por el personal (GestionPedidoService): ANULAR · CANCELAR.
        $cancelacion = $this->bitacora('PEDIDO', $pedido['id'])->firstWhere('detalle.operacion', 'CANCELAR');
        $this->assertNotNull($cancelacion, 'La cancelación del cliente no dejó bitácora.');
        $this->assertSame('ANULAR', $cancelacion->accion);
        $this->assertSame('CLIENTE', $cancelacion->detalle['cancelado_por']);
        $this->assertSame('CANCELADO', $cancelacion->detalle['estado_nuevo']);
        $this->assertSame('Me equivoqué de producto', $cancelacion->detalle['motivo']);
        $this->assertSame($this->idDe(self::LUCIO), (int) $cancelacion->usuario_id);
    }

    public function test_el_reembolso_de_una_devolucion_queda_en_bitacora_con_quien_lo_registro(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 1]);
        $this->pagarConTarjeta(self::LUCIO, $pedido['id'])->assertOk();
        $this->entregar($pedido['id']);
        $despacho = $this->despachoDe($pedido['id'], self::ACEITE_COPAIBO);
        $this->solicitarDevolucion(self::LUCIO, $despacho, 1)->assertCreated();
        $devolucion = $this->ultimaDevolucion($despacho);
        $this->aprobarDevolucion($devolucion, 'REEMBOLSO', 1, 1, 1)->assertOk();
        $this->completarDevolucion($devolucion)->assertOk();

        $reembolso = DB::table('reembolso')->where('devolucion_id', $devolucion)->first();
        $fila = $this->bitacora('REEMBOLSO', (int) $reembolso->id)->first();
        $this->assertSame('CREAR', $fila->accion);
        $this->assertSame($this->idDe(self::CARLA), (int) $fila->usuario_id);
        $this->assertEquals(70.0, $fila->detalle['monto']);
        $this->assertSame($devolucion, $fila->detalle['devolucion_id']);
    }

    public function test_todas_las_acciones_usadas_estan_en_la_lista_del_esquema(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 1]);
        $this->pagar(self::LUCIO, $pedido['id'], ['metodo' => 'QR'])->assertOk();
        $this->withToken($this->cliente(self::LUCIO))->postJson("/api/mis-pedidos/{$pedido['id']}/cancelar", ['motivo' => 'Prueba de bitácora'])->assertOk();

        $acciones = DB::table('bitacora')->whereRaw("detalle->>'canal' = 'WEB'")->whereIn('entidad', ['PEDIDO', 'PAGO', 'FACTURA', 'REEMBOLSO'])
            ->distinct()->pluck('accion')->all();

        $this->assertNotEmpty($acciones);
        $this->assertSame([], array_diff($acciones, ['CREAR', 'MODIFICAR', 'ANULAR']));
    }

    private function darDeBajaTarjetas(string $correo): void
    {
        DB::table('confirmacion_operacion')->where('usuario_id', $this->idDe($correo))->where('comando', 'METODO_PAGO')
            ->whereNull('usada_en')->update(['usada_en' => DB::raw('now()')]);
    }

    private function predeterminadas(string $correo): array
    {
        return DB::table('confirmacion_operacion')->where('usuario_id', $this->idDe($correo))->where('comando', 'METODO_PAGO')
            ->whereNull('usada_en')->whereRaw("(parametros->>'predeterminado')::boolean")->orderBy('id')->pluck('id')->all();
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
