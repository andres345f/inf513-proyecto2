<?php

namespace Tests\Integracion\Acceso;

use Illuminate\Support\Facades\DB;

class CierreMultirrolTest extends AccesoTestCase
{
    public function test_comprar_con_el_mismo_usuario_y_recuperar_el_carrito_y_el_historial(): void
    {
        $administrativo = $this->crearCuenta(['ADMINISTRADOR']);
        $this->api('POST', "/api/admin/usuarios/{$administrativo->id}/roles", ['rol' => 'CLIENTE'], $this->tokenDe(self::ADMIN))->assertOk();

        foreach ([self::MULTIRROL => 'PERSONAL_FEDER', self::PRODUCTORA => 'PRODUCTOR', $administrativo->correo => 'ADMINISTRADOR'] as $correo => $profesional) {
            $token = $this->tokenDe($correo, $profesional);
            $titular = $this->usuarioId($correo);
            $this->cambiarRol($token, 'CLIENTE', $titular);
            $carrito = $this->api('POST', '/api/carrito', ['producto_id' => 1, 'cantidad' => 1], $token)->assertOk()->json('items');
            $this->cambiarRol($token, $profesional, $titular);
            $this->api('GET', '/api/carrito', [], $token)->assertForbidden();
            $this->cambiarRol($token, 'CLIENTE', $titular);
            $this->api('GET', '/api/carrito', [], $token)->assertOk()->assertJsonPath('items', $carrito);
            $id = $this->api('POST', '/api/pedidos', [
                'tipo' => 'INMEDIATO', 'modalidad' => 'RECOJO', 'forma_pago' => 'UNICO',
                'nombre_receptor' => 'Cliente de prueba', 'telefono_contacto' => '70012345',
            ], $token)->assertCreated()->json('id');
            $this->assertSame($titular, (int) DB::table('pedido')->where('id', $id)->value('usuario_id'));
            $this->cambiarRol($token, $profesional, $titular);
            $this->cambiarRol($token, 'CLIENTE', $titular);
            $this->api('GET', '/api/mis-pedidos', [], $token)->assertOk()->assertJsonFragment(['id' => $id]);
        }
    }

    public function test_sin_cliente_o_con_cliente_revocado_el_catalogo_no_lo_concede(): void
    {
        $sinCliente = $this->crearCuenta(['PERSONAL_FEDER']);
        $conBaja = $this->crearCuenta(['CLIENTE', 'PERSONAL_FEDER']);
        $this->api('POST', "/api/admin/usuarios/{$conBaja->id}/roles/CLIENTE/revocar", ['motivo' => 'Baja de compras personales'], $this->tokenDe(self::ADMIN))->assertOk();
        foreach ([$sinCliente, $conBaja] as $persona) {
            $token = $this->tokenDe($persona->correo, 'PERSONAL_FEDER');
            $this->api('GET', '/api/catalogo', [], $token)->assertOk();
            $this->api('POST', '/api/auth/rol-activo', ['rol_id' => $this->rolId('CLIENTE')], $token)->assertForbidden();
            $this->api('GET', '/api/carrito', [], $token)->assertForbidden();
            $this->assertFalse(DB::table('usuario_rol')->where('usuario_id', $persona->id)->where('rol_id', $this->rolId('CLIENTE'))->where('estado', 'VIGENTE')->exists());
        }
    }

    public function test_aprobacion_por_otra_persona_y_suspension_profesional_conservan_cliente(): void
    {
        $cuenta = $this->crearCuenta(['CLIENTE', 'PERSONAL_FEDER', 'PRODUCTOR']);
        $token = $this->tokenDe($cuenta->correo, 'CLIENTE');
        $id = $this->api('POST', '/api/productor/solicitud', [
            'comunidad_id' => 1, 'ubicacion_actividad' => 'Parcela comunitaria de prueba',
        ], $token)->assertCreated()->json('id');
        $this->cambiarRol($token, 'PERSONAL_FEDER', $cuenta->id);
        $this->api('POST', "/api/gestion/productores/{$id}/aprobar", [], $token)->assertForbidden();
        $this->api('POST', "/api/gestion/productores/{$id}/aprobar", [], $this->tokenDe(self::PERSONAL))->assertOk();
        $this->cambiarRol($token, 'CLIENTE', $cuenta->id);
        $this->api('GET', '/api/carrito', [], $token)->assertOk();
        $this->api('POST', "/api/admin/usuarios/{$cuenta->id}/roles/PRODUCTOR/revocar", ['motivo' => 'Retiro de la actividad productiva'], $this->tokenDe(self::ADMIN))->assertOk();
        $this->assertFalse((bool) DB::table('productor')->where('id', $id)->value('activo'));
        $cliente = $this->tokenDe($cuenta->correo, 'CLIENTE');
        $this->api('GET', '/api/carrito', [], $cliente)->assertOk();
        $this->api('POST', '/api/auth/rol-activo', ['rol_id' => $this->rolId('PRODUCTOR')], $cliente)->assertForbidden();
    }

    public function test_otro_operador_compra_recibe_y_paga_los_lotes_de_un_empleado_productor(): void
    {
        $titular = $this->usuarioId(self::PRODUCTORA_C);
        $this->api('POST', "/api/admin/usuarios/{$titular}/roles", ['rol' => 'PERSONAL_FEDER'], $this->tokenDe(self::ADMIN))->assertOk();
        $yo = $this->tokenDe(self::PRODUCTORA_C, 'PRODUCTOR');
        $this->cambiarRol($yo, 'PERSONAL_FEDER', $titular);
        $otro = $this->tokenDe(self::PERSONAL);
        $compra = ['lineas' => [['lote_id' => 13, 'cantidad' => 1, 'precio' => 40]], 'modalidad_traslado' => 'RECOJO_FEDER'];
        $this->api('POST', '/api/gestion/compras', $compra, $yo)->assertForbidden();
        $id = $this->api('POST', '/api/gestion/compras', $compra, $otro)->assertCreated()->json('id');
        $linea = DB::table('detalle_compra')->where('compra_id', $id)->value('id');
        $recepcion = ['lineas' => [['detalle_id' => $linea, 'recibida' => 1, 'rechazada' => 0, 'cancelada' => 0]]];
        $this->api('POST', "/api/gestion/compras/{$id}/recibir", $recepcion, $yo)->assertForbidden();
        $this->api('POST', "/api/gestion/compras/{$id}/recibir", $recepcion, $otro)->assertOk();
        $this->api('POST', "/api/gestion/compras/{$id}/pagar", [], $yo)->assertForbidden();
        $this->api('POST', "/api/gestion/compras/{$id}/pagar", [], $otro)->assertOk();
        $this->assertSame($this->usuarioId(self::PERSONAL), (int) $this->ultimaBitacora('COMPRA_FEDER', $id)->usuario_id);
    }

    private function cambiarRol(string $token, string $codigo, int $titular): void
    {
        $this->api('POST', '/api/auth/rol-activo', ['rol_id' => $this->rolId($codigo)], $token)->assertOk()
            ->assertJsonPath('usuario.id', $titular)->assertJsonPath('rol_activo.codigo', $codigo);
    }
}
