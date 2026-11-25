<?php

namespace Tests\Integracion\Acceso;

use App\Support\CredencialesDemostracion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * H-02 (3): la contraseña de demostración sirve solo para el laboratorio. Con APP_ENV=production no se inicia
 * sesión con una cuenta que conserve el hash exacto de db/03_datos_web.sql ni escribiendo esa contraseña.
 *
 * La contraseña de demostración real no aparece en estas pruebas: el bloqueo por hash se prueba con las cuentas
 * de los datos iniciales (que lo traen) y el bloqueo por contraseña con una «demostración» creada por la prueba.
 */
class ContrasenaDemostracionTest extends AccesoTestCase
{
    public function test_en_produccion_no_se_entra_con_una_cuenta_que_conserva_el_hash_de_demostracion(): void
    {
        $this->assertTrue(CredencialesDemostracion::esHashDeDemostracion(DB::table('usuario')->where('correo', self::ADMIN)->value('password_hash')));
        $this->enProduccion();
        $sesiones = DB::table('confirmacion_operacion')->where('comando', 'SESION_WEB')->count();

        $respuesta = $this->api('POST', '/api/auth/login', ['correo' => self::ADMIN, 'password' => $this->contrasenaFicticia()]);

        $respuesta->assertStatus(403);
        $this->assertStringContainsString('php artisan feder:restablecer-contrasenas', $respuesta->json('message'));
        $this->assertArrayNotHasKey('token', $respuesta->json());
        $this->assertSame($sesiones, DB::table('confirmacion_operacion')->where('comando', 'SESION_WEB')->count());
        $bitacora = $this->ultimaBitacora('USUARIO', $this->usuarioId(self::ADMIN));
        $this->assertSame('INICIO_SESION', $bitacora->accion);
        $this->assertSame('FALLIDO', $bitacora->detalle['resultado']);
        $this->assertSame('CONTRASENA_DEMOSTRACION', $bitacora->detalle['motivo']);
    }

    public function test_fuera_de_produccion_la_misma_cuenta_no_queda_bloqueada_por_esa_regla(): void
    {
        // En el laboratorio la cuenta de demostración responde como cualquier otra (aquí: contraseña incorrecta).
        $respuesta = $this->api('POST', '/api/auth/login', ['correo' => self::ADMIN, 'password' => $this->contrasenaFicticia()]);

        $respuesta->assertStatus(422)->assertJsonPath('message', 'El correo o la contraseña no son correctos.');
        $this->assertSame('CONTRASENA_INCORRECTA', $this->ultimaBitacora('USUARIO', $this->usuarioId(self::ADMIN))->detalle['motivo']);
    }

    public function test_en_produccion_una_cuenta_con_contrasena_propia_entra_con_normalidad(): void
    {
        $clave = $this->contrasenaFicticia();
        $cuenta = $this->crearCuenta(['CLIENTE'], $clave);
        $this->enProduccion();

        $this->api('POST', '/api/auth/login', ['correo' => $cuenta->correo, 'password' => $clave])->assertOk()->assertJsonStructure(['token']);
        $this->api('POST', '/api/auth/login', ['correo' => $cuenta->correo, 'password' => $this->contrasenaFicticia()])->assertStatus(422);
    }

    public function test_en_produccion_tampoco_vale_la_contrasena_de_demostracion_guardada_con_otra_sal(): void
    {
        $demostracion = $this->contrasenaFicticia();
        config(['feder.hashes_demostracion' => [Hash::make($demostracion)]]);
        // Misma contraseña, otro hash: no coincide «exactamente», pero la contraseña escrita es la de demostración.
        $cuenta = $this->crearCuenta(['CLIENTE'], $demostracion);
        $this->assertFalse(CredencialesDemostracion::esHashDeDemostracion($cuenta->password_hash));

        // Laboratorio: funciona.
        $this->api('POST', '/api/auth/login', ['correo' => $cuenta->correo, 'password' => $demostracion])->assertOk();

        $this->enProduccion();
        $respuesta = $this->api('POST', '/api/auth/login', ['correo' => $cuenta->correo, 'password' => $demostracion]);
        $respuesta->assertStatus(403);
        $this->assertStringContainsString('feder:restablecer-contrasenas', $respuesta->json('message'));
    }

    public function test_en_produccion_un_correo_inexistente_responde_como_siempre(): void
    {
        $this->enProduccion();

        $this->api('POST', '/api/auth/login', ['correo' => 'nadie.existe@feder.example', 'password' => $this->contrasenaFicticia()])
            ->assertStatus(422)->assertJsonPath('message', 'El correo o la contraseña no son correctos.');
    }

    public function test_nadie_se_registra_ni_es_dado_de_alta_con_la_contrasena_de_demostracion(): void
    {
        $demostracion = $this->contrasenaFicticia();
        config(['feder.hashes_demostracion' => [Hash::make($demostracion)]]);
        $usuarios = DB::table('usuario')->count();
        $datos = fn (string $correo) => [
            'nombres' => 'Ana', 'apellidos' => 'Prueba', 'correo' => $correo, 'ci' => random_int(900000000, 999999999),
            'telefono' => 70012345, 'password' => $demostracion, 'password_confirmation' => $demostracion, 'acepta_terminos' => true,
        ];

        $registro = $this->api('POST', '/api/auth/registro', $datos('registro.'.bin2hex(random_bytes(4)).'@feder.example'));
        $registro->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertStringContainsString('demostración', $registro->json('errors.password.0'));

        $alta = $this->api('POST', '/api/admin/usuarios', $datos('alta.'.bin2hex(random_bytes(4)).'@feder.example') + ['rol' => 'PERSONAL_FEDER'], $this->tokenDe(self::ADMIN));
        $alta->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertSame($usuarios, DB::table('usuario')->count());
    }

    public function test_el_hash_bloqueado_es_el_que_carga_el_script_de_datos_y_el_script_no_trae_la_contrasena_en_claro(): void
    {
        $ruta = base_path('../db/03_datos_web.sql');
        if (! is_file($ruta)) {
            $this->markTestSkipped('El script db/03_datos_web.sql no está junto al backend.');
        }
        $sql = file_get_contents($ruta);

        $this->assertSame(1, preg_match("/SET password_hash = '([^']+)'/", $sql, $coincidencia), 'No se encontró el hash de demostración en el script.');
        $this->assertTrue(CredencialesDemostracion::esHashDeDemostracion($coincidencia[1]), 'El hash del script y el bloqueado por la aplicación no coinciden.');

        // Ninguna palabra de los comentarios del script es la contraseña de ese hash.
        preg_match_all('/^\s*--(.*)$/m', $sql, $comentarios);
        $candidatas = collect(preg_split('/[\s(),;:«»"\']+/u', implode(' ', $comentarios[1])))
            ->flatMap(fn ($palabra) => [$palabra, rtrim($palabra, '.')])
            ->filter(fn ($palabra) => strlen($palabra) >= 6 && preg_match('/[A-Za-z]/', $palabra) && preg_match('/\d/', $palabra))
            ->unique()->values();
        $this->assertLessThan(60, $candidatas->count());
        foreach ($candidatas as $palabra) {
            $this->assertFalse(password_verify($palabra, $coincidencia[1]), 'El script todavía trae la contraseña de demostración en un comentario.');
        }
    }

    private function enProduccion(): void
    {
        $this->app['env'] = 'production';
        $this->assertTrue(app()->isProduction());
    }
}
