<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangoFechasRequest;
use App\Services\AuditoriaService;
use App\Services\ReporteService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Descarga de reportes en CSV (abre en Excel). */
class ReporteController extends Controller
{
    public function __construct(
        private readonly ReporteService $reportes,
        private readonly AuditoriaService $auditoria,
    ) {
    }

    public function descargar(RangoFechasRequest $request, string $tipo): StreamedResponse
    {
        $reporte = $this->reportes->generar($tipo, $request->validated('desde'), $request->validated('hasta'));
        $this->auditoria->operacion($this->sesionActual($request)->usuario->id, 'CREAR', 'REPORTE', null,
            ['reporte' => $tipo, 'archivo' => $reporte['nombre']], $request->ip());

        return response()->streamDownload(function () use ($reporte) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $reporte['encabezados'], ';');
            foreach ($reporte['filas'] as $fila) {
                fputcsv($salida, $fila, ';');
            }
            fclose($salida);
        }, $reporte['nombre'], ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
