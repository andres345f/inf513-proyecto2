<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comercio\ListarInventarioRequest;
use App\Http\Requests\Comercio\ListarComprasRequest;
use App\Http\Requests\Comercio\ListarProduccionesRequest;
use App\Services\ConsultaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Inventario por lote (personal) y portal del productor (sus producciones y ventas a FEDER). */
class ConsultaController extends Controller
{
    public function __construct(private readonly ConsultaService $consultas)
    {
    }

    public function inventario(ListarInventarioRequest $request): JsonResponse
    {
        $filtros = $request->validated();

        return response()->json($this->consultas->inventario($filtros));
    }

    public function gestionProducciones(ListarProduccionesRequest $request): JsonResponse
    {
        return response()->json($this->consultas->produccionesGestion($request->validated()));
    }

    public function gestionCompras(ListarComprasRequest $request): JsonResponse
    {
        return response()->json($this->consultas->comprasGestion($request->validated()));
    }

    public function producciones(Request $request): JsonResponse
    {
        return response()->json(['producciones' => $this->consultas->producciones($this->sesionActual($request)->usuario->id)]);
    }

    public function lotesProductor(Request $request): JsonResponse
    {
        return response()->json(['lotes' => $this->consultas->lotesDelProductor($this->sesionActual($request)->usuario->id)]);
    }

    public function ventas(Request $request): JsonResponse
    {
        return response()->json($this->consultas->ventasDelProductor($this->sesionActual($request)->usuario->id));
    }
}
