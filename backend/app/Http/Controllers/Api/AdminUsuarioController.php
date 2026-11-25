<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AsignarRolRequest;
use App\Http\Requests\Admin\CambiarActivoUsuarioRequest;
use App\Http\Requests\Admin\CrearUsuarioRequest;
use App\Http\Requests\Admin\ListarUsuariosRequest;
use App\Http\Requests\Admin\RevocarRolRequest;
use App\Services\AdminUsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Administración de usuarios y roles (solo ADMINISTRADOR). */
class AdminUsuarioController extends Controller
{
    public function __construct(private readonly AdminUsuarioService $usuarios)
    {
    }

    public function index(ListarUsuariosRequest $request): JsonResponse
    {
        return response()->json($this->usuarios->listar($request->validated()));
    }

    public function store(CrearUsuarioRequest $request): JsonResponse
    {
        return response()->json($this->usuarios->crear($this->admin($request), $request->validated(), $request->ip()), 201);
    }

    public function activo(CambiarActivoUsuarioRequest $request, int $usuario): JsonResponse
    {
        $activo = (bool) $request->validated('activo');
        $this->usuarios->cambiarActivo($this->admin($request), $usuario, $activo, $request->ip());

        return response()->json(['message' => $activo ? 'Cuenta activada.' : 'Cuenta desactivada y sesiones cerradas.']);
    }

    public function asignarRol(AsignarRolRequest $request, int $usuario): JsonResponse
    {
        $this->usuarios->asignarRol($this->admin($request), $usuario, $request->validated('rol'), $request->ip());

        return response()->json(['message' => 'Rol asignado.']);
    }

    public function revocarRol(RevocarRolRequest $request, int $usuario, string $rol): JsonResponse
    {
        $this->usuarios->revocarRol($this->admin($request), $usuario, strtoupper($rol), $request->validated('motivo'), $request->ip());

        return response()->json(['message' => 'Rol revocado y sesiones del usuario cerradas.']);
    }

    private function admin(Request $request)
    {
        return $this->sesionActual($request)->usuario;
    }
}
