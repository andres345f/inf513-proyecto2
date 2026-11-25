<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comercio\CotizarEnvioRequest;
use App\Http\Requests\Comercio\ItemCarritoRequest;
use App\Services\CarritoService;
use App\Services\EnvioService;
use App\Services\PedidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarritoController extends Controller
{
    public function __construct(
        private readonly CarritoService $carrito,
        private readonly PedidoService $pedidos,
        private readonly EnvioService $envio,
    ) {
    }

    /** Dirección sugerida para el punto marcado en el mapa (puede venir vacía). */
    public function direccion(CotizarEnvioRequest $request): JsonResponse
    {
        $datos = $request->validated();

        return response()->json(['direccion' => $this->envio->direccionDe((float) $datos['lat'], (float) $datos['lng'])]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->carrito->ver($this->clienteId($request)));
    }

    public function store(ItemCarritoRequest $request): JsonResponse
    {
        $datos = $request->validated();

        return response()->json($this->carrito->agregar($this->clienteId($request), (int) $datos['producto_id'], (float) $datos['cantidad']));
    }

    public function update(ItemCarritoRequest $request, int $producto): JsonResponse
    {
        return response()->json($this->carrito->cambiarCantidad($this->clienteId($request), $producto, (float) $request->validated('cantidad')));
    }

    public function destroy(Request $request, int $producto): JsonResponse
    {
        return response()->json($this->carrito->quitar($this->clienteId($request), $producto));
    }

    public function cotizarEnvio(CotizarEnvioRequest $request): JsonResponse
    {
        $datos = $request->validated();

        return response()->json($this->pedidos->cotizarEnvio($this->clienteId($request), (float) $datos['lat'], (float) $datos['lng']));
    }

    private function clienteId(Request $request): int
    {
        return $this->sesionActual($request)->usuario->id;
    }
}
