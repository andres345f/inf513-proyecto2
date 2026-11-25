<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnularFacturaRequest;
use App\Services\FacturaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacturaController extends Controller
{
    public function __construct(private readonly FacturaService $facturas)
    {
    }

    public function anular(AnularFacturaRequest $request, int $factura): JsonResponse
    {
        return response()->json($this->facturas->anular($this->sesionActual($request), $factura, $request->validated(), $request->ip()));
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['facturas' => $this->facturas->listar($this->sesionActual($request)->usuario->id)]);
    }

    public function show(Request $request, int $factura): JsonResponse
    {
        return response()->json($this->facturas->detalle($this->sesionActual($request)->usuario->id, $factura));
    }
}
