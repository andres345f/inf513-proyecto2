<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusquedaRequest;
use App\Services\BusquedaService;
use Illuminate\Http\JsonResponse;

class BusquedaController extends Controller
{
    public function __construct(private readonly BusquedaService $busqueda)
    {
    }

    public function index(BusquedaRequest $request): JsonResponse
    {
        return response()->json($this->busqueda->buscar($request->validated('q')));
    }
}
