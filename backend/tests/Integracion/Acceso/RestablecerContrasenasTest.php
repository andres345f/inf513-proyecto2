<?php

namespace Tests\Integracion\Acceso;

use App\Support\CredencialesDemostracion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * H-02 (2): php artisan feder:restablecer-contrasenas. Genera una contraseña aleatoria fuerte por cuenta, la entrega una
 * sola vez (archivo fuera de public/ o consola), cierra sesiones, deja bitácora sin el valor y pide confirmación.
 */
class RestablecerContrasenasTest extends AccesoTestCase
{
    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = storage_path('framework/testing/credenciales-'.bin2hex(random_bytes(4)));
        mkdir($this->carpeta, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta.'/*') ?: [] as $archivo) {
            @unlink($archivo);
        }
        @rmdir($this->carpeta);
        putenv('FEDER_CONTRASENA_LABORATORIO');
        unset($_ENV['FEDER_CONTRASENA_LABORATORIO'], $_SERVER['FEDER_CONTRASENA_LABORATORIO']);
        parent::tearDown();
    }

    public function test_restablece_todas_las_cuentas_activas_y_entrega_las_contrasenas_una_sola_vez_en_un_archivo(): void
    {
        $inactiva = $this->crearCuenta(['CLIENTE'], activo: false);
        $hashInactiva = $inactiva->password_hash;
        $sesionAdmin = $this->tokenDe(self::ADMIN);
        $activas = DB::table('usuario')->where('activo', true)->orderBy('id')->pluck('correo', 'id');
        $salida = $this->carpeta.'/credenciales.txt';

        $codigo = Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--salida' => $salida]);
        $consola = Artisan::output();

        $this->assertSame(0, $codigo, $consola);
        $entregadas = $this->leerArchivo($salida);
        $this->assertEqualsCanonicalizing($activas->values()->all(), array_keys($entregadas));
        $this->assertCount($activas->count(), array_unique($entregadas), 'Hay contraseñas repetidas.');

        $hashes = DB::table('usuario')->where('activo', true)->pluck('password_hash', 'correo');
        $this->assertCount($activas->count(), $hashes->unique());
        foreach ($entregadas as $correo => $contrasena) {
            $this->assertTrue(Hash::check($contrasena, $hashes[$correo]), "La contraseña entregada de {$correo} no coincide con la guardada.");
            $this->assertFalse(CredencialesDemostracion::esHashDeDemostracion($hashes[$correo]));
            $this->assertSame('bcrypt', password_get_info($hashes[$correo])['algoName']);
            $this->assertGreaterThanOrEqual(16, strlen($contrasena));
            $this->assertMatchesRegularExpression('/[a-z]/', $contrasena);
            $this->assertMatchesRegularExpression('/[A-Z]/', $contrasena);
            $this->assertMatchesRegularExpression('/[0-9]/', $contrasena);
            $this->assertMatchesRegularExpression('/[^A-Za-z0-9]/', $contrasena);
            // Ni la consola ni la bitácora ni el registro de Laravel traen la contraseña.
            $this->assertStringNotContainsString($contrasena, $consola);
        }
        $this->assertStringContainsString((string) $activas->count(), $consola);

        // Las cuentas inactivas no se tocan y las sesiones abiertas se cierran.
        $this->assertSame($hashInactiva, DB::table('usuario')->where('id', $inactiva->id)->value('password_hash'));
        $this->api('GET', '/api/auth/yo', [], $sesionAdmin)->assertStatus(401);
        $this->assertSame(0, DB::table('confirmacion_operacion')->where('comando', 'SESION_WEB')->whereNull('usada_en')->count());

        // Una fila de bitácora por cuenta, sin actor (consola) y sin valores.
        $filas = DB::table('bitacora')->where('entidad', 'USUARIO')->whereRaw("detalle->>'operacion' = 'RESTABLECER_CONTRASENA'")->get();
        $this->assertCount($activas->count(), $filas);
        $this->assertEqualsCanonicalizing($activas->keys()->all(), $filas->pluck('entidad_id')->map(fn ($id) => (int) $id)->all());
        $bitacora = $filas->map(fn ($f) => $f->accion.$f->detalle)->implode("\n");
        $this->assertStringContainsString('CONSOLA', $bitacora);
        foreach ($entregadas as $correo => $contrasena) {
            $this->assertStringNotContainsString($contrasena, $bitacora);
            $this->assertStringNotContainsString($hashes[$correo], $bitacora);
        }
        $this->assertSame(['MODIFICAR'], $filas->pluck('accion')->unique()->values()->all());

        // Se puede entrar con la contraseña entregada, también en producción.
        $this->app['env'] = 'production';
        $this->api('POST', '/api/auth/login', ['correo' => self::ADMIN, 'password' => $entregadas[self::ADMIN]])->assertOk();
    }

    public function test_el_registro_de_laravel_no_recibe_las_contrasenas(): void
    {
        $registro = storage_path('logs/laravel.log');
        $tamano = is_file($registro) ? filesize($registro) : 0;
        $salida = $this->carpeta.'/credenciales.txt';

        $this->assertSame(0, Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--salida' => $salida, '--correo' => [self::CLIENTE, self::PERSONAL]]));

        clearstatcache();
        $nuevo = is_file($registro) ? (string) file_get_contents($registro, false, null, $tamano) : '';
        foreach ($this->leerArchivo($salida) as $contrasena) {
            $this->assertStringNotContainsString($contrasena, $nuevo);
        }
    }

    public function test_con_correo_solo_cambia_las_cuentas_indicadas(): void
    {
        $antes = DB::table('usuario')->pluck('password_hash', 'correo');
        $salida = $this->carpeta.'/dos.txt';

        $this->assertSame(0, Artisan::call('feder:restablecer-contrasenas', [
            '--confirmar' => true, '--salida' => $salida, '--correo' => [self::CLIENTE, strtoupper(self::PERSONAL)],
        ]));

        $entregadas = $this->leerArchivo($salida);
        $this->assertEqualsCanonicalizing([self::CLIENTE, self::PERSONAL], array_keys($entregadas));
        $despues = DB::table('usuario')->pluck('password_hash', 'correo');
        $cambiadas = $despues->filter(fn ($hash, $correo) => $hash !== $antes[$correo])->keys()->all();
        $this->assertEqualsCanonicalizing([self::CLIENTE, self::PERSONAL], $cambiadas);
    }

    public function test_un_correo_inexistente_o_inactivo_detiene_todo_sin_cambiar_nada(): void
    {
        $inactiva = $this->crearCuenta(['CLIENTE'], activo: false);
        $antes = DB::table('usuario')->pluck('password_hash', 'id')->all();

        foreach (['no.existe@feder.example', $inactiva->correo] as $correo) {
            $salida = $this->carpeta.'/'.md5($correo).'.txt';
            $codigo = Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--salida' => $salida, '--correo' => [self::CLIENTE, $correo]]);

            $this->assertNotSame(0, $codigo);
            $this->assertStringContainsString($correo, Artisan::output());
            $this->assertFileDoesNotExist($salida);
        }
        $this->assertSame($antes, DB::table('usuario')->pluck('password_hash', 'id')->all());
    }

    public function test_solo_demostracion_respeta_las_cuentas_que_ya_tienen_contrasena_propia(): void
    {
        $propia = $this->crearCuenta(['CLIENTE']);
        $hashPropio = $propia->password_hash;
        $conDemostracion = DB::table('usuario')->where('activo', true)->get(['correo', 'password_hash'])
            ->filter(fn ($u) => CredencialesDemostracion::esHashDeDemostracion($u->password_hash))->pluck('correo')->all();
        $this->assertCount(23, $conDemostracion);
        $salida = $this->carpeta.'/demo.txt';

        $this->assertSame(0, Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--salida' => $salida, '--solo-demostracion' => true]));

        $this->assertEqualsCanonicalizing($conDemostracion, array_keys($this->leerArchivo($salida)));
        $this->assertSame($hashPropio, DB::table('usuario')->where('id', $propia->id)->value('password_hash'));
        $this->assertSame(0, DB::table('usuario')->where('activo', true)->get(['password_hash'])
            ->filter(fn ($u) => CredencialesDemostracion::esHashDeDemostracion($u->password_hash))->count());

        // Ya no queda ninguna: una segunda pasada no tiene nada que hacer y no crea archivo.
        $segunda = $this->carpeta.'/demo2.txt';
        $this->assertSame(0, Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--salida' => $segunda, '--solo-demostracion' => true]));
        $this->assertFileDoesNotExist($segunda);
    }

    public function test_pide_confirmacion_y_sin_ella_no_cambia_nada(): void
    {
        $antes = DB::table('usuario')->pluck('password_hash', 'id')->all();
        $salida = $this->carpeta.'/no.txt';

        // Interactivo: se responde «no».
        $this->artisan('feder:restablecer-contrasenas', ['--salida' => $salida])
            ->expectsConfirmation('¿Restablecer la contraseña de 23 cuenta(s) y cerrar sus sesiones?', 'no')
            ->assertExitCode(1);
        // No interactivo (despliegue por guion) sin la opción explícita: se niega.
        $codigo = Artisan::call('feder:restablecer-contrasenas', ['--salida' => $salida, '--no-interaction' => true]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('--confirmar', Artisan::output());

        $this->assertSame($antes, DB::table('usuario')->pluck('password_hash', 'id')->all());
        $this->assertFileDoesNotExist($salida);
    }

    public function test_interactivo_con_respuesta_afirmativa_si_restablece(): void
    {
        $salida = $this->carpeta.'/si.txt';

        $this->artisan('feder:restablecer-contrasenas', ['--salida' => $salida, '--correo' => [self::CLIENTE]])
            ->expectsConfirmation('¿Restablecer la contraseña de 1 cuenta(s) y cerrar sus sesiones?', 'yes')
            ->assertExitCode(0);

        $this->assertSame([self::CLIENTE], array_keys($this->leerArchivo($salida)));
    }

    public function test_no_escribe_dentro_de_public_ni_sobrescribe_un_archivo_existente(): void
    {
        $antes = DB::table('usuario')->pluck('password_hash', 'id')->all();

        $enPublic = public_path('credenciales-prueba.txt');
        $this->assertNotSame(0, Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--salida' => $enPublic]));
        $this->assertStringContainsString('public', Artisan::output());
        $this->assertFileDoesNotExist($enPublic);

        $porAtajo = $this->carpeta.'/../../../../public/credenciales-prueba.txt';
        $this->assertNotSame(0, Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--salida' => $porAtajo]));
        $this->assertFileDoesNotExist($enPublic);

        $existente = $this->carpeta.'/ya-existe.txt';
        file_put_contents($existente, 'contenido anterior');
        $this->assertNotSame(0, Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--salida' => $existente]));
        $this->assertSame('contenido anterior', file_get_contents($existente));

        $this->assertSame($antes, DB::table('usuario')->pluck('password_hash', 'id')->all());
    }

    public function test_con_mostrar_las_entrega_por_consola_y_no_crea_archivo(): void
    {
        $codigo = Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--mostrar' => true, '--correo' => [self::CLIENTE]]);
        $consola = Artisan::output();

        $this->assertSame(0, $codigo, $consola);
        $this->assertSame(1, preg_match('/'.preg_quote(self::CLIENTE, '/').'\s+(\S{16,})/', $consola, $coincidencia), $consola);
        $this->assertTrue(Hash::check($coincidencia[1], DB::table('usuario')->where('correo', self::CLIENTE)->value('password_hash')));
        $this->assertSame([], glob($this->carpeta.'/*') ?: []);
        $this->assertSame([], glob(storage_path('app/private/credenciales/*')) ?: []);
    }

    public function test_modo_laboratorio_pone_la_contrasena_comun_de_la_variable_de_entorno(): void
    {
        $comun = $this->contrasenaFicticia();
        putenv('FEDER_CONTRASENA_LABORATORIO='.$comun);
        $sesion = $this->tokenDe(self::CLIENTE);

        $codigo = Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--laboratorio' => true]);
        $consola = Artisan::output();

        $this->assertSame(0, $codigo, $consola);
        $this->assertStringNotContainsString($comun, $consola);
        $hashes = DB::table('usuario')->where('activo', true)->pluck('password_hash');
        $this->assertCount(23, $hashes->unique(), 'Cada cuenta debe tener su propia sal.');
        $hashes->each(fn ($hash) => $this->assertTrue(Hash::check($comun, $hash)));
        $this->api('GET', '/api/auth/yo', [], $sesion)->assertStatus(401);
        $this->api('POST', '/api/auth/login', ['correo' => self::MULTIRROL, 'password' => $comun])->assertOk();
        $this->assertSame('LABORATORIO', $this->ultimaBitacora('USUARIO', $this->usuarioId(self::ADMIN))->detalle['modo']);
    }

    public function test_modo_laboratorio_se_rechaza_en_produccion_y_sin_variable(): void
    {
        $antes = DB::table('usuario')->pluck('password_hash', 'id')->all();

        // Sin la variable de entorno.
        $this->assertNotSame(0, Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--laboratorio' => true]));
        $this->assertStringContainsString('FEDER_CONTRASENA_LABORATORIO', Artisan::output());

        // Con una contraseña demasiado corta.
        putenv('FEDER_CONTRASENA_LABORATORIO=abc12');
        $this->assertNotSame(0, Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--laboratorio' => true]));

        // En producción, nunca.
        putenv('FEDER_CONTRASENA_LABORATORIO='.$this->contrasenaFicticia());
        $this->app['env'] = 'production';
        $this->assertNotSame(0, Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--laboratorio' => true]));
        $this->assertStringContainsString('producción', Artisan::output());

        $this->assertSame($antes, DB::table('usuario')->pluck('password_hash', 'id')->all());
    }

    public function test_en_produccion_el_modo_normal_funciona_y_desbloquea_las_cuentas_de_demostracion(): void
    {
        $this->app['env'] = 'production';
        $this->api('POST', '/api/auth/login', ['correo' => self::PERSONAL, 'password' => $this->contrasenaFicticia()])->assertStatus(403);
        $salida = $this->carpeta.'/produccion.txt';

        $this->assertSame(0, Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true, '--salida' => $salida, '--solo-demostracion' => true]));

        $this->api('POST', '/api/auth/login', ['correo' => self::PERSONAL, 'password' => $this->leerArchivo($salida)[self::PERSONAL]])->assertOk();
    }

    public function test_opciones_incompatibles_se_rechazan_sin_cambiar_nada(): void
    {
        $antes = DB::table('usuario')->pluck('password_hash', 'id')->all();
        putenv('FEDER_CONTRASENA_LABORATORIO='.$this->contrasenaFicticia());

        foreach ([
            ['--laboratorio' => true, '--mostrar' => true],
            ['--laboratorio' => true, '--salida' => $this->carpeta.'/lab.txt'],
            ['--mostrar' => true, '--salida' => $this->carpeta.'/doble.txt'],
            ['--salida' => $this->carpeta.'/no-existe/credenciales.txt'],
        ] as $opciones) {
            $this->assertNotSame(0, Artisan::call('feder:restablecer-contrasenas', ['--confirmar' => true] + $opciones), json_encode($opciones));
        }

        $this->assertSame($antes, DB::table('usuario')->pluck('password_hash', 'id')->all());
        $this->assertSame([], glob($this->carpeta.'/*') ?: []);
    }

    /** @return array<string, string> correo => contraseña entregada */
    private function leerArchivo(string $ruta): array
    {
        $this->assertFileExists($ruta);
        $entregadas = [];
        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            if (preg_match('/^(\S+@\S+)\t(\S+)$/', $linea, $m)) {
                $entregadas[$m[1]] = $m[2];
            }
        }

        return $entregadas;
    }
}
