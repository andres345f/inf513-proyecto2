<?php

namespace Tests\Integracion\Acceso;

use Illuminate\Support\Facades\DB;

/**
 * Propiedad de recursos (IDOR): con el permiso correcto pero el identificador de otra persona, la API responde
 * «no existe» y no cambia nada. Se llama a la API directamente, como haría alguien que no usa la interfaz.
 *
 * Datos: la clienta (daniela) es dueña de los pedidos 1, 7 y 8; el cliente B (marco) de los pedidos 2, 3 y 9,
 * los pagos 2, 3 y 9, las facturas 2, 3 y 7 y el despacho 12. El pedido 11 (pendiente, pago 11 QR) es de otro cliente.
 */
class PropiedadDeRecursosTest extends AccesoTestCase
{
    public function test_un_cliente_no_ve_cancela_ni_paga_el_pedido_de_otro(): void
    {
        $token = $this->tokenDe(self::CLIENTE);
        $antes = (array) DB::table('pedido')->where('id', 11)->first();
        $pagos = DB::table('pago')->count();

        $this->api('GET', '/api/mis-pedidos/2', [], $token)->assertStatus(404);
        $this->api('POST', '/api/mis-pedidos/11/cancelar', ['motivo' => 'Prueba de propiedad de recursos'], $token)->assertStatus(404);
        $this->api('POST', '/api/mis-pedidos/11/pagar', ['metodo' => 'EFECTIVO'], $token)->assertStatus(404);

        $this->assertSame($antes, (array) DB::table('pedido')->where('id', 11)->first());
        $this->assertSame($pagos, DB::table('pago')->count());
        // El mismo mensaje que para un pedido que no existe: no se revela que es de otra persona.
        $this->assertSame(
            $this->api('GET', '/api/mis-pedidos/999999', [], $token)->json('message'),
            $this->api('GET', '/api/mis-pedidos/2', [], $token)->json('message')
        );
        // Y sus propios pedidos sí los ve.
        $this->api('GET', '/api/mis-pedidos/1', [], $token)->assertOk()->assertJsonPath('id', 1);
        $this->assertEqualsCanonicalizing([1, 7, 8], array_column($this->api('GET', '/api/mis-pedidos', [], $token)->json('pedidos'), 'id'));
    }

    public function test_pagos_y_facturas_ajenos_no_existen_para_otro_cliente(): void
    {
        // Con la simulación de QR encendida, para que lo que frene la petición sea la propiedad del pago y no el interruptor.
        config(['feder.qr_simulado' => true]);
        $token = $this->tokenDe(self::CLIENTE);
        $pago = (array) DB::table('pago')->where('id', 11)->first();

        $this->api('GET', '/api/mis-pagos/2', [], $token)->assertStatus(404);
        $this->api('POST', '/api/mis-pagos/11/simular-qr', [], $token)->assertStatus(404);
        $this->api('GET', '/api/mis-facturas/2', [], $token)->assertStatus(404);

        $this->assertSame($pago, (array) DB::table('pago')->where('id', 11)->first());
        $this->api('GET', '/api/mis-facturas/1', [], $token)->assertOk();
    }

    public function test_no_se_pide_la_devolucion_de_un_despacho_ajeno(): void
    {
        $devoluciones = DB::table('devolucion')->count();

        // El despacho 12 pertenece al pedido 2 del cliente B.
        $this->api('POST', '/api/mis-devoluciones', ['despacho_id' => 12, 'cantidad' => 1, 'motivo' => 'Prueba de propiedad'], $this->tokenDe(self::CLIENTE))
            ->assertStatus(404);

        $this->assertSame($devoluciones, DB::table('devolucion')->count());
    }

    public function test_las_tarjetas_guardadas_de_otro_cliente_no_se_tocan_ni_se_usan(): void
    {
        $duenio = $this->usuarioId(self::CLIENTE_B);
        $tarjetaId = (int) DB::table('confirmacion_operacion')->insertGetId([
            'codigo_hash' => hash('sha256', 'PRUEBA:'.bin2hex(random_bytes(8))), 'correo' => self::CLIENTE_B, 'usuario_id' => $duenio,
            'comando' => 'METODO_PAGO', 'expira_en' => '9999-12-31 23:59:59-04',
            'parametros' => json_encode(['tipo' => 'TARJETA', 'marca' => 'VISA', 'ultimos4' => '4242', 'titular' => 'TITULAR DE PRUEBA',
                'expira_mes' => 12, 'expira_anio' => 2030, 'alias' => 'Prueba', 'simulacion' => 'APROBADA', 'predeterminado' => true]),
        ]);
        $token = $this->tokenDe(self::CLIENTE);
        // Se compara con lo que ya había: los datos iniciales pueden traer pagos previos de ese pedido.
        $pagosAntes = DB::table('pago')->where('pedido_id', 8)->count();

        $this->assertSame([], $this->api('GET', '/api/metodos-pago', [], $token)->assertOk()->json('metodos'));
        $this->api('DELETE', "/api/metodos-pago/{$tarjetaId}", [], $token)->assertStatus(404);
        $this->api('POST', "/api/metodos-pago/{$tarjetaId}/predeterminado", [], $token)->assertStatus(404);
        // Pagar un pedido propio con la tarjeta de otro tampoco funciona.
        $pago = $this->api('POST', '/api/mis-pedidos/8/pagar', ['metodo' => 'TARJETA', 'metodo_pago_id' => $tarjetaId], $token);
        $this->assertContains($pago->status(), [404, 422]);

        $this->assertNull(DB::table('confirmacion_operacion')->where('id', $tarjetaId)->value('usada_en'));
        $this->assertSame($pagosAntes, DB::table('pago')->where('pedido_id', 8)->count(), 'Se registró un pago con la tarjeta de otro cliente.');
    }

