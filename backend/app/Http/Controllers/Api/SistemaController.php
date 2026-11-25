<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SistemaController extends Controller
{
    /** Verificación rápida del despliegue: la API responde y la base de datos está accesible. */
    public function ping(): JsonResponse
    {
        DB::select('SELECT 1');

        return response()->json([
            'estado' => 'ok',
            'sistema' => config('app.name'),
            'hora_servidor' => now()->toIso8601String(),
        ]);
    }
}
