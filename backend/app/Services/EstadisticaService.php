<?php

namespace App\Services;

use App\Repositories\EstadisticaRepository;
use Carbon\CarbonImmutable;

/**
 * Panel de negocio (CU7 indicadores, CU8 estadísticas) y estadísticas de acceso al sitio.
 */
class EstadisticaService
{
    public function __construct(private readonly EstadisticaRepository $estadisticas)
    {
    }

    /**
     * Rango por defecto: últimos 12 meses (negocio). Definiciones en EstadisticaRepository:
     * ventas = ingresos netos (pagos confirmados − reintegros ejecutados); pedidos = pedidos vendidos;
     * ticket_promedio = total medio de los pedidos vendidos.
     */
    public function negocio(?string $desde, ?string $hasta): array
    {
        [$desde, $hasta] = $this->rango($desde, $hasta, 365);
        $brutos = $this->estadisticas->cobrosBrutos($desde, $hasta);
        $reintegros = $this->estadisticas->reintegrosEjecutados($desde, $hasta);
        $vendidos = $this->estadisticas->pedidosVendidos($desde, $hasta);
        $pedidos = (int) $vendidos->cantidad;

        return [
            'rango' => ['desde' => $desde, 'hasta' => $hasta],
            'indicadores' => [
                'ventas' => round($brutos - $reintegros, 2),
                'ventas_brutas' => round($brutos, 2),
                'reintegros' => round($reintegros, 2),
                'pedidos' => $pedidos,
                'ticket_promedio' => $pedidos > 0 ? round((float) $vendidos->total / $pedidos, 2) : 0,
                'clientes_nuevos' => $this->estadisticas->clientesNuevos($desde, $hasta),
                'pedidos_en_curso' => $this->estadisticas->pedidosEnCurso(),
                'productos_stock_bajo' => $this->estadisticas->productosConStockBajo(),
            ],
            'ventas_por_mes' => $this->estadisticas->ventasPorMes($desde, $hasta)
                ->map(fn ($f) => ['mes' => $f->mes, 'total' => (float) $f->total, 'cobrado' => (float) $f->cobrado,
                    'reintegrado' => (float) $f->reintegrado, 'pagos' => (int) $f->pagos]),
            'pedidos_por_estado' => $this->estadisticas->pedidosPorEstado($desde, $hasta)
                ->map(fn ($f) => ['estado' => $f->estado, 'cantidad' => (int) $f->cantidad]),
            'productos_mas_vendidos' => $this->estadisticas->productosMasVendidos($desde, $hasta)
                ->map(fn ($f) => ['nombre' => $f->nombre, 'cantidad' => (float) $f->cantidad, 'monto' => (float) $f->monto]),
            'pagos_por_metodo' => $this->estadisticas->pagosPorMetodo($desde, $hasta)
                ->map(fn ($f) => ['metodo' => $f->metodo, 'monto' => (float) $f->monto, 'cobrado' => (float) $f->cobrado,
                    'reintegrado' => (float) $f->reintegrado, 'cantidad' => (int) $f->cantidad]),
        ];
    }

    /** Rango por defecto: últimos 30 días (accesos). */
    public function accesos(?string $desde, ?string $hasta): array
    {
        [$desde, $hasta] = $this->rango($desde, $hasta, 30);

        return [
            'rango' => ['desde' => $desde, 'hasta' => $hasta],
            'total_visitas' => $this->estadisticas->totalVisitas($desde, $hasta),
            'visitas_por_dia' => $this->estadisticas->visitasPorDia($desde, $hasta)
                ->map(fn ($f) => ['dia' => $f->dia, 'visitas' => (int) $f->visitas]),
            'paginas_mas_visitadas' => $this->estadisticas->paginasMasVisitadas($desde, $hasta)
                ->map(fn ($f) => ['pagina' => $f->pagina, 'visitas' => (int) $f->visitas]),
            'inicios_de_sesion' => $this->estadisticas->inicioSesionPorDia($desde, $hasta)
                ->map(fn ($f) => ['dia' => $f->dia, 'exitosos' => (int) $f->exitosos, 'fallidos' => (int) $f->fallidos]),
            'ips_con_mas_fallos' => $this->estadisticas->ipsConMasFallos($desde, $hasta)
                ->map(fn ($f) => ['ip' => $f->ip, 'fallidos' => (int) $f->fallidos]),
        ];
    }

    public function bitacora(array $filtros): array
    {
        $pagina = $this->estadisticas->bitacora($filtros);

        return [
            'registros' => collect($pagina->items())->map(fn ($r) => [
                'id' => (int) $r->id,
                'fecha' => $r->fecha,
                'accion' => $r->accion,
                'entidad' => $r->entidad,
                'entidad_id' => $r->entidad_id ? (int) $r->entidad_id : null,
                'usuario' => $r->usuario,
                'correo' => $r->correo,
                'ip' => $r->ip,
                'detalle' => json_decode($r->detalle ?? 'null', true),
            ])->all(),
            'paginacion' => ['pagina' => $pagina->currentPage(), 'paginas' => $pagina->lastPage(), 'total' => $pagina->total()],
        ];
    }

    private function rango(?string $desde, ?string $hasta, int $diasPorDefecto): array
    {
        $fin = $hasta ? CarbonImmutable::parse($hasta) : CarbonImmutable::today();
        $inicio = $desde ? CarbonImmutable::parse($desde) : $fin->subDays($diasPorDefecto - 1);

        return [$inicio->toDateString(), $fin->toDateString()];
    }
}
