<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comercio\ListarGestionPagosRequest;
use App\Http\Requests\Comercio\RechazarPagoRequest;
use App\Services\GestionPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pagos de todos los clientes, para el Personal de FEDER y el administrador. */
class GestionPagoController extends Controller
{
    public function __construct(private readonly GestionPagoService $gestion)
    {
    }

    public function index(ListarGestionPagosRequest $request): JsonResponse
    {
        return response()->json($this->gestion->listar($request->validated()));
    }

    public function confirmar(Request $request, int $pago): JsonResponse
    {
        return response()->json($this->gestion->confirmar($this->sesionActual($request)->usuario, $pago, $request->ip()));
    }

    public function rechazar(RechazarPagoRequest $request, int $pago): JsonResponse
    {
        return response()->json($this->gestion->rechazar($this->sesionActual($request)->usuario, $pago, $request->validated('motivo'), $request->ip()));
    }
}
