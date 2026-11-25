<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Convierte cualquier error de la API en una respuesta JSON uniforme y en español:
 * { "message": "...", "errors": { "campo": ["..."] } }
 */
class RenderizadorErroresApi
{
    public static function aplicaA(Request $request): bool
    {
        return $request->is('api', 'api/*');
    }

    public static function renderizar(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::aplicaA($request)) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => self::json(
                'Los datos enviados no son válidos. Revisa los campos marcados.', 422, $e->errors()),
            $e instanceof NegocioException => self::json($e->getMessage(), $e->estadoHttp(), $e->errores),
            $e instanceof AuthenticationException => self::json(
                'Tu sesión no es válida o expiró. Inicia sesión nuevamente.', 401),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => self::json(
                'No tienes permiso para realizar esta acción.', 403),
            $e instanceof ThrottleRequestsException => self::json(
                'Demasiados intentos. Espera un minuto e inténtalo de nuevo.', 429),
            $e instanceof NotFoundHttpException => self::json('El recurso solicitado no existe.', 404),
            $e instanceof MethodNotAllowedHttpException => self::json('Operación no permitida para este recurso.', 405),
            $e instanceof QueryException => self::errorDeBaseDeDatos($e),
            $e instanceof HttpExceptionInterface => self::json(
                'No se pudo completar la solicitud.', $e->getStatusCode()),
            default => self::errorInesperado($e),
        };
    }

    /**
     * Los disparadores de FEDER rechazan con SQLSTATE P0001 y un mensaje en español: se muestra tal cual.
     * Las violaciones de restricciones (23xxx) se informan como conflicto de datos.
     */
    private static function errorDeBaseDeDatos(QueryException $e): JsonResponse
    {
        $estado = (string) ($e->errorInfo[0] ?? '');

        if ($estado === 'P0001') {
            $mensaje = trim(preg_replace('/^.*?ERROR:\s*/s', '', $e->errorInfo[2] ?? ''));
            $mensaje = trim(strtok($mensaje, "\n") ?: 'La operación no cumple una regla del sistema.');

            return self::json($mensaje, 422);
        }

        if ($estado === '23505') {
            return self::json('La operación entra en conflicto con datos existentes.', 409);
        }

        if (str_starts_with($estado, '23')) {
            Log::warning($e);

            return self::json('Los datos no cumplen las reglas del sistema. Revísalos e inténtalo de nuevo.', 422);
        }

        return self::errorInesperado($e);
    }

    private static function errorInesperado(Throwable $e): JsonResponse
    {
        Log::error($e);

        return self::json('Ocurrió un error inesperado. Inténtalo nuevamente en unos minutos.', 500);
    }

    private static function json(string $mensaje, int $estado, array $errores = []): JsonResponse
    {
        $cuerpo = ['message' => $mensaje];

        if ($errores !== []) {
            $cuerpo['errors'] = $errores;
        }

        return response()->json($cuerpo, $estado);
    }
}
