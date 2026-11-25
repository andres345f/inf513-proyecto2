<?php

namespace Tests\Integracion\Acceso;

use App\Exceptions\NegocioException;
use App\Repositories\UsuarioRepository;
use App\Services\AdminUsuarioService;
use Illuminate\Support\Facades\DB;

/**
 * H-17 · R102 «Último administrador»: siempre queda al menos una cuenta activa con ADMINISTRADOR vigente.
 *
 * Estas pruebas cubren la regla en una sola conexión. La carrera real (dos administradores que se desactivan
 * a la vez desde dos instancias) se prueba con laboratorio\correcciones\c_acceso\h17_ultimo_administrador.ps1.
 */
class UltimoAdministradorTest extends AccesoTestCase
{
    public function test_quien_gestiona_usuarios_sin_ser_administrador_no_puede_desactivar_al_ultimo_administrador(): void
    {
        // La concesión histórica es inadmisible para Personal y se descarta antes de entrar al servicio.
        $this->fijarPermisos('PERSONAL_FEDER', [...$this->permisosGuardados('PERSONAL_FEDER'), 'usuarios.gestionar']);
        $this->assertSame(1, $this->administradoresActivos());

        $respuesta = $this->api('PATCH', '/api/admin/usuarios/'.$this->usuarioId(self::ADMIN).'/activo', ['activo' => false], $this->tokenDe(self::PERSONAL));

        $respuesta->assertForbidden();
        $this->assertSame(1, $this->administradoresActivos());
        $this->assertTrue((bool) DB::table('usuario')->where('correo', self::ADMIN)->value('activo'));
    }

    public function test_tampoco_puede_revocar_el_rol_del_ultimo_administrador(): void
    {
        $this->fijarPermisos('PERSONAL_FEDER', [...$this->permisosGuardados('PERSONAL_FEDER'), 'usuarios.gestionar']);

        $respuesta = $this->api('POST', '/api/admin/usuarios/'.$this->usuarioId(self::ADMIN).'/roles/ADMINISTRADOR/revocar',
            ['motivo' => 'Prueba de la regla del último administrador'], $this->tokenDe(self::PERSONAL));

        $respuesta->assertForbidden();
        $this->assertSame(1, $this->administradoresActivos());
        $this->assertSame('VIGENTE', DB::table('usuario_rol')->where('usuario_id', $this->usuarioId(self::ADMIN))
            ->where('rol_id', $this->rolId('ADMINISTRADOR'))->orderByDesc('id')->value('estado'));
    }

    public function test_con_dos_administradores_uno_puede_desactivar_al_otro_pero_no_queda_el_sistema_sin_ninguno(): void
    {
        $segundo = $this->crearCuenta(['ADMINISTRADOR']);
        $original = $this->usuarioId(self::ADMIN);
        $this->assertSame(2, $this->administradoresActivos());

        // El segundo desactiva al original: permitido, queda él.
        $this->api('PATCH', "/api/admin/usuarios/{$original}/activo", ['activo' => false], $this->tokenDe($segundo->correo))->assertOk();
        $this->assertSame(1, $this->administradoresActivos());

        // Nadie puede desactivarse a sí mismo y, como es el último, tampoco puede hacerlo otra cuenta con ese permiso.
        $this->api('PATCH', "/api/admin/usuarios/{$segundo->id}/activo", ['activo' => false], $this->tokenDe($segundo->correo))->assertStatus(422);
        $this->fijarPermisos('PERSONAL_FEDER', [...$this->permisosGuardados('PERSONAL_FEDER'), 'usuarios.gestionar']);
        $this->api('PATCH', "/api/admin/usuarios/{$segundo->id}/activo", ['activo' => false], $this->tokenDe(self::PERSONAL))->assertForbidden();
        $this->assertSame(1, $this->administradoresActivos());
    }

    public function test_una_cuenta_inactiva_con_rol_vigente_no_cuenta_como_administrador(): void
    {
        // Segundo administrador con la concesión vigente pero la cuenta inactiva: no protege al sistema.
        $this->crearCuenta(['ADMINISTRADOR'], activo: false);
        $this->fijarPermisos('PERSONAL_FEDER', [...$this->permisosGuardados('PERSONAL_FEDER'), 'usuarios.gestionar']);

        $this->api('PATCH', '/api/admin/usuarios/'.$this->usuarioId(self::ADMIN).'/activo', ['activo' => false], $this->tokenDe(self::PERSONAL))
            ->assertForbidden();
        $this->assertSame(1, $this->administradoresActivos());
    }

