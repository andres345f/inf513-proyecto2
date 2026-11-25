<?php

use App\Exceptions\NegocioException;
use App\Exceptions\RenderizadorErroresApi;
use App\Http\Middleware\AgregarCabecerasSeguridad;
use App\Http\Middleware\BloquearPorCuotaVencida;
use App\Http\Middleware\VerificarPermiso;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AgregarCabecerasSeguridad::class);

        $middleware->alias([
            'permiso' => VerificarPermiso::class,
            'sin-cuota-vencida' => BloquearPorCuotaVencida::class,
        ]);

        // API sin páginas de login: un visitante sin token recibe 401 en JSON, nunca una redirección.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Las reglas de negocio incumplidas son respuestas esperadas, no fallas del sistema.
        $exceptions->dontReport([NegocioException::class]);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => RenderizadorErroresApi::aplicaA($request));
        $exceptions->render(fn (Throwable $e, Request $request) => RenderizadorErroresApi::renderizar($e, $request));
    })->create();
