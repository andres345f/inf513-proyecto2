<?php

namespace App\Providers;

use App\Services\SesionWebService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /** Comandos que crearían o borrarían tablas: prohibidos por la regla de cero cambios de esquema. */
    private const COMANDOS_PROHIBIDOS = [
        'migrate', 'migrate:fresh', 'migrate:install', 'migrate:refresh', 'migrate:reset', 'migrate:rollback',
        'db:seed', 'db:wipe', 'make:migration', 'session:table', 'cache:table', 'queue:table',
        'install:api', 'install:broadcasting',
    ];

    public function register(): void
    {
        $this->app->singleton(SesionWebService::class);
    }

    public function boot(): void
    {
        $this->registrarGuardDeTokens();
        $this->registrarLimitesDePeticiones();
        $this->bloquearComandosDeEsquema();
    }

    /**
     * Guard "token-web": resuelve el token Bearer contra confirmacion_operacion (SESION_WEB)
     * y deja la sesión activa (usuario + rol activo) en los atributos del request.
     */
    private function registrarGuardDeTokens(): void
    {
        Auth::viaRequest('token-web', function (Request $request) {
            if ($request->attributes->has('sesion')) {
                return $request->attributes->get('sesion')?->usuario;
            }

            $sesion = app(SesionWebService::class)->resolver($request->bearerToken());
            $request->attributes->set('sesion', $sesion);

            return $sesion?->usuario;
        });
    }

    /**
     * Límites de peticiones por minuto.
     * - login: 5 por correo + IP (adivinar la contraseña de una cuenta) y, además, 30 por IP en total
     *   (probar una misma contraseña contra muchas cuentas).
     * - contrasena: 10 peticiones de cambio por minuto y por cuenta (freno general). Además, AutenticacionService
     *   admite solo 5 intentos con la contraseña actual equivocada cada 15 minutos (los errores de formulario no cuentan).
     * - visitas: 60 por cuenta si hay sesión; 60 por IP para los visitantes. En una red que sale a internet con una sola
     *   IP (un aula), los visitantes sin sesión comparten ese tope: al superarlo el pie deja de contar, nada más falla.
     */
    private function registrarLimitesDePeticiones(): void
    {
        RateLimiter::for('login', function (Request $request) {
            // El correo solo entra en la clave si es texto: una lista u otro tipo lo rechaza después el FormRequest (422).
            $correo = $request->input('correo');
            $correo = is_string($correo) ? mb_strtolower(trim($correo)) : '';

            return [
                Limit::perMinute(5)->by($correo.'|'.$request->ip()),
                Limit::perMinute(30)->by('ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('registro', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('contrasena', fn (Request $request) => Limit::perMinute(10)
            ->by('cuenta|'.($this->usuarioDe($request) ?? 'ip|'.$request->ip())));
        RateLimiter::for('visitas', function (Request $request) {
            $usuarioId = $this->usuarioDe($request);

            return Limit::perMinute(60)->by($usuarioId !== null ? 'cuenta|'.$usuarioId : 'ip|'.$request->ip());
        });
        RateLimiter::for('busqueda', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }

    /** Id de la cuenta con sesión válida en esta petición, o null si es un visitante. */
    private function usuarioDe(Request $request): ?int
    {
        auth('api')->user();

        return $request->attributes->get('sesion')?->usuario->id;
    }

    private function bloquearComandosDeEsquema(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $evento) {
            if (in_array($evento->command, self::COMANDOS_PROHIBIDOS, true)) {
                throw new RuntimeException(
                    "FEDER Web trabaja con cero cambios de esquema: el comando «{$evento->command}» está deshabilitado. ".
                    'Los datos se cargan con los scripts de la carpeta db/.'
                );
            }
        });
    }
}
