<?php

namespace Tests\Integracion\Acceso;

use Illuminate\Support\Facades\DB;

/**
 * Sesiones, cuentas con varios roles y cambios hechos mientras hay una sesión abierta
 * (rol revocado, cuenta desactivada, token vencido, cambio de permisos). Todo se comprueba en el servidor.
 */
class SesionesYRolesTest extends AccesoTestCase
{
    // ---------------- Tokens ----------------

    public function test_el_token_solo_se_guarda_como_sha256_y_solo_vale_por_cabecera_bearer(): void
    {
        $token = $this->tokenDe(self::CLIENTE);

        $this->assertSame(1, DB::table('confirmacion_operacion')->where('comando', 'SESION_WEB')->where('codigo_hash', hash('sha256', $token))->count());
        $this->assertSame(0, DB::table('confirmacion_operacion')->whereRaw('codigo_hash = ? OR parametros::text LIKE ?', [$token, "%{$token}%"])->count());
        $this->assertSame(0, DB::table('bitacora')->whereRaw('detalle::text LIKE ?', ["%{$token}%"])->count());

        $this->api('GET', '/api/auth/yo', [], $token)->assertOk()->assertJsonPath('usuario.correo', self::CLIENTE);
        $this->api('GET', '/api/auth/yo?token='.$token)->assertStatus(401);
        $this->api('GET', '/api/auth/yo?api_token='.$token)->assertStatus(401);
        $this->api('GET', '/api/auth/yo', [], null, ['Authorization' => 'Basic '.base64_encode($token.':')])->assertStatus(401);
        $this->api('GET', '/api/auth/yo', [], null, ['Authorization' => $token])->assertStatus(401);
    }

    public function test_tokens_inventados_alterados_o_con_otro_formato_no_abren_sesion(): void
    {
        $token = $this->tokenDe(self::CLIENTE);
        $alterado = substr($token, 0, 63).($token[63] === 'a' ? 'b' : 'a');

        foreach ([bin2hex(random_bytes(32)), $alterado, strtoupper($token), substr($token, 0, 32), $token.'00', 'null', ''] as $malo) {
            $this->api('GET', '/api/auth/yo', [], null, ['Authorization' => 'Bearer '.$malo])->assertStatus(401);
            $this->api('GET', '/api/mis-pedidos', [], null, ['Authorization' => 'Bearer '.$malo])->assertStatus(401);
        }
        // En una ruta pública, un token inválido se trata como visitante.
        $this->api('GET', '/api/menu', [], null, ['Authorization' => 'Bearer '.$alterado])->assertOk()->assertJsonPath('rol', 'VISITANTE');
    }

    public function test_un_token_vencido_deja_de_servir(): void
    {
        $token = $this->tokenDe(self::CLIENTE);
        $this->api('GET', '/api/auth/yo', [], $token)->assertOk();

        // La sesión se abrió hace 9 horas y venció hace 1 (se respeta expira_en > creada_en).
        DB::update("UPDATE confirmacion_operacion SET creada_en = now() - interval '9 hours', expira_en = now() - interval '1 hour' WHERE codigo_hash = ?", [hash('sha256', $token)]);

        $this->api('GET', '/api/auth/yo', [], $token)->assertStatus(401);
        $this->api('GET', '/api/mis-pedidos', [], $token)->assertStatus(401);
        $this->api('POST', '/api/auth/logout', [], $token)->assertStatus(401);
    }

    public function test_cerrar_sesion_invalida_solo_ese_token(): void
    {
        $uno = $this->tokenDe(self::CLIENTE);
        $otro = $this->tokenDe(self::CLIENTE);

        $this->api('POST', '/api/auth/logout', [], $uno)->assertOk();

        $this->api('GET', '/api/auth/yo', [], $uno)->assertStatus(401);
        $this->api('GET', '/api/auth/yo', [], $otro)->assertOk();
    }

    // ---------------- Rol revocado y cuenta desactivada ----------------

