<?php

namespace Tests\Integracion\Acceso;

use Illuminate\Support\Facades\DB;

/**
 * H-22 (parte de acceso): el cierre de sesión y el cambio de rol activo dejan constancia en la bitácora,
 * con la acción nativa MODIFICAR sobre la fila de la sesión y sin guardar el token.
 */
class BitacoraSesionTest extends AccesoTestCase
{
    public function test_cerrar_sesion_queda_en_la_bitacora(): void
    {
        $token = $this->tokenDe(self::CLIENTE);
        $sesionId = (int) DB::table('confirmacion_operacion')->where('codigo_hash', hash('sha256', $token))->value('id');

        $this->api('POST', '/api/auth/logout', [], $token)->assertOk();

        $fila = $this->ultimaBitacora('SESION_WEB', $sesionId);
        $this->assertNotNull($fila, 'El cierre de sesión no dejó fila en la bitácora.');
        $this->assertSame('MODIFICAR', $fila->accion);
        $this->assertSame($this->usuarioId(self::CLIENTE), (int) $fila->usuario_id);
        $this->assertSame('CIERRE_SESION', $fila->detalle['operacion']);
        $this->assertSame('CLIENTE', $fila->detalle['rol']);
        $this->assertStringNotContainsString($token, json_encode($fila->detalle));
        $this->assertStringNotContainsString(hash('sha256', $token), json_encode($fila->detalle));

        // La sesión quedó cerrada de verdad.
        $this->api('GET', '/api/auth/yo', [], $token)->assertStatus(401);
    }

    public function test_cambiar_de_rol_activo_queda_en_la_bitacora(): void
    {
        $token = $this->tokenDe(self::MULTIRROL, 'PERSONAL_FEDER');
        $sesionId = (int) DB::table('confirmacion_operacion')->where('codigo_hash', hash('sha256', $token))->value('id');

        $this->api('POST', '/api/auth/rol-activo', ['rol_id' => $this->rolId('CLIENTE')], $token)
            ->assertOk()->assertJsonPath('rol_activo.codigo', 'CLIENTE');

        $fila = $this->ultimaBitacora('SESION_WEB', $sesionId);
        $this->assertNotNull($fila, 'El cambio de rol activo no dejó fila en la bitácora.');
        $this->assertSame('MODIFICAR', $fila->accion);
        $this->assertSame('CAMBIO_ROL_ACTIVO', $fila->detalle['operacion']);
        $this->assertSame('PERSONAL_FEDER', $fila->detalle['rol_anterior']);
        $this->assertSame('CLIENTE', $fila->detalle['rol_nuevo']);
    }

    public function test_pedir_el_mismo_rol_o_un_rol_ajeno_no_deja_un_cambio_en_la_bitacora(): void
    {
        $token = $this->tokenDe(self::MULTIRROL, 'PERSONAL_FEDER');
        $antes = DB::table('bitacora')->where('entidad', 'SESION_WEB')->count();

        $this->api('POST', '/api/auth/rol-activo', ['rol_id' => $this->rolId('PERSONAL_FEDER')], $token)->assertOk();
        $this->api('POST', '/api/auth/rol-activo', ['rol_id' => $this->rolId('ADMINISTRADOR')], $token)->assertStatus(403);

        $this->assertSame($antes, DB::table('bitacora')->where('entidad', 'SESION_WEB')->count());
    }
}
