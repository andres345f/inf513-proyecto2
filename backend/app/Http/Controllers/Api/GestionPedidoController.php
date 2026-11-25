<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comercio\AvanzarPedidoRequest;
use App\Http\Requests\Comercio\CancelarPedidoRequest;
use App\Http\Requests\Comercio\ListarGestionPedidosRequest;
use App\Services\GestionPedidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bandeja de pedidos del Personal de FEDER (y del administrador que lo suple). */
class GestionPedidoController extends Controller
{
    public function __construct(private readonly GestionPedidoService $gestion) {}

    public function index(ListarGestionPedidosRequest $request): JsonResponse
    {
        return response()->json($this->gestion->listar($request->validated()));
    }

    public function show(Request $request, int $pedido): JsonResponse
    {
        return response()->json($this->gestion->detalle($this->sesionActual($request), $pedido));
    }

    public function avanzar(AvanzarPedidoRequest $request, int $pedido): JsonResponse
    {
        return response()->json($this->gestion->avanzar($this->sesionActual($request), $pedido, $request->validated(), $request->ip()));
    }

    public function abastecer(Request $request, int $pedido): JsonResponse
    {
        return response()->json($this->gestion->abastecer($this->sesionActual($request), $pedido, $request->ip()));
    }

    public function cancelar(CancelarPedidoRequest $request, int $pedido): JsonResponse
    {
        return response()->json($this->gestion->cancelar($this->sesionActual($request), $pedido, $request->validated('motivo'), $request->ip()));
    }
}