    public function test_operaciones_administrativas_en_vuelo_respetan_la_ultima_cuenta_activa(): void
    {
        $actor = $this->crearCuenta(['ADMINISTRADOR']);
        $ultimoId = $this->usuarioId(self::ADMIN);
        $this->api('PATCH', "/api/admin/usuarios/{$actor->id}/activo", ['activo' => false], $this->tokenDe(self::ADMIN))->assertOk();
        // El actor entró como administrador antes de su desactivación. Tras obtener el bloqueo R102
        // se relee el número de administradores activos, incluso si el objeto del actor está desactualizado.
        $servicio = app(AdminUsuarioService::class);
        foreach ([
            fn () => $servicio->cambiarActivo($actor, $ultimoId, false, null),
            fn () => $servicio->revocarRol($actor, $ultimoId, 'ADMINISTRADOR', 'Fin de la suplencia', null),
        ] as $operacion) {
            try {
                $operacion();
                $this->fail('La operación dejó a FEDER sin un administrador.');
            } catch (NegocioException $e) {
                $this->assertSame(422, $e->estadoHttp());
                $this->assertStringContainsString('última cuenta activa', $e->getMessage());
            }
            $this->assertSame(1, $this->administradoresActivos());
        }
    }

    public function test_revocar_administrador_se_permite_mientras_quede_otro_activo(): void
    {
        $segundo = $this->crearCuenta(['ADMINISTRADOR', 'CLIENTE']);
        $token = $this->tokenDe($segundo->correo, 'ADMINISTRADOR');
        $usuarios = app(UsuarioRepository::class);
        $this->assertTrue($usuarios->esAdministradorActivo($segundo->id));

        $this->api('POST', "/api/admin/usuarios/{$segundo->id}/roles/ADMINISTRADOR/revocar", ['motivo' => 'Fin de la suplencia'], $this->tokenDe(self::ADMIN))
            ->assertOk();

        $this->assertSame(1, $this->administradoresActivos());
        $this->assertTrue($usuarios->tieneRolVigente($segundo->id, 'CLIENTE'));
        $this->assertFalse($usuarios->esAdministradorActivo($segundo->id));
        // La sesión del administrador revocado deja de valer en la siguiente petición.
        $this->api('GET', '/api/admin/usuarios', [], $token)->assertStatus(401);
    }

    public function test_desactivar_una_cuenta_que_no_es_administradora_sigue_funcionando(): void
    {
        $cliente = $this->crearCuenta(['CLIENTE']);
        $token = $this->tokenDe($cliente->correo);

        $this->api('PATCH', "/api/admin/usuarios/{$cliente->id}/activo", ['activo' => false], $this->tokenDe(self::ADMIN))->assertOk();

        $this->assertFalse((bool) DB::table('usuario')->where('id', $cliente->id)->value('activo'));
        $this->api('GET', '/api/auth/yo', [], $token)->assertStatus(401);
        $this->assertSame(1, $this->administradoresActivos());
    }

    public function test_quien_actua_debe_seguir_habilitado_al_momento_de_aplicar_el_cambio(): void
    {
        // Petición «en vuelo»: el administrador ya fue desactivado cuando su operación llega al servicio.
        $segundo = $this->crearCuenta(['ADMINISTRADOR']);
        $cliente = $this->crearCuenta(['CLIENTE']);
        $servicio = app(\App\Services\AdminUsuarioService::class);
        DB::table('usuario')->where('id', $segundo->id)->update(['activo' => false]);

        try {
            $servicio->cambiarActivo($segundo, $cliente->id, false, '127.0.0.1');
            $this->fail('Una cuenta ya desactivada pudo desactivar a otra.');
        } catch (\App\Exceptions\NegocioException $e) {
            $this->assertSame(401, $e->estadoHttp());
        }
        $this->assertTrue((bool) DB::table('usuario')->where('id', $cliente->id)->value('activo'));
    }
}