    public function test_revocar_un_rol_cierra_al_instante_todas_las_sesiones_de_la_cuenta(): void
    {
        $clave = $this->contrasenaFicticia();
        $cuenta = $this->crearCuenta(['PERSONAL_FEDER', 'CLIENTE'], $clave);
        $comoPersonal = $this->tokenDe($cuenta->correo, 'PERSONAL_FEDER');
        $comoCliente = $this->tokenDe($cuenta->correo, 'CLIENTE');
        $this->api('GET', '/api/gestion/pedidos', [], $comoPersonal)->assertOk();

        $this->api('POST', "/api/admin/usuarios/{$cuenta->id}/roles/PERSONAL_FEDER/revocar", ['motivo' => 'Fin de contrato'], $this->tokenDe(self::ADMIN))->assertOk();

        $this->api('GET', '/api/gestion/pedidos', [], $comoPersonal)->assertStatus(401);
        $this->api('GET', '/api/mis-pedidos', [], $comoCliente)->assertStatus(401);
        $this->assertSame(0, DB::table('confirmacion_operacion')->where('usuario_id', $cuenta->id)->where('comando', 'SESION_WEB')->whereNull('usada_en')->count());

        // Puede volver a entrar, pero solo con el rol que conserva.
        $ingreso = $this->api('POST', '/api/auth/login', ['correo' => $cuenta->correo, 'password' => $clave, 'rol_id' => $this->rolId('PERSONAL_FEDER')])->assertOk();
        $this->assertSame('CLIENTE', $ingreso->json('rol_activo.codigo'));
        $this->assertSame(['CLIENTE'], array_column($ingreso->json('roles'), 'codigo'));
        $this->api('GET', '/api/gestion/pedidos', [], $ingreso->json('token'))->assertStatus(403);
    }

    public function test_una_cuenta_sin_ningun_rol_vigente_no_puede_entrar(): void
    {
        $clave = $this->contrasenaFicticia();
        $cuenta = $this->crearCuenta(['PERSONAL_FEDER'], $clave);
        $this->api('POST', "/api/admin/usuarios/{$cuenta->id}/roles/PERSONAL_FEDER/revocar", ['motivo' => 'Fin de contrato'], $this->tokenDe(self::ADMIN))->assertOk();

        $respuesta = $this->api('POST', '/api/auth/login', ['correo' => $cuenta->correo, 'password' => $clave]);

        $respuesta->assertStatus(403);
        $this->assertStringContainsString('rol vigente', $respuesta->json('message'));
        $this->assertSame('SIN_ROL_VIGENTE', $this->ultimaBitacora('USUARIO', $cuenta->id)->detalle['motivo']);
    }

    public function test_un_rol_revocado_directamente_en_la_base_tambien_deja_de_valer(): void
    {
        $cuenta = $this->crearCuenta(['PERSONAL_FEDER', 'CLIENTE']);
        $comoPersonal = $this->tokenDe($cuenta->correo, 'PERSONAL_FEDER');
        $comoCliente = $this->tokenDe($cuenta->correo, 'CLIENTE');

        // Revocación hecha fuera de la web (por ejemplo, por el canal de correo del Proyecto 1): las sesiones no se cierran...
        DB::table('usuario_rol')->where('usuario_id', $cuenta->id)->where('rol_id', $this->rolId('PERSONAL_FEDER'))
            ->update(['estado' => 'REVOCADA', 'fecha_revocacion' => DB::raw('now()'), 'revocado_por_id' => $this->usuarioId(self::ADMIN), 'motivo_revocacion' => 'Revocado fuera de la web']);

        // ...pero cada petición vuelve a comprobar el rol activo.
        $this->api('GET', '/api/gestion/pedidos', [], $comoPersonal)->assertStatus(401);
        $this->api('GET', '/api/mis-pedidos', [], $comoCliente)->assertOk();
    }

    public function test_desactivar_la_cuenta_cierra_la_sesion_y_reactivarla_no_la_revive(): void
    {
        $clave = $this->contrasenaFicticia();
        $cuenta = $this->crearCuenta(['CLIENTE'], $clave);
        $token = $this->tokenDe($cuenta->correo);
        $admin = $this->tokenDe(self::ADMIN);

        $this->api('PATCH', "/api/admin/usuarios/{$cuenta->id}/activo", ['activo' => false], $admin)->assertOk();

        $this->api('GET', '/api/auth/yo', [], $token)->assertStatus(401);
        $inactiva = $this->api('POST', '/api/auth/login', ['correo' => $cuenta->correo, 'password' => $clave])->assertStatus(403);
        $this->assertStringContainsString('inactiva', $inactiva->json('message'));

        $this->api('PATCH', "/api/admin/usuarios/{$cuenta->id}/activo", ['activo' => true], $admin)->assertOk();
        $this->api('GET', '/api/auth/yo', [], $token)->assertStatus(401);
        $this->api('POST', '/api/auth/login', ['correo' => $cuenta->correo, 'password' => $clave])->assertOk();
    }

    public function test_una_cuenta_desactivada_directamente_en_la_base_no_opera(): void
    {
        $cuenta = $this->crearCuenta(['CLIENTE']);
        $token = $this->tokenDe($cuenta->correo);

        DB::table('usuario')->where('id', $cuenta->id)->update(['activo' => false]);

        $this->api('GET', '/api/mis-pedidos', [], $token)->assertStatus(401);
        $this->api('POST', '/api/carrito', ['producto_id' => 1, 'cantidad' => 1], $token)->assertStatus(401);
        $this->assertSame(0, DB::table('item_carrito')->where('cliente_id', $cuenta->id)->count());
    }

    // ---------------- Cuentas con varios roles ----------------

