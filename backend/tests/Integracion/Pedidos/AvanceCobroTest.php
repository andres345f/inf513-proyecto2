<?php

namespace Tests\Integracion\Pedidos;

use App\Models\ConfirmacionOperacion;
use Illuminate\Support\Facades\DB;

/**
 * H-28: «avanzar» lleva el pedido solo al estado que el personal vio en pantalla y exige el cobro resuelto
 * (reglas R047 y R057), con la excepción documentada del plan de cuotas (PROMPT 7.5) y del efectivo contra entrega.
 * La concurrencia real (dos peticiones a la vez contra dos instancias) se prueba aparte, por HTTP.
 */
class AvanceCobroTest extends PedidosTestCase
{
    private function productoConStock(float $precio = 50.0, float $stock = 50): int
    {
        $producto = $this->crearProducto(['precio_venta' => number_format($precio, 2, '.', '')]);
        $this->crearLoteFeder($producto, $stock, null, '2026-10-01 09:00:00');

        return $producto;
    }

    // ------------------------------------------------------------------ transición por estado esperado

    public function test_un_avance_repetido_con_el_mismo_destino_no_salta_al_estado_siguiente(): void
    {
        $pedido = $this->pedidoPagado(self::CLIENTE, [$this->productoConStock() => 2]);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'EN_PREPARACION'])->assertOk()->assertJsonPath('estado', 'EN_PREPARACION');
        $repetido = $this->avanzar($this->admin(), $pedido, ['hacia' => 'EN_PREPARACION']);

        $repetido->assertStatus(409);
        $this->assertSame('EN_PREPARACION', $this->pedido($pedido)->estado);
        $this->assertCount(0, $this->salidas($pedido), 'El segundo «preparar» no puede despachar.');
        $this->assertSame(1, $this->historial($pedido)->where('estado_nuevo', 'EN_PREPARACION')->count());
    }

    public function test_avanzar_sin_indicar_el_destino_se_rechaza_en_las_dos_rutas(): void
    {
        $pedido = $this->pedidoPagado(self::CLIENTE, [$this->productoConStock() => 2]);

        foreach (['pedidos', 'entregas'] as $ruta) {
            $respuesta = $this->avanzar($this->personal(), $pedido, [], $ruta);

            $respuesta->assertStatus(422)->assertJsonValidationErrors('hacia');
            $this->assertStringNotContainsString('The ', json_encode($respuesta->json('errors'), JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);
        $this->assertSame(0, $this->historial($pedido)->where('estado_nuevo', 'EN_PREPARACION')->count());
    }

    public function test_una_pantalla_antigua_no_ejecuta_otra_transicion_aunque_pasen_unos_segundos(): void
    {
        // Dos pestañas con el mismo pedido pagado y con RECOJO: A lo prepara y, cuatro segundos después, B pulsa «Preparar».
        $pedido = $this->pedidoPagado(self::CLIENTE, [$this->productoConStock() => 2]);
        $this->avanzar($this->personal(), $pedido, ['hacia' => 'EN_PREPARACION'])->assertOk();
        DB::update("UPDATE historial_pedido SET fecha = fecha - interval '4 seconds' WHERE pedido_id = ?", [$pedido]);

        $sinDestino = $this->avanzar($this->admin(), $pedido);
        $pantallaAntigua = $this->avanzar($this->admin(), $pedido, ['hacia' => 'EN_PREPARACION'], 'entregas');

        $sinDestino->assertStatus(422)->assertJsonValidationErrors('hacia');
        $pantallaAntigua->assertStatus(409);
        $this->assertStringContainsString('Recarga la página', $pantallaAntigua->json('message'));
        $this->assertSame('EN_PREPARACION', $this->pedido($pedido)->estado);
        $this->assertSame('PENDIENTE', $this->entrega($pedido)->estado);
        $this->assertCount(0, $this->salidas($pedido), 'La pestaña antigua no puede despachar ni consumir inventario.');
    }

    public function test_el_destino_debe_ser_un_estado_de_avance_valido(): void
    {
        $pedido = $this->pedidoPagado(self::CLIENTE, [$this->productoConStock() => 2]);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'CANCELADO'])->assertStatus(422)->assertJsonValidationErrors('hacia');
        $this->avanzar($this->personal(), $pedido, ['hacia' => 'DESPACHADO'])->assertStatus(409);
        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);
    }

    public function test_un_pedido_pendiente_o_cancelado_no_avanza(): void
    {
        $pedido = $this->pedidoDe(self::CLIENTE, [$this->productoConStock() => 2]);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'EN_PREPARACION'])->assertStatus(409);
        $this->avanzar($this->personal(), $pedido)->assertStatus(422);
        $this->assertSame('PENDIENTE', $this->pedido($pedido)->estado);
    }

    // ------------------------------------------------------------------ control de cobro

    public function test_pedido_pagado_por_completo_avanza_hasta_entregado(): void
    {
        $pedido = $this->pedidoPagado(self::CLIENTE, [$this->productoConStock() => 2]);

        $this->avanzarHasta($pedido, 'EN_PREPARACION', 'DESPACHADO', 'ENTREGADO');

        $this->assertSame('ENTREGADO', $this->pedido($pedido)->estado);
        $this->assertSame('ENTREGADA', $this->entrega($pedido)->estado);
        $this->assertNotNull($this->entrega($pedido)->fecha_entrega);
    }

    public function test_no_prepara_un_pedido_confirmado_sin_cobro(): void
    {
        // Pedido CONFIRMADO sin ningún cobro (dato forzado). Antes se llegaba aquí abasteciendo un encargo impago, pero
        // eso ya no se permite (ciclo de verificación del hallazgo 6, ver VencimientoPedidosTest). El control de cobro
        // al preparar sigue siendo la última barrera para datos así.
        $pedido = $this->pedidoDe(self::CLIENTE, [$this->productoConStock(90.0) => 12]);
        DB::update("UPDATE pedido SET estado = 'CONFIRMADO' WHERE id = ?", [$pedido]);
        DB::update('UPDATE reserva_stock SET fecha_expiracion = NULL WHERE detalle_pedido_id IN (SELECT id FROM detalle_pedido WHERE pedido_id = ?)', [$pedido]);

        $respuesta = $this->avanzar($this->personal(), $pedido, ['hacia' => 'EN_PREPARACION']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('Bs', $respuesta->json('message'));
        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);
        $this->assertCount(0, $this->pagos($pedido));
    }

    public function test_no_despacha_ni_entrega_si_el_cobro_dejo_de_estar_completo(): void
    {
        $pedido = $this->pedidoPagado(self::CLIENTE, [$this->productoConStock() => 2]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION');
        // El cobro deja de constar como pagado (dato alterado para la prueba).
        DB::update("UPDATE pago SET estado = 'RECHAZADO', fecha_pago = NULL WHERE pedido_id = ?", [$pedido]);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'DESPACHADO'])->assertStatus(422);
        $this->assertSame('EN_PREPARACION', $this->pedido($pedido)->estado);
        $this->assertCount(0, $this->salidas($pedido));

        DB::update("UPDATE pedido SET estado = 'DESPACHADO' WHERE id = ?", [$pedido]);
        $this->avanzar($this->personal(), $pedido, ['hacia' => 'ENTREGADO'])->assertStatus(422);
        $this->assertSame('DESPACHADO', $this->pedido($pedido)->estado);
        $this->assertSame('PENDIENTE', $this->entrega($pedido)->estado);
    }

    public function test_un_cobro_mayor_que_el_total_no_cuenta_como_cobro_completo(): void
    {
        // Bs 315 cobrados en un pedido de Bs 210 pasaban como cobro completo (R057).
        $pedido = $this->pedidoPagado(self::CLIENTE, [$this->productoConStock(70.0) => 3]);
        DB::table('pago')->insert(['pedido_id' => $pedido, 'metodo' => 'TARJETA', 'monto' => '105.00', 'estado' => 'PAGADO',
            'fecha_pago' => now(), 'referencia_externa' => 'TRJ-DUPLICADO-'.$pedido, 'clave_idempotencia' => hash('sha256', 'PRUEBA|DUPLICADO|'.$pedido)]);

        $respuesta = $this->avanzar($this->personal(), $pedido, ['hacia' => 'EN_PREPARACION']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('Bs 315,00', $respuesta->json('message'));
        $this->assertStringContainsString('supera el total', $respuesta->json('message'));
        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);
    }

    // ------------------------------------------------------------------ efectivo contra entrega

    public function test_efectivo_pendiente_permite_avanzar_y_se_cobra_al_entregar(): void
    {
        $pedido = $this->pedidoDe(self::CLIENTE, [$this->productoConStock(35.0) => 3]);
        $this->pagarEnEfectivo(self::CLIENTE, $pedido)->assertOk();
        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);

        $this->avanzarHasta($pedido, 'EN_PREPARACION', 'DESPACHADO');
        $sinConfirmar = $this->avanzar($this->personal(), $pedido, ['hacia' => 'ENTREGADO']);
        $sinConfirmar->assertStatus(422);
        $this->assertSame('PENDIENTE', $this->pagos($pedido)[0]->estado);
        $this->assertSame('DESPACHADO', $this->pedido($pedido)->estado);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'ENTREGADO', 'efectivo_confirmado' => true])->assertOk();

        $pago = $this->pagos($pedido)[0];
        $this->assertSame(['EFECTIVO', 'PAGADO', '105.00'], [$pago->metodo, $pago->estado, $pago->monto]);
        $this->assertNotNull($pago->fecha_pago);
        $this->assertSame('ENTREGADO', $this->pedido($pedido)->estado);
        $this->assertSame('105.00', DB::table('factura')->where('pedido_id', $pedido)->value('total'));
    }

    public function test_un_efectivo_pendiente_que_no_cubre_el_saldo_no_cuenta_como_cobro(): void
    {
        $pedido = $this->pedidoDe(self::CLIENTE, [$this->productoConStock(35.0) => 3]);
        $this->pagarEnEfectivo(self::CLIENTE, $pedido)->assertOk();
        DB::update('UPDATE pago SET monto = 50 WHERE pedido_id = ?', [$pedido]);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'EN_PREPARACION'])->assertStatus(422);

        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);
    }

    // ------------------------------------------------------------------ plan de cuotas (PROMPT 7.5)

    public function test_plan_valido_con_la_primera_cuota_pagada_permite_preparar_despachar_y_entregar(): void
    {
        $pedido = $this->pedidoDe(self::CLIENTE, [$this->productoConStock(70.0) => 3], ['forma_pago' => 'CUOTAS', 'num_cuotas' => 2]);
        $this->pagarConTarjeta(self::CLIENTE, $pedido)->assertOk()->assertJsonPath('resultado', 'PAGADO');
        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);
        $this->assertSame(['PAGADA', 'PENDIENTE'], array_column($this->plan($pedido)['cuotas'], 'estado'));

        $this->avanzarHasta($pedido, 'EN_PREPARACION', 'DESPACHADO', 'ENTREGADO');

        $this->assertSame('ENTREGADO', $this->pedido($pedido)->estado);
        $this->assertSame(105.0, (float) $this->pagos($pedido)->where('estado', 'PAGADO')->sum('monto'), 'Entregado con la primera cuota de Bs 210.');
        $this->assertSame(0, DB::table('factura')->where('pedido_id', $pedido)->count(), 'La factura llega con la última cuota.');
    }

    public function test_plan_sin_la_primera_cuota_pagada_no_permite_preparar(): void
    {
        $pedido = $this->pedidoDe(self::CLIENTE, [$this->productoConStock(70.0) => 3], ['forma_pago' => 'CUOTAS', 'num_cuotas' => 2]);
        // Estado forzado: confirmado sin haber cobrado ninguna cuota.
        DB::update("UPDATE pedido SET estado = 'CONFIRMADO' WHERE id = ?", [$pedido]);
        DB::update('UPDATE reserva_stock SET fecha_expiracion = NULL WHERE detalle_pedido_id IN (SELECT id FROM detalle_pedido WHERE pedido_id = ?)', [$pedido]);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'EN_PREPARACION'])->assertStatus(422);

        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);
    }

    public function test_plan_cuya_primera_cuota_tiene_el_pago_rechazado_no_permite_preparar(): void
    {
        $pedido = $this->pedidoDe(self::CLIENTE, [$this->productoConStock(70.0) => 3], ['forma_pago' => 'CUOTAS', 'num_cuotas' => 2]);
        $this->pagarConTarjeta(self::CLIENTE, $pedido)->assertOk();
        // El cobro de la primera cuota deja de constar como pagado aunque el plan la marque PAGADA.
        DB::update("UPDATE pago SET estado = 'RECHAZADO', fecha_pago = NULL WHERE pedido_id = ?", [$pedido]);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'EN_PREPARACION'])->assertStatus(422);

        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);
    }

    public function test_un_plan_de_otro_pedido_o_cancelado_no_sirve_de_excepcion(): void
    {
        $producto = $this->productoConStock(70.0);
        $conPlan = $this->pedidoDe(self::CLIENTE, [$producto => 3], ['forma_pago' => 'CUOTAS', 'num_cuotas' => 2]);
        $this->pagarConTarjeta(self::CLIENTE, $conPlan)->assertOk();
        $sinPlan = $this->pedidoDe(self::OTRO_CLIENTE, [$producto => 3]);
        DB::update("UPDATE pedido SET estado = 'CONFIRMADO' WHERE id = ?", [$sinPlan]);

        $this->avanzar($this->personal(), $sinPlan, ['hacia' => 'EN_PREPARACION'])->assertStatus(422);

        // Plan cancelado (queda dado de baja): el pedido con una cuota pagada ya no puede prepararse por la excepción.
        $plan = ConfirmacionOperacion::query()->where('comando', 'PLAN_PAGO')->whereRaw("(parametros->>'pedido_id')::bigint = ?", [$conPlan])->first();
        $parametros = $plan->parametros;
        $parametros['estado'] = 'CANCELADO';
        $plan->parametros = $parametros;
        $plan->save();

        $this->avanzar($this->personal(), $conPlan, ['hacia' => 'EN_PREPARACION'])->assertStatus(422);
        $this->assertSame('CONFIRMADO', $this->pedido($conPlan)->estado);
    }

    public function test_un_plan_real_de_tres_cuotas_con_redondeo_permite_preparar(): void
    {
        // Bs 250 en 3 cuotas: PlanPagoService las crea de 83,33 + 83,33 + 83,34 con vencimientos semanales.
        $pedido = $this->pedidoDe(self::CLIENTE, [$this->productoConStock(50.0) => 5], ['forma_pago' => 'CUOTAS', 'num_cuotas' => 3]);
        $this->pagarConTarjeta(self::CLIENTE, $pedido)->assertOk()->assertJsonPath('resultado', 'PAGADO');

        $this->avanzarHasta($pedido, 'EN_PREPARACION');

        $this->assertSame(['83.33', '83.33', '83.34'], array_map(fn ($c) => number_format($c['monto'], 2, '.', ''), $this->plan($pedido)['cuotas']));
    }

    public function test_un_plan_mal_formado_no_sirve_de_excepcion(): void
    {
        // un PLAN_PAGO de cuatro cuotas sin fechas, con la primera cobrada,
        // dejaba preparar el pedido. Se parte de un plan real de 2 cuotas con la primera pagada y se altera su forma.
        $pedido = $this->pedidoDe(self::CLIENTE, [$this->productoConStock(70.0) => 3], ['forma_pago' => 'CUOTAS', 'num_cuotas' => 2]);
        $this->pagarConTarjeta(self::CLIENTE, $pedido)->assertOk()->assertJsonPath('resultado', 'PAGADO');
        $plan = ConfirmacionOperacion::query()->where('comando', 'PLAN_PAGO')->whereRaw("(parametros->>'pedido_id')::bigint = ?", [$pedido])->first();
        $original = $plan->parametros;
        $parametros = $original;
        $pagoId = $parametros['cuotas'][0]['pago_id'];
        DB::update('UPDATE pago SET monto = 52.50 WHERE id = ?', [$pagoId]);
        $parametros['num_cuotas'] = 4;
        $parametros['cuotas'] = array_map(fn ($n) => ['numero' => $n, 'monto' => 52.5, 'estado' => $n === 1 ? 'PAGADA' : 'PENDIENTE',
            'pago_id' => $n === 1 ? $pagoId : null], [1, 2, 3, 4]);
        $plan->parametros = $parametros;
        $plan->save();

        $respuesta = $this->avanzar($this->personal(), $pedido, ['hacia' => 'EN_PREPARACION']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('plan de cuotas no es válido', $respuesta->json('message'));
        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);

        // Con vencimientos que no son semanales tampoco vale.
        DB::update('UPDATE pago SET monto = 105.00 WHERE id = ?', [$pagoId]);
        $original['cuotas'][1]['vence'] = now()->addDays(30)->toDateString();
        $plan->parametros = $original;
        $plan->save();
        $this->avanzar($this->personal(), $pedido, ['hacia' => 'EN_PREPARACION'])->assertStatus(422);
        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);
    }

    public function test_el_pedido_8_de_los_datos_iniciales_no_se_entrega_sin_cobro(): void
    {
        // Se retira el cobro contra entrega de la semilla para probar el encargo impago.
        $this->assertSame(1, DB::update("UPDATE pago SET estado = 'RECHAZADO' WHERE pedido_id = 8 AND metodo = 'EFECTIVO' AND estado = 'PENDIENTE'"));
        $lote = $this->crearLoteFeder(2, 12, '2027-11-05', '2026-11-20 09:00:00');
        $this->abastecer($this->personal(), 8)->assertStatus(422);

        $this->avanzar($this->personal(), 8, ['hacia' => 'EN_PREPARACION'])->assertStatus(409);

        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido(8)->estado);
        $this->assertSame('0.000', $this->lote($lote)->cantidad_reservada);
        $this->assertSame(0.0, (float) $this->pagos(8)->where('estado', 'PAGADO')->sum('monto'));
    }
}
