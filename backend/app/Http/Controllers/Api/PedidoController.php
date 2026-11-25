<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comercio\CancelarPedidoRequest;
use App\Http\Requests\Comercio\CrearPedidoRequest;
use App\Http\Requests\Comercio\ListarPedidosRequest;
use App\Http\Requests\Comercio\PagarRequest;
use App\Services\PagoService;
use App\Services\PedidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pedidos del cliente: confirmar el carrito, consultar, cancelar y pagar. */
class PedidoController extends Controller
{
    public function __construct(
        private readonly PedidoService $pedidos,
        private readonly PagoService $pagos,
    ) {
    }

    public function index(ListarPedidosRequest $request): JsonResponse
    {
        return response()->json($this->pedidos->listar($this->sesionActual($request)->usuario->id, $request->validated()));
    }

    public function store(CrearPedidoRequest $request): JsonResponse
    {
        return response()->json($this->pedidos->confirmar($this->sesionActual($request)->usuario, $request->validated(), $request->ip()), 201);
    }

    public function show(Request $request, int $pedido): JsonResponse
    {
        return response()->json($this->pedidos->detalle($this->sesionActual($request)->usuario->id, $pedido));
    }

    public function cancelar(CancelarPedidoRequest $request, int $pedido): JsonResponse
    {
        return response()->json($this->pedidos->cancelar($this->sesionActual($request)->usuario, $pedido, $request->validated('motivo'), $request->ip()));
    }

    public function pagar(PagarRequest $request, int $pedido): JsonResponse
    {
        return response()->json($this->pagos->pagar($this->sesionActual($request)->usuario, $pedido, $request->validated(), $request->ip()));
    }
}
