<?php

namespace Tests\Integracion;

use App\Models\Usuario;
use App\Repositories\UsuarioRepository;
use App\Services\SesionWebService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Las pruebas de integración se ejecutan en transacciones sobre un clúster PostgreSQL de pruebas.
 * Antes de iniciar cada transacción se verifica la conexión y la identidad del servidor local.
 */
abstract class IntegracionTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function setUpTraits()
    {
        $this->exigirBaseDeLaboratorio();

        return parent::setUpTraits();
    }

    /** Cada petición de prueba vuelve a resolver la sesión desde el token, como en producción. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /** Emite un token real de sesión (SESION_WEB) sin pasar por la contraseña ni el límite de intentos. */
    protected function tokenDe(string $correo, ?string $rolCodigo = null): string
    {
        $usuario = Usuario::query()->whereRaw('lower(correo) = ?', [mb_strtolower($correo)])->firstOrFail();
        $roles = app(UsuarioRepository::class)->rolesVigentes($usuario->id);
        $rol = $rolCodigo ? $roles->firstWhere('codigo', $rolCodigo) : $roles->first();
        if ($rol === null) {
            throw new RuntimeException("La cuenta {$correo} no tiene el rol vigente {$rolCodigo}.");
        }

        return app(SesionWebService::class)->iniciar($usuario, $rol)['token'];
    }

    private function exigirBaseDeLaboratorio(): void
    {
        $driver = config('database.default');
        $conexion = config("database.connections.{$driver}", []);
        $base = 'feder_p2_demo_test';
        $puerto = filter_var(env('FEDER_TEST_PGPORT'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        $ruta = (string) env('FEDER_TEST_PGDATA', '');
        $directorio = $ruta !== '' && is_dir($ruta) ? realpath($ruta) : false;

        if (! app()->environment('testing') || $driver !== 'pgsql'
            || ($conexion['host'] ?? '') !== '127.0.0.1' || ($conexion['database'] ?? '') !== $base
            || $puerto === false || (string) ($conexion['port'] ?? '') !== (string) $puerto
            || $directorio === false || ! str_starts_with($ruta, '/') && ! preg_match('~^[A-Za-z]:[/\\\\]~', $ruta)) {
            throw new RuntimeException('La suite requiere un clúster de pruebas local, la base feder_p2_demo_test, '
                .'FEDER_TEST_PGPORT y la ruta absoluta FEDER_TEST_PGDATA.');
        }

        $servidor = DB::selectOne("SELECT current_database() AS base, host(inet_server_addr()) AS host,
            inet_server_port() AS puerto, current_setting('data_directory') AS directorio");
        $directorioReal = realpath((string) $servidor->directorio);
        $mismoDirectorio = $directorioReal !== false && (PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($directorioReal, $directorio) === 0
            : $directorioReal === $directorio);

        if ($servidor->base !== $base || $servidor->host !== '127.0.0.1'
            || (int) $servidor->puerto !== $puerto || ! $mismoDirectorio) {
            throw new RuntimeException('La identidad del servidor no coincide con el clúster de pruebas configurado.');
        }
    }
}
