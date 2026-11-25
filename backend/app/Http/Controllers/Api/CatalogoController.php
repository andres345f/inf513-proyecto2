<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comercio\CatalogoRequest;
use App\Services\CatalogoService;
use Illuminate\Http\JsonResponse;

class CatalogoController extends Controller
{
    public function __construct(private readonly CatalogoService $catalogo)
    {
    }

    public function index(CatalogoRequest $request): JsonResponse
    {
        return response()->json($this->catalogo->listar($request->validated()));
    }

    public function filtros(): JsonResponse
    {
        return response()->json($this->catalogo->filtros());
    }

    public function show(int $producto): JsonResponse
    {
        return response()->json($this->catalogo->detalle($producto));
    }
}
