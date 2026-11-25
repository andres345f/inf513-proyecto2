<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Productor\ComunidadRequest;
use App\Http\Requests\Productor\ListarProductoresRequest;
use App\Http\Requests\Productor\RechazarProductorRequest;
use App\Http\Requests\Productor\SolicitudProductorRequest;
use App\Services\ProductorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Productores (solicitudes de afiliación) y comunidades agrícolas. */
class ProductorController extends Controller
{
    public function __construct(private readonly ProductorService $productores)
    {
    }

    public function index(ListarProductoresRequest $request): JsonResponse
    {
        return response()->json($this->productores->listar($request->validated()));
    }

    public function aprobar(Request $request, int $productor): JsonResponse
    {
        return response()->json($this->productores->aprobar($this->sesionActual($request)->usuario, $productor, $request->ip()));
    }

    public function rechazar(RechazarProductorRequest $request, int $productor): JsonResponse
    {
        return response()->json($this->productores->rechazar(
            $this->sesionActual($request)->usuario, $productor, $request->validated('motivo'), $request->ip()
        ));
    }

    public function miFicha(Request $request): JsonResponse
    {
        return response()->json($this->productores->miFicha($this->sesionActual($request)->usuario->id));
    }

    public function comunidades(): JsonResponse
    {
        return response()->json(['comunidades' => $this->productores->comunidades()]);
    }

    public function guardarComunidad(ComunidadRequest $request, ?int $comunidad = null): JsonResponse
    {
        return response()->json(
            $this->productores->guardarComunidad($this->sesionActual($request)->usuario, $comunidad, $request->validated(), $request->ip()),
            $comunidad === null ? 201 : 200
        );
    }

    public function estadoSolicitud(Request $request): JsonResponse
    {
        return response()->json($this->productores->estadoSolicitud($this->sesionActual($request)->usuario->id));
    }

    public function comunidadesDisponibles(): JsonResponse
    {
        return response()->json(['comunidades' => $this->productores->comunidadesDisponibles()]);
    }

    public function solicitar(SolicitudProductorRequest $request): JsonResponse
    {
        return response()->json($this->productores->solicitar(
            $this->sesionActual($request)->usuario,
            $request->validated(),
            $request->ip(),
        ), 201);
    }
}
