<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comercio\AvanzarPedidoRequest;
use App\Http\Requests\Comercio\ListarEntregasRequest;
use App\Services\GestionPedidoService;
use Illuminate\Http\JsonResponse;

/** Entregas (recojo y delivery) para el Personal de FEDER: seguimiento y avance rápido. */
class GestionEntregaController extends Controller
{
    public function __construct(private readonly GestionPedidoService $gestion)
    {
    }

    public function index(ListarEntregasRequest $request): JsonResponse
    {
        return response()->json($this->gestion->listarEntregas($request->validated(), $this->sesionActual($request)));
    }

    /**
     * Avanza el pedido de la entrega. El servicio exige el permiso de cada transición: entregar y cobrar el
     * efectivo corresponden a entregas.gestionar; preparar y despachar, a pedidos.gestionar.
     */
    public function avanzar(AvanzarPedidoRequest $request, int $pedido): JsonResponse
    {
        $this->gestion->avanzar($this->sesionActual($request), $pedido, $request->validated(), $request->ip());

        return response()->json(['mensaje' => 'Entrega actualizada.']);
    }
}
