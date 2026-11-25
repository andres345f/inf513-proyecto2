<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comercio\CategoriaRequest;
use App\Http\Requests\Comercio\ListarProductosRequest;
use App\Http\Requests\Comercio\ProductoRequest;
use App\Services\ProductoService;
use Illuminate\Http\JsonResponse;

/** Mantenimiento de productos y categorías (Personal de FEDER y administrador). */
class ProductoController extends Controller
{
    public function __construct(private readonly ProductoService $productos)
    {
    }

    public function index(ListarProductosRequest $request): JsonResponse
    {
        $filtros = $request->validated();

        return response()->json($this->productos->listar($filtros));
    }

    public function store(ProductoRequest $request): JsonResponse
    {
        return response()->json($this->productos->guardar($this->sesionActual($request)->usuario, null, $request->validated(), $request->ip()), 201);
    }

    public function update(ProductoRequest $request, int $producto): JsonResponse
    {
        return response()->json($this->productos->guardar($this->sesionActual($request)->usuario, $producto, $request->validated(), $request->ip()));
    }

    public function categorias(): JsonResponse
    {
        return response()->json(['categorias' => $this->productos->categorias()]);
    }

    public function guardarCategoria(CategoriaRequest $request, ?int $categoria = null): JsonResponse
    {
        $datos = $request->validated();

        return response()->json($this->productos->guardarCategoria($this->sesionActual($request)->usuario, $categoria, $datos, $request->ip()),
            $categoria === null ? 201 : 200);
    }
}
