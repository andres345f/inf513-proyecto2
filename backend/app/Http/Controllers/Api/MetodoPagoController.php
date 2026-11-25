<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comercio\TarjetaRequest;
use App\Services\MetodoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetodoPagoController extends Controller
{
    public function __construct(private readonly MetodoPagoService $metodos)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['metodos' => $this->metodos->listar($this->sesionActual($request)->usuario->id)]);
    }

    public function store(TarjetaRequest $request): JsonResponse
    {
        $tarjeta = $request->validated();
        $this->metodos->validarTarjetaNueva($tarjeta);

        return response()->json($this->metodos->registrar($this->sesionActual($request)->usuario, $tarjeta), 201);
    }

    public function destroy(Request $request, int $metodo): JsonResponse
    {
        $this->metodos->eliminar($this->sesionActual($request)->usuario->id, $metodo);

        return response()->json(['message' => 'Eliminamos la tarjeta de tus métodos de pago.']);
    }

    public function predeterminado(Request $request, int $metodo): JsonResponse
    {
        $usuarioId = $this->sesionActual($request)->usuario->id;
        $this->metodos->marcarPredeterminado($usuarioId, $metodo);

        return response()->json(['metodos' => $this->metodos->listar($usuarioId)]);
    }
}
