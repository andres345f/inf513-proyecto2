<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comercio\AprobarDevolucionRequest;
use App\Http\Requests\Comercio\ListarDevolucionesRequest;
use App\Http\Requests\Comercio\RechazarDevolucionRequest;
use App\Http\Requests\Comercio\SolicitarDevolucionRequest;
use App\Services\DevolucionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Devoluciones: solicitudes del cliente y resolución del Personal de FEDER. */
class DevolucionController extends Controller
{
    public function __construct(private readonly DevolucionService $devoluciones)
    {
    }

    public function misDevoluciones(Request $request): JsonResponse
    {
        return response()->json($this->devoluciones->resumenCliente($this->sesionActual($request)->usuario->id));
    }

    public function solicitar(SolicitarDevolucionRequest $request): JsonResponse
    {
        $datos = $request->validated();

        return response()->json($this->devoluciones->solicitar(
            $this->sesionActual($request)->usuario, (int) $datos['despacho_id'], (float) $datos['cantidad'], $datos['motivo'], $request->ip()
        ), 201);
    }

    public function index(ListarDevolucionesRequest $request): JsonResponse
    {
        return response()->json($this->devoluciones->listar($request->validated()));
    }

    public function aprobar(AprobarDevolucionRequest $request, int $devolucion): JsonResponse
    {
        return response()->json($this->devoluciones->aprobar($this->sesionActual($request)->usuario, $devolucion, $request->validated(), $request->ip()));
    }

    public function rechazar(RechazarDevolucionRequest $request, int $devolucion): JsonResponse
    {
        return response()->json($this->devoluciones->rechazar($this->sesionActual($request)->usuario, $devolucion, $request->validated('motivo'), $request->ip()));
    }

    public function completar(Request $request, int $devolucion): JsonResponse
    {
        return response()->json($this->devoluciones->completar($this->sesionActual($request)->usuario, $devolucion, $request->ip()));
    }
}
