<?php

namespace App\Http\Middleware;

use App\Services\PermisoService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Matriz de acceso: deja pasar solo si el rol activo (o el visitante) tiene el permiso indicado.
 * Uso en rutas: ->middleware('permiso:catalogo.ver')
 */
class VerificarPermiso
{
    public function __construct(private readonly PermisoService $permisos)
    {
    }

    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        auth('api')->user();
        $sesion = $request->attributes->get('sesion');

        if (collect($permisos)->contains(fn (string $permiso) => $this->permisos->tienePermiso($sesion, $permiso))) {
            return $next($request);
        }

        if ($sesion === null) {
            throw new AuthenticationException();
        }

        throw new AuthorizationException();
    }
}
