<?php

namespace Tests\Integracion\Acceso;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * H-02 (1): PUT /api/auth/contrasena. Pide la contraseña actual, aplica la política, cierra las DEMÁS sesiones,
 * deja bitácora sin el valor y tiene límite de intentos. Todas las contraseñas se generan en la prueba.
 */
class CambioContrasenaTest extends AccesoTestCase
{
    public function test_cambia_la_contrasena_cierra_las_demas_sesiones_y_deja_bitacora_sin_el_valor(): void
    {
        $actual = $this->contrasenaFicticia();
        $nueva = $this->contrasenaFicticia();
        $cuenta = $this->crearCuenta(['CLIENTE'], $actual);
        $estaSesion = $this->tokenDe($cuenta->correo);
        $otraSesion = $this->tokenDe($cuenta->correo);
        $terceraSesion = $this->tokenDe($cuenta->correo);

        $respuesta = $this->api('PUT', '/api/auth/contrasena', [
            'contrasena_actual' => $actual, 'contrasena_nueva' => $nueva, 'contrasena_nueva_confirmation' => $nueva,
        ], $estaSesion);

        $respuesta->assertOk()->assertJsonPath('sesiones_cerradas', 2);
        $this->assertStringContainsString('contraseña', mb_strtolower($respuesta->json('message')));
        $this->assertStringNotContainsString($nueva, $respuesta->getContent());

        $hash = DB::table('usuario')->where('id', $cuenta->id)->value('password_hash');
        $this->assertSame('bcrypt', password_get_info($hash)['algoName']);
        $this->assertTrue(Hash::check($nueva, $hash));
        $this->assertFalse(Hash::check($actual, $hash));

        // La sesión con la que se hizo el cambio sigue abierta; las otras, no.
        $this->api('GET', '/api/auth/yo', [], $estaSesion)->assertOk();
        $this->api('GET', '/api/auth/yo', [], $otraSesion)->assertStatus(401);
        $this->api('GET', '/api/auth/yo', [], $terceraSesion)->assertStatus(401);

        $bitacora = $this->ultimaBitacora('USUARIO', $cuenta->id);
        $this->assertSame('MODIFICAR', $bitacora->accion);
        $this->assertSame($cuenta->id, (int) $bitacora->usuario_id);
        $this->assertSame('CAMBIAR_CONTRASENA', $bitacora->detalle['operacion']);
        $this->assertSame(2, $bitacora->detalle['sesiones_cerradas']);
        $texto = json_encode($bitacora->detalle);
        foreach ([$actual, $nueva, $hash] as $secreto) {
            $this->assertStringNotContainsString($secreto, $texto);
        }

        // Se entra con la nueva y ya no con la anterior.
        $this->api('POST', '/api/auth/login', ['correo' => $cuenta->correo, 'password' => $actual])->assertStatus(422);
        $this->api('POST', '/api/auth/login', ['correo' => $cuenta->correo, 'password' => $nueva])->assertOk();
    }

    public function test_con_la_contrasena_actual_equivocada_no_cambia_nada(): void
    {
        $actual = $this->contrasenaFicticia();
        $nueva = $this->contrasenaFicticia();
        $cuenta = $this->crearCuenta(['CLIENTE'], $actual);
        $estaSesion = $this->tokenDe($cuenta->correo);
        $otraSesion = $this->tokenDe($cuenta->correo);
        $hash = DB::table('usuario')->where('id', $cuenta->id)->value('password_hash');

        $respuesta = $this->api('PUT', '/api/auth/contrasena', [
            'contrasena_actual' => $this->contrasenaFicticia(), 'contrasena_nueva' => $nueva, 'contrasena_nueva_confirmation' => $nueva,
        ], $estaSesion);

        $respuesta->assertStatus(422)->assertJsonPath('errors.contrasena_actual.0', 'La contraseña actual no es correcta.');
        $this->assertSame($hash, DB::table('usuario')->where('id', $cuenta->id)->value('password_hash'));
        $this->api('GET', '/api/auth/yo', [], $otraSesion)->assertOk();
        $bitacora = $this->ultimaBitacora('USUARIO', $cuenta->id);
        $this->assertSame('CAMBIAR_CONTRASENA', $bitacora->detalle['operacion']);
        $this->assertSame('FALLIDO', $bitacora->detalle['resultado']);
    }

