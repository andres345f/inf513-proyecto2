<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PagoService;
use App\Services\PlanPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Historial de pagos, estado de un QR y planes de cuotas del cliente. */
class PagoController extends Controller
{
    public function __construct(
        private readonly PagoService $pagos,
        private readonly PlanPagoService $planes,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['pagos' => $this->pagos->historial($this->sesionActual($request)->usuario->id)]);
    }

    public function show(Request $request, int $pago): JsonResponse
    {
        return response()->json($this->pagos->estado($this->sesionActual($request)->usuario, $pago));
    }

    public function simularQr(Request $request, int $pago): JsonResponse
    {
        return response()->json($this->pagos->confirmarQrSimulado($this->sesionActual($request)->usuario, $pago, $request->ip()));
    }

    public function planes(Request $request): JsonResponse
    {
        $clienteId = $this->sesionActual($request)->usuario->id;

        return response()->json([
            'planes' => $this->planes->listar($clienteId),
            'tiene_cuota_vencida' => $this->planes->tieneCuotaVencida($clienteId),
        ]);
    }
}
