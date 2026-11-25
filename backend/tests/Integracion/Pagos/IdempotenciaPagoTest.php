<?php

namespace Tests\Integracion\Pagos;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * H-06: repetir o duplicar «pagar» no debe cobrar otra cuota.
 * Contrato: clave_operacion (UUID por intento) y cuota_numero (obligatorio con plan; debe ser la siguiente pendiente).
 */
class IdempotenciaPagoTest extends PagosTestCase
{
    public function test_reintento_con_la_misma_clave_devuelve_el_pago_ya_registrado_y_no_cobra_la_cuota_siguiente(): void
    {
        $pedido = $this->crearPedido(self::DANIELA, [self::ACEITE_COPAIBO => 3], 3);   // Bs 210 → 70 + 70 + 70
        $cuerpo = ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(), 'cuota_numero' => 1, 'clave_operacion' => $this->uuid()];

        $primero = $this->pagar(self::DANIELA, $pedido['id'], $cuerpo)->assertOk()->json();
        $segundo = $this->pagar(self::DANIELA, $pedido['id'], $cuerpo)->assertOk()->json();

        $this->assertSame('PAGADO', $primero['resultado']);
        $this->assertFalse($primero['repetido']);
        $this->assertSame('PAGADO', $segundo['resultado']);
        $this->assertTrue($segundo['repetido']);
        $this->assertSame($primero['pago']['id'], $segundo['pago']['id']);