    public function test_politica_de_la_contrasena_nueva(): void
    {
        $actual = $this->contrasenaFicticia();
        $cuenta = $this->crearCuenta(['CLIENTE'], $actual);
        $token = $this->tokenDe($cuenta->correo);
        $hash = DB::table('usuario')->where('id', $cuenta->id)->value('password_hash');
        $buena = $this->contrasenaFicticia();
        $enviar = fn (array $datos) => $this->api('PUT', '/api/auth/contrasena', $datos, $token);

        $enviar([])->assertStatus(422)->assertJsonValidationErrors(['contrasena_actual', 'contrasena_nueva']);
        $enviar(['contrasena_actual' => $actual, 'contrasena_nueva' => $buena])
            ->assertStatus(422)->assertJsonPath('errors.contrasena_nueva.0', 'Las contraseñas nuevas no coinciden.');
        $enviar(['contrasena_actual' => $actual, 'contrasena_nueva' => $buena, 'contrasena_nueva_confirmation' => $buena.'x'])
            ->assertStatus(422)->assertJsonPath('errors.contrasena_nueva.0', 'Las contraseñas nuevas no coinciden.');
        $enviar(['contrasena_actual' => $actual, 'contrasena_nueva' => 'ab12', 'contrasena_nueva_confirmation' => 'ab12'])
            ->assertStatus(422)->assertJsonPath('errors.contrasena_nueva.0', 'La contraseña nueva debe tener al menos 8 caracteres.');
        $enviar(['contrasena_actual' => $actual, 'contrasena_nueva' => 'solamenteletras', 'contrasena_nueva_confirmation' => 'solamenteletras'])
            ->assertStatus(422)->assertJsonPath('errors.contrasena_nueva.0', 'La contraseña nueva debe combinar letras y números.');
        $enviar(['contrasena_actual' => $actual, 'contrasena_nueva' => '1234567890', 'contrasena_nueva_confirmation' => '1234567890'])
            ->assertStatus(422)->assertJsonPath('errors.contrasena_nueva.0', 'La contraseña nueva debe combinar letras y números.');
        $enviar(['contrasena_actual' => $actual, 'contrasena_nueva' => $actual, 'contrasena_nueva_confirmation' => $actual])
            ->assertStatus(422)->assertJsonPath('errors.contrasena_nueva.0', 'La contraseña nueva debe ser distinta de la actual.');
        // bcrypt solo usa los primeros 72 bytes: no se aceptan contraseñas más largas (las tildes ocupan 2 bytes).
        $larga = str_repeat('ñ', 36).'a1';
        $enviar(['contrasena_actual' => $actual, 'contrasena_nueva' => $larga, 'contrasena_nueva_confirmation' => $larga])
            ->assertStatus(422)->assertJsonValidationErrors(['contrasena_nueva']);
        $enviar(['contrasena_actual' => [$actual], 'contrasena_nueva' => [$buena], 'contrasena_nueva_confirmation' => [$buena]])
            ->assertStatus(422)->assertJsonValidationErrors(['contrasena_actual', 'contrasena_nueva']);

        $this->assertSame($hash, DB::table('usuario')->where('id', $cuenta->id)->value('password_hash'));
    }

    public function test_exige_sesion_y_no_depende_de_la_matriz_de_permisos(): void
    {
        $actual = $this->contrasenaFicticia();
        $nueva = $this->contrasenaFicticia();
        $datos = ['contrasena_actual' => $actual, 'contrasena_nueva' => $nueva, 'contrasena_nueva_confirmation' => $nueva];

        $this->api('PUT', '/api/auth/contrasena', $datos)->assertStatus(401);

        // Aunque al rol le quiten todos los permisos, la persona puede seguir cambiando su contraseña.
        $cuenta = $this->crearCuenta(['CLIENTE'], $actual);
        $this->fijarPermisos('CLIENTE', []);
        $this->api('PUT', '/api/auth/contrasena', $datos, $this->tokenDe($cuenta->correo))->assertOk();
    }

