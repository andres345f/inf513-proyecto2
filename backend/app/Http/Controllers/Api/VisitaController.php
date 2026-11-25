<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarVisitaRequest;
use App\Services\VisitaService;
use Illuminate\Http\JsonResponse;

class VisitaController extends Controller
{
    public function __construct(private readonly VisitaService $visitas)
    {
    }

    /**
     * Registra la visita a una página y devuelve su contador acumulado (para el pie de página).
     * Respuesta: { pagina, total, registrada }. «registrada» es false cuando la página no existe en la aplicación
     * o quien la pide no puede abrirla: en ese caso no se guarda nada y el total es 0.
     */
    public function store(RegistrarVisitaRequest $request): JsonResponse
    {
        return response()->json($this->visitas->registrar(
            $request->validated('pagina'), $this->sesionActual($request), $request->ip()
        ));
    }
}