    public function test_marcar_como_leida_una_notificacion_ajena_no_tiene_efecto(): void
    {
        $ajena = (int) DB::table('notificacion')->where('usuario_id', $this->usuarioId(self::CLIENTE_B))->whereNull('fecha_lectura')->min('id');

        $this->api('POST', "/api/notificaciones/{$ajena}/leer", [], $this->tokenDe(self::CLIENTE));

        $this->assertNull(DB::table('notificacion')->where('id', $ajena)->value('fecha_lectura'));
        $ids = array_column($this->api('GET', '/api/notificaciones', [], $this->tokenDe(self::CLIENTE))->assertOk()->json('notificaciones'), 'id');
        $this->assertNotContains($ajena, $ids);
    }

    public function test_un_productor_no_ve_ni_cambia_producciones_y_lotes_de_otro(): void
    {
        // Producción 11 y lote 11 son de la productora A; quien llama es el productor B (dueño de la producción 12).
        $token = $this->tokenDe(self::PRODUCTOR_B, 'PRODUCTOR');
        $huella = fn () => [
            (array) DB::table('produccion')->where('id', 11)->first(),
            (array) DB::table('lote_produccion')->where('id', 11)->first(),
            DB::table('lote_produccion')->count(),
        ];
        $antes = $huella();
        $lote = ['fecha_elaboracion' => '2026-11-05', 'fecha_vencimiento' => '2027-11-05', 'cantidad_producida' => 40, 'cantidad_ofrecida' => 40, 'precio_ofrecido' => 1];
        $produccion = ['producto_id' => 1, 'fecha_inicio' => '2026-10-20', 'fecha_disponibilidad_estimada' => '2026-12-20', 'cantidad_estimada' => 10, 'ubicacion_produccion' => 'Parcela ajena de prueba'];

        $this->api('GET', '/api/productor/producciones/11', [], $token)->assertStatus(404);
        $this->api('PUT', '/api/productor/producciones/11', $produccion, $token)->assertStatus(404);
        $this->api('POST', '/api/productor/producciones/11/estado', ['estado' => 'TERMINADA'], $token)->assertStatus(404);
        $this->api('POST', '/api/productor/producciones/11/lotes', $lote, $token)->assertStatus(404);
        $this->api('PUT', '/api/productor/producciones/11/lotes/11', $lote, $token)->assertStatus(404);
        $this->api('POST', '/api/productor/lotes/11/retirar', [], $token)->assertStatus(404);
        // Tampoco colando el lote ajeno dentro de una producción propia.
        $this->assertContains($this->api('PUT', '/api/productor/producciones/12/lotes/11', $lote, $token)->status(), [404, 409, 422]);

        $this->assertSame($antes, $huella());
        $this->assertNotContains(11, array_column($this->api('GET', '/api/productor/producciones', [], $token)->assertOk()->json('producciones'), 'id'));
        $this->assertNotContains(11, array_column($this->api('GET', '/api/productor/lotes', [], $token)->assertOk()->json('lotes'), 'id'));
    }

    public function test_un_productor_no_ve_la_venta_de_otro_productor(): void
    {
        // La compra 1 es de la productora A (productor 1); la compra 3 es del productor B (productor 3).
        $token = $this->tokenDe(self::PRODUCTOR_B, 'PRODUCTOR');

        $this->api('GET', '/api/productor/ventas/3', [], $token)->assertOk()->assertJsonPath('codigo', 'CMP-2026-003');
        $ajena = $this->api('GET', '/api/productor/ventas/1', [], $token)->assertStatus(404);
        $inexistente = $this->api('GET', '/api/productor/ventas/999999', [], $token)->assertStatus(404);

        // Mismo mensaje en los dos casos: no se delata que la compra existe.
        $this->assertSame($inexistente->json('message'), $ajena->json('message'));
    }

    public function test_el_rol_cliente_de_una_cuenta_de_personal_no_abre_recursos_de_gestion(): void
    {
        // Acceso directo con el identificador correcto pero el rol activo equivocado.
        $token = $this->tokenDe(self::MULTIRROL, 'CLIENTE');

        $this->api('GET', '/api/gestion/pedidos/2', [], $token)->assertStatus(403);
        $this->api('POST', '/api/gestion/pagos/11/confirmar', [], $token)->assertStatus(403);
        $this->api('GET', '/api/gestion/compras/1', [], $token)->assertStatus(403);
        $this->assertSame('PENDIENTE', DB::table('pago')->where('id', 11)->value('estado'));
    }
}
