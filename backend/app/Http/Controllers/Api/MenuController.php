<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PermisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function __construct(private readonly PermisoService $permisos)
    {
    }

    /** Menú del rol activo (o del visitante si no hay sesión), leído de la base de datos. */
    public function index(Request $request): JsonResponse
    {
        $sesion = $this->sesionActual($request);

        return response()->json([
            'rol' => $this->permisos->rolEfectivo($sesion),
            'grupos' => $this->permisos->menuPara($sesion),
        ]);
    }
}