    public function test_tiene_limite_de_intentos_por_cuenta(): void
    {
        $actual = $this->contrasenaFicticia();
        $nueva = $this->contrasenaFicticia();
        $cuenta = $this->crearCuenta(['CLIENTE'], $actual);
        $token = $this->tokenDe($cuenta->correo);
        $hash = DB::table('usuario')->where('id', $cuenta->id)->value('password_hash');

        $codigos = [];
        for ($i = 1; $i <= 6; $i++) {
            $codigos[] = $this->api('PUT', '/api/auth/contrasena', [
                'contrasena_actual' => $this->contrasenaFicticia(), 'contrasena_nueva' => $nueva, 'contrasena_nueva_confirmation' => $nueva,
            ], $token)->status();
        }

        $this->assertSame([422, 422, 422, 422, 422, 429], $codigos);
        // Ni siquiera con la contraseña correcta se atiende el intento que supera el límite.
        $this->api('PUT', '/api/auth/contrasena', ['contrasena_actual' => $actual, 'contrasena_nueva' => $nueva, 'contrasena_nueva_confirmation' => $nueva], $token)
            ->assertStatus(429);
        $this->assertSame($hash, DB::table('usuario')->where('id', $cuenta->id)->value('password_hash'));

        // El límite es por cuenta: otra persona desde la misma IP no queda bloqueada.
        $otraClave = $this->contrasenaFicticia();
        $otra = $this->crearCuenta(['CLIENTE'], $otraClave);
        $this->api('PUT', '/api/auth/contrasena', ['contrasena_actual' => $otraClave, 'contrasena_nueva' => $nueva, 'contrasena_nueva_confirmation' => $nueva], $this->tokenDe($otra->correo))
            ->assertOk();
    }

    public function test_no_se_puede_elegir_la_contrasena_de_demostracion_como_nueva(): void
    {
        // Se simula una «contraseña de demostración» propia de la prueba; la real nunca se escribe aquí.
        $demostracion = $this->contrasenaFicticia();
        config(['feder.hashes_demostracion' => [Hash::make($demostracion)]]);
        $actual = $this->contrasenaFicticia();
        $cuenta = $this->crearCuenta(['CLIENTE'], $actual);

        $respuesta = $this->api('PUT', '/api/auth/contrasena', [
            'contrasena_actual' => $actual, 'contrasena_nueva' => $demostracion, 'contrasena_nueva_confirmation' => $demostracion,
        ], $this->tokenDe($cuenta->correo));

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['contrasena_nueva']);
        $this->assertStringContainsString('demostración', $respuesta->json('errors.contrasena_nueva.0'));
    }

    public function test_una_cuenta_sin_contrasena_web_no_puede_definirla_por_esta_via(): void
    {
        // Cuenta del canal de correo: su password_hash no es bcrypt (valor inerte), así que no tiene «contraseña actual».
        $cuenta = $this->crearCuenta(['CLIENTE']);
        DB::table('usuario')->where('id', $cuenta->id)->update(['password_hash' => 'SIN-CONTRASENA-WEB']);
        $nueva = $this->contrasenaFicticia();

        $this->api('PUT', '/api/auth/contrasena', [
            'contrasena_actual' => 'SIN-CONTRASENA-WEB', 'contrasena_nueva' => $nueva, 'contrasena_nueva_confirmation' => $nueva,
        ], $this->tokenDe($cuenta->correo))->assertStatus(422)->assertJsonValidationErrors(['contrasena_actual']);

        $this->assertSame('SIN-CONTRASENA-WEB', DB::table('usuario')->where('id', $cuenta->id)->value('password_hash'));
    }
}
