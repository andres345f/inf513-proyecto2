<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActualizarPermisosRequest;
use App\Services\AdminPermisoService;
use Illuminate\Http\JsonResponse;

/** Matriz de permisos por rol y, con ella, el menú de cada rol. */
class AdminPermisoController extends Controller
{
    public function __construct(private readonly AdminPermisoService $permisos)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->permisos->matriz());
    }

    public function update(ActualizarPermisosRequest $request, string $rol): JsonResponse
    {
        return response()->json($this->permisos->actualizar(
            $this->sesionActual($request)->usuario, $rol, $request->validated('permisos'), $request->ip()
        ));
    }
}