    public function test_los_permisos_salen_solo_del_rol_activo(): void
    {
        $token = $this->tokenDe(self::MULTIRROL, 'PERSONAL_FEDER');

        $this->api('GET', '/api/gestion/pedidos', [], $token)->assertOk();
        $this->api('GET', '/api/mis-pedidos', [], $token)->assertStatus(403);
        $this->api('POST', '/api/carrito', ['producto_id' => 1, 'cantidad' => 1], $token)->assertStatus(403);

        $cambio = $this->api('POST', '/api/auth/rol-activo', ['rol_id' => $this->rolId('CLIENTE')], $token)->assertOk();
        $this->assertSame('CLIENTE', $cambio->json('rol_activo.codigo'));
        $this->assertContains('carrito.usar', $cambio->json('permisos'));
        $this->assertNotContains('pedidos.gestionar', $cambio->json('permisos'));

        $this->api('GET', '/api/mis-pedidos', [], $token)->assertOk();
        $this->api('GET', '/api/gestion/pedidos', [], $token)->assertStatus(403);
        $this->api('GET', '/api/menu', [], $token)->assertOk()->assertJsonPath('rol', 'CLIENTE');
    }

    public function test_ninguna_cabecera_ni_parametro_cambia_el_rol_activo(): void
    {
        $token = $this->tokenDe(self::MULTIRROL, 'CLIENTE');
        $personal = $this->rolId('PERSONAL_FEDER');

        foreach (['X-Rol-Activo', 'X-Rol', 'X-Rol-Id', 'Rol-Activo', 'X-Rol-Codigo'] as $cabecera) {
            $this->api('GET', '/api/gestion/pedidos', [], $token, [$cabecera => (string) $personal])->assertStatus(403);
            $this->api('GET', '/api/gestion/pedidos', [], $token, [$cabecera => 'PERSONAL_FEDER'])->assertStatus(403);
        }
        $this->api('GET', "/api/gestion/pedidos?rol_id={$personal}&rol=PERSONAL_FEDER", [], $token)->assertStatus(403);
        $this->api('GET', '/api/auth/yo', [], $token)->assertOk()->assertJsonPath('rol_activo.codigo', 'CLIENTE');
    }

    public function test_dos_sesiones_de_la_misma_cuenta_conservan_cada_una_su_rol(): void
    {
        $comoPersonal = $this->tokenDe(self::MULTIRROL, 'PERSONAL_FEDER');
        $comoCliente = $this->tokenDe(self::MULTIRROL, 'CLIENTE');

        $this->api('POST', '/api/auth/rol-activo', ['rol_id' => $this->rolId('PERSONAL_FEDER')], $comoPersonal)->assertOk();

        $this->api('GET', '/api/auth/yo', [], $comoPersonal)->assertOk()->assertJsonPath('rol_activo.codigo', 'PERSONAL_FEDER');
        $this->api('GET', '/api/auth/yo', [], $comoCliente)->assertOk()->assertJsonPath('rol_activo.codigo', 'CLIENTE');
        $this->api('GET', '/api/gestion/pedidos', [], $comoCliente)->assertStatus(403);
    }

    public function test_solo_se_puede_activar_un_rol_vigente_de_la_propia_cuenta(): void
    {
        $token = $this->tokenDe(self::MULTIRROL, 'CLIENTE');

        $this->api('POST', '/api/auth/rol-activo', ['rol_id' => $this->rolId('ADMINISTRADOR')], $token)->assertStatus(403);
        $this->api('POST', '/api/auth/rol-activo', ['rol_id' => $this->rolId('PRODUCTOR')], $token)->assertStatus(403);
        $this->api('POST', '/api/auth/rol-activo', ['rol_id' => 999], $token)->assertStatus(403);
        $this->api('POST', '/api/auth/rol-activo', ['rol_id' => 'ADMINISTRADOR'], $token)->assertStatus(422);
        $this->api('GET', '/api/admin/usuarios', [], $token)->assertStatus(403);
        $this->api('GET', '/api/auth/yo', [], $token)->assertOk()->assertJsonPath('rol_activo.codigo', 'CLIENTE');
    }

    public function test_un_rol_recien_asignado_no_se_usa_hasta_que_la_persona_lo_activa(): void
    {
        $cuenta = $this->crearCuenta(['CLIENTE']);
        $token = $this->tokenDe($cuenta->correo);

        $this->api('POST', "/api/admin/usuarios/{$cuenta->id}/roles", ['rol' => 'PERSONAL_FEDER'], $this->tokenDe(self::ADMIN))->assertOk();

        $yo = $this->api('GET', '/api/auth/yo', [], $token)->assertOk();
        $this->assertSame('CLIENTE', $yo->json('rol_activo.codigo'));
        $this->assertEqualsCanonicalizing(['PERSONAL_FEDER', 'CLIENTE'], array_column($yo->json('roles'), 'codigo'));
        $this->api('GET', '/api/gestion/pedidos', [], $token)->assertStatus(403);

        $this->api('POST', '/api/auth/rol-activo', ['rol_id' => $this->rolId('PERSONAL_FEDER')], $token)->assertOk();
        $this->api('GET', '/api/gestion/pedidos', [], $token)->assertOk();
    }

