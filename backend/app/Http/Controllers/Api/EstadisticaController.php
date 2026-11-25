<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BitacoraRequest;
use App\Http\Requests\RangoFechasRequest;
use App\Services\EstadisticaService;
use Illuminate\Http\JsonResponse;

/** Panel de negocio, estadísticas de acceso y bitácora. */
class EstadisticaController extends Controller
{
    public function __construct(private readonly EstadisticaService $estadisticas)
    {
    }

    public function negocio(RangoFechasRequest $request): JsonResponse
    {
        return response()->json($this->estadisticas->negocio($request->validated('desde'), $request->validated('hasta')));
    }

    public function accesos(RangoFechasRequest $request): JsonResponse
    {
        return response()->json($this->estadisticas->accesos($request->validated('desde'), $request->validated('hasta')));
    }

    public function bitacora(BitacoraRequest $request): JsonResponse
    {
        return response()->json($this->estadisticas->bitacora($request->validated()));
    }
}
