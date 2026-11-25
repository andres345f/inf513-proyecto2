<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ActualizarPerfilRequest;
use App\Http\Requests\Auth\CambiarContrasenaRequest;
use App\Http\Requests\Auth\CambiarRolRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegistroRequest;
use App\Services\AutenticacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutenticacionController extends Controller
{
    public function __construct(private readonly AutenticacionService $autenticacion)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $datos = $request->validated();

        return response()->json($this->autenticacion->iniciarSesion(
            $datos['correo'], $datos['password'], $datos['rol_id'] ?? null, $request->ip()
        ));
    }

    public function registro(RegistroRequest $request): JsonResponse
    {
        return response()->json($this->autenticacion->registrarCliente($request->validated(), $request->ip()), 201);
    }

    public function yo(Request $request): JsonResponse
    {
        return response()->json($this->autenticacion->perfil($this->sesionActual($request)));
    }

    public function actualizarPerfil(ActualizarPerfilRequest $request): JsonResponse
    {
        return response()->json($this->autenticacion->actualizarPerfil($this->sesionActual($request), $request->validated(), $request->ip()));
    }

    public function cambiarContrasena(CambiarContrasenaRequest $request): JsonResponse
    {
        return response()->json($this->autenticacion->cambiarContrasena(
            $this->sesionActual($request), $request->validated('contrasena_actual'), $request->validated('contrasena_nueva'), $request->ip()
        ));
    }

    public function cambiarRol(CambiarRolRequest $request): JsonResponse
    {
        return response()->json($this->autenticacion->cambiarRolActivo(
            $this->sesionActual($request), (int) $request->validated('rol_id'), $request->ip()
        ));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->autenticacion->cerrarSesion($this->sesionActual($request), $request->ip());

        return response()->json(['message' => 'Cerraste sesión correctamente.']);
    }
}