    public function test_al_entrar_se_usa_el_rol_pedido_si_es_vigente_y_si_no_el_de_mayor_prioridad(): void
    {
        $clave = $this->contrasenaFicticia();
        $cuenta = $this->crearCuenta(['PERSONAL_FEDER', 'CLIENTE'], $clave);
        $credenciales = ['correo' => $cuenta->correo, 'password' => $clave];

        $this->api('POST', '/api/auth/login', $credenciales)->assertOk()->assertJsonPath('rol_activo.codigo', 'PERSONAL_FEDER');
        $this->api('POST', '/api/auth/login', $credenciales + ['rol_id' => $this->rolId('CLIENTE')])->assertOk()->assertJsonPath('rol_activo.codigo', 'CLIENTE');
        $this->api('POST', '/api/auth/login', $credenciales + ['rol_id' => $this->rolId('ADMINISTRADOR')])->assertOk()->assertJsonPath('rol_activo.codigo', 'PERSONAL_FEDER');
    }

    // ---------------- Cambios de permisos con la sesión abierta ----------------

    public function test_un_cambio_de_permisos_se_aplica_en_la_siguiente_peticion_de_las_sesiones_abiertas(): void
    {
        $admin = $this->tokenDe(self::ADMIN);
        $personal = $this->tokenDe(self::PERSONAL);
        $original = $this->permisosGuardados('PERSONAL_FEDER');
        $this->api('GET', '/api/gestion/inventario', [], $personal)->assertOk();

        $this->api('PUT', '/api/admin/permisos/PERSONAL_FEDER', ['permisos' => array_values(array_diff($original, ['inventario.ver']))], $admin)->assertOk();

        $this->api('GET', '/api/gestion/inventario', [], $personal)->assertStatus(403);
        $this->assertNotContains('inventario.ver', $this->api('GET', '/api/auth/yo', [], $personal)->json('permisos'));
        $rutasMenu = collect($this->api('GET', '/api/menu', [], $personal)->json('grupos'))->flatMap(fn ($g) => array_column($g['entradas'], 'ruta'));
        $this->assertNotContains('/gestion/inventario', $rutasMenu);
        // El resto de su trabajo sigue igual y otros roles no cambian.
        $this->api('GET', '/api/gestion/pedidos', [], $personal)->assertOk();
        $this->api('GET', '/api/gestion/inventario', [], $admin)->assertOk();

        $this->api('PUT', '/api/admin/permisos/PERSONAL_FEDER', ['permisos' => $original], $admin)->assertOk();
        $this->api('GET', '/api/gestion/inventario', [], $personal)->assertOk();

        $bitacora = $this->ultimaBitacora('PERMISO_ROL');
        $this->assertSame(['inventario.ver'], $bitacora->detalle['agregados']);
    }

    public function test_quitar_un_permiso_propio_a_los_clientes_les_cierra_esa_funcion_al_instante(): void
    {
        $admin = $this->tokenDe(self::ADMIN);
        $cliente = $this->tokenDe(self::CLIENTE);
        $original = $this->permisosGuardados('CLIENTE');

        $this->api('PUT', '/api/admin/permisos/CLIENTE', ['permisos' => array_values(array_diff($original, ['carrito.usar']))], $admin)->assertOk();

        $this->api('GET', '/api/carrito', [], $cliente)->assertStatus(403);
        $this->api('POST', '/api/carrito', ['producto_id' => 1, 'cantidad' => 1], $cliente)->assertStatus(403);
        $this->api('GET', '/api/mis-pedidos', [], $cliente)->assertOk();
    }

    public function test_el_administrador_no_puede_darse_roles_desactivarse_ni_quitarse_su_rol(): void
    {
        $admin = $this->tokenDe(self::ADMIN);
        $id = $this->usuarioId(self::ADMIN);

        $this->api('POST', "/api/admin/usuarios/{$id}/roles", ['rol' => 'CLIENTE'], $admin)->assertStatus(422);
        $this->api('PATCH', "/api/admin/usuarios/{$id}/activo", ['activo' => false], $admin)->assertStatus(422);
        $this->api('POST', "/api/admin/usuarios/{$id}/roles/ADMINISTRADOR/revocar", ['motivo' => 'Prueba'], $admin)->assertStatus(422);

        $this->assertSame(1, $this->administradoresActivos());
        $this->api('GET', '/api/admin/usuarios', [], $admin)->assertOk();
    }
}
