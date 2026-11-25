<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comercio\ListarNotificacionesRequest;
use App\Services\NotificacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    public function __construct(private readonly NotificacionService $notificaciones)
    {
    }

    public function index(ListarNotificacionesRequest $request): JsonResponse
    {
        $datos = $request->validated();

        return response()->json($this->notificaciones->listar(
            $this->usuarioId($request), (bool) ($datos['no_leidas'] ?? false), (int) ($datos['pagina'] ?? 1)
        ));
    }

    public function contador(Request $request): JsonResponse
    {
        return response()->json(['no_leidas' => $this->notificaciones->contarNoLeidas($this->usuarioId($request))]);
    }

    public function leer(Request $request, int $notificacion): JsonResponse
    {
        return response()->json(['no_leidas' => $this->notificaciones->marcarLeida($this->usuarioId($request), $notificacion)]);
    }

    public function leerTodas(Request $request): JsonResponse
    {
        return response()->json(['no_leidas' => $this->notificaciones->marcarTodas($this->usuarioId($request))]);
    }

    private function usuarioId(Request $request): int
    {
        return $this->sesionActual($request)->usuario->id;
    }
}