        $this->assertCount(1, $this->pagos($pedido['id']), 'El reintento creó otro pago.');
        $cuotas = array_column($this->plan($pedido['id'])['cuotas'], 'estado');
        $this->assertSame(['PAGADA', 'PENDIENTE', 'PENDIENTE'], $cuotas);
    }

    public function test_sin_clave_un_segundo_envio_de_la_misma_cuota_se_rechaza_con_409(): void
    {
        $pedido = $this->crearPedido(self::DANIELA, [self::ACEITE_COPAIBO => 3], 3);
        $cuerpo = ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(), 'cuota_numero' => 1];

        $this->pagar(self::DANIELA, $pedido['id'], $cuerpo)->assertOk();
        $this->pagar(self::DANIELA, $pedido['id'], $cuerpo)->assertStatus(409);

        $this->assertCount(1, $this->pagos($pedido['id'], 'PAGADO'));
    }

    public function test_sin_clave_repetir_la_ultima_cuota_ya_pagada_se_rechaza_con_409_y_no_cobra(): void
    {
        $pedido = $this->crearPedido(self::DANIELA, [self::ACEITE_COPAIBO => 3], 2);   // Bs 210 → 105 + 105
        $this->pagarConTarjeta(self::DANIELA, $pedido['id'], 1)->assertOk();
        $cuerpo = ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(), 'cuota_numero' => 2];

        $this->pagar(self::DANIELA, $pedido['id'], $cuerpo)->assertOk()->assertJsonPath('resultado', 'PAGADO');
        // El mismo envío llega otra vez: la cuota 2 ya no es la siguiente pendiente (no queda ninguna). Es un conflicto
        // con el estado actual del plan, igual que repetir cualquier otra cuota (contrato H-06), no un error de datos.
        $this->pagar(self::DANIELA, $pedido['id'], $cuerpo)->assertStatus(409);
        // Sin indicar la cuota sigue siendo un pedido sin nada que cobrar.
        $this->pagar(self::DANIELA, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta()])->assertStatus(422);

        $this->assertCount(2, $this->pagos($pedido['id'], 'PAGADO'));
        $this->assertCount(2, $this->pagos($pedido['id']));
        $this->assertSame('COMPLETADO', $this->plan($pedido['id'])['estado']);
        $this->assertSame(1, DB::table('factura')->where('pedido_id', $pedido['id'])->count());
    }

    public function test_con_plan_hay_que_indicar_la_cuota_y_debe_ser_la_siguiente_pendiente(): void
    {
        $pedido = $this->crearPedido(self::DANIELA, [self::ACEITE_COPAIBO => 3], 3);

        $this->pagar(self::DANIELA, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta()])->assertStatus(422);
        $this->pagar(self::DANIELA, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(), 'cuota_numero' => 2])->assertStatus(409);

        $this->assertCount(0, $this->pagos($pedido['id']));
        $this->assertSame('PENDIENTE', $this->estadoPedido($pedido['id']));
    }

    public function test_un_pedido_sin_plan_no_acepta_numero_de_cuota(): void
    {
        $pedido = $this->crearPedido(self::DANIELA, [self::ACEITE_COPAIBO => 1]);

        $this->pagar(self::DANIELA, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(), 'cuota_numero' => 1])->assertStatus(409);

        $this->assertCount(0, $this->pagos($pedido['id']));
    }

    public function test_pago_unico_con_la_misma_clave_no_se_cobra_dos_veces_y_sin_clave_el_segundo_se_rechaza(): void
    {
        $pedido = $this->crearPedido(self::DANIELA, [self::ACEITE_COPAIBO => 1]);
        $cuerpo = ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(), 'clave_operacion' => $this->uuid()];

        $primero = $this->pagar(self::DANIELA, $pedido['id'], $cuerpo)->assertOk()->json();
        $segundo = $this->pagar(self::DANIELA, $pedido['id'], $cuerpo)->assertOk()->json();
        $this->assertSame($primero['pago']['id'], $segundo['pago']['id']);
        $this->assertTrue($segundo['repetido']);

        // Otro intento distinto (sin clave) sobre un pedido ya pagado no cobra nada.
        $this->pagar(self::DANIELA, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta()])->assertStatus(422);
        $this->assertCount(1, $this->pagos($pedido['id']));
    }

    public function test_reintento_de_una_tarjeta_rechazada_devuelve_el_rechazo_sin_crear_otro_intento(): void
    {
        $pedido = $this->crearPedido(self::DANIELA, [self::ACEITE_COPAIBO => 1]);
        $cuerpo = ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(self::TARJETA_SIN_FONDOS), 'clave_operacion' => $this->uuid()];

        $primero = $this->pagar(self::DANIELA, $pedido['id'], $cuerpo)->assertOk()->json();
        $segundo = $this->pagar(self::DANIELA, $pedido['id'], $cuerpo)->assertOk()->json();

        $this->assertSame('RECHAZADO', $primero['resultado']);
        $this->assertSame('RECHAZADO', $segundo['resultado']);
        $this->assertTrue($segundo['repetido']);
        $this->assertCount(1, $this->pagos($pedido['id']));
        $this->assertSame('PENDIENTE', $this->estadoPedido($pedido['id']));
    }

    public function test_la_misma_clave_con_otro_medio_de_pago_se_rechaza(): void
    {
        $pedido = $this->crearPedido(self::DANIELA, [self::ACEITE_COPAIBO => 1]);
        $clave = $this->uuid();

        $this->pagar(self::DANIELA, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(self::TARJETA_SIN_FONDOS), 'clave_operacion' => $clave])->assertOk();
        $this->pagar(self::DANIELA, $pedido['id'], ['metodo' => 'QR', 'clave_operacion' => $clave])->assertStatus(409);

        $this->assertCount(1, $this->pagos($pedido['id']));
    }

    public function test_la_clave_de_una_cuota_ya_pagada_no_sirve_para_pagar_la_siguiente(): void
    {
        $pedido = $this->crearPedido(self::DANIELA, [self::ACEITE_COPAIBO => 3], 3);
        $clave = $this->uuid();

        $this->pagar(self::DANIELA, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(), 'cuota_numero' => 1, 'clave_operacion' => $clave])->assertOk();
        $this->pagar(self::DANIELA, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(), 'cuota_numero' => 2, 'clave_operacion' => $clave])->assertStatus(409);

        $this->assertCount(1, $this->pagos($pedido['id']));
        $this->assertSame(['PAGADA', 'PENDIENTE', 'PENDIENTE'], array_column($this->plan($pedido['id'])['cuotas'], 'estado'));
    }

    public static function intentosSinCobro(): array
    {
        return [
            'QR pendiente' => ['QR', 'PENDIENTE'],
            'tarjeta rechazada' => ['TARJETA', 'RECHAZADO'],
        ];
    }

    #[DataProvider('intentosSinCobro')]
    public function test_el_reintento_sin_cobro_conserva_la_cuota_indicada_por_el_cliente(string $metodo, string $estado): void
    {
        $pedido = $this->crearPedido(self::DANIELA, [self::ACEITE_COPAIBO => 3], 3);
        $intento = ['metodo' => $metodo, 'cuota_numero' => 1, 'clave_operacion' => $this->uuid()];
        if ($metodo === 'TARJETA') {
            $intento['tarjeta'] = $this->tarjeta(self::TARJETA_SIN_FONDOS);
        }
        $primero = $this->pagar(self::DANIELA, $pedido['id'], $intento)->assertOk()->assertJsonPath('resultado', $estado)->json();
        $this->pagar(self::DANIELA, $pedido['id'], $intento)->assertOk()->assertJsonPath('repetido', true)
            ->assertJsonPath('pago.id', $primero['pago']['id']);

        $intento['cuota_numero'] = 2;
        $this->pagar(self::DANIELA, $pedido['id'], $intento)->assertStatus(409);
        unset($intento['cuota_numero']);
        $this->pagar(self::DANIELA, $pedido['id'], $intento)->assertStatus(422);

        $this->assertCount(1, $this->pagos($pedido['id']));
        $this->assertSame(['PENDIENTE', 'PENDIENTE', 'PENDIENTE'], array_column($this->plan($pedido['id'])['cuotas'], 'estado'));
    }

    public function test_la_clave_de_idempotencia_guardada_es_estable(): void
    {
        $pedido = $this->crearPedido(self::DANIELA, [self::ACEITE_COPAIBO => 1]);
        $clave = $this->uuid();

        $this->pagar(self::DANIELA, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(self::TARJETA_SIN_FONDOS), 'clave_operacion' => $clave])->assertOk();
        $this->pagar(self::DANIELA, $pedido['id'], ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta()])->assertOk();

        $claves = $this->pagos($pedido['id'])->pluck('clave_idempotencia')->all();
        $this->assertSame(hash('sha256', 'PAGO_WEB|'.$pedido['id'].'|'.$clave), $claves[0], 'La clave del cliente debe dar siempre la misma clave guardada.');
        $this->assertSame("PAG-{$pedido['codigo']}-02", $claves[1], 'Sin clave del cliente se usa el correlativo del pedido.');
    }

    public function test_la_clave_de_operacion_debe_ser_un_uuid(): void
    {
        $pedido = $this->crearPedido(self::DANIELA, [self::ACEITE_COPAIBO => 1]);

        $this->pagar(self::DANIELA, $pedido['id'], ['metodo' => 'QR', 'clave_operacion' => 'no-es-un-uuid'])
            ->assertStatus(422)->assertJsonValidationErrors(['clave_operacion']);
        $this->assertCount(0, $this->pagos($pedido['id']));
    }
}
