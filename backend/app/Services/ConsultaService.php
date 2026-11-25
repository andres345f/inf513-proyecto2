<?php

namespace App\Services;

use App\Repositories\InventarioConsultaRepository;

/**
 * Consultas de inventario (CU4) y del portal del productor (CU3 producción, CU7 indicadores propios).
 */
class ConsultaService
{
    public function __construct(private readonly InventarioConsultaRepository $consultas) {}

    public function inventario(array $filtros): array
    {
        $pagina = $this->consultas->lotes($filtros);

        return [
            'lotes' => collect($pagina->items())->map(fn ($l) => [
                'id' => (int) $l->id,
                'codigo' => $l->codigo,
                'producto' => $l->producto,
                'producto_codigo' => $l->producto_codigo,
                'unidad_medida' => $l->unidad_medida,
                'disponible' => (float) $l->cantidad_disponible,
                'reservado' => (float) $l->cantidad_reservada,
                'libre' => (float) $l->cantidad_disponible - (float) $l->cantidad_reservada,
                'estado' => $l->estado,
                'ubicacion' => $l->ubicacion_almacen,
                'fecha_ingreso' => $l->fecha_ingreso,
                'lote_origen' => $l->codigo_lote,
                'vence' => $l->fecha_vencimiento,
                'productor' => $l->codigo_productor,
                'comunidad' => $l->comunidad,
            ])->all(),
            'paginacion' => ['pagina' => $pagina->currentPage(), 'paginas' => $pagina->lastPage(), 'total' => $pagina->total()],
        ];
    }

    public function produccionesGestion(array $filtros): array
    {
        $pagina = $this->consultas->producciones($filtros);
        $ids = collect($pagina->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $lotes = $this->consultas->lotesDeProducciones($ids)->groupBy('produccion_id');

        return [
            'producciones' => collect($pagina->items())->map(fn ($p) => [
                'id' => (int) $p->id,
                'codigo' => $p->codigo,
                'estado' => $p->estado,
                'producto' => $p->producto,
                'unidad_medida' => $p->unidad_medida,
                'productor' => $p->productor,
                'codigo_productor' => $p->codigo_productor,
                'comunidad' => $p->nombre_comunidad_origen,
                'fecha_inicio' => $p->fecha_inicio,
                'disponible_desde' => $p->fecha_disponibilidad_estimada,
                'estimado' => $p->cantidad_estimada !== null ? (float) $p->cantidad_estimada : null,
                'producido' => (float) $p->producido,
                'ofrecido' => (float) $p->ofrecido,
                'ubicacion' => $p->ubicacion_produccion,
                'observacion' => $p->observacion,
                'lotes' => collect($lotes->get($p->id, collect()))->map(fn ($l) => [
                    'id' => (int) $l->id,
                    'codigo' => $l->codigo_lote,
                    'elaborado' => $l->fecha_elaboracion,
                    'vence' => $l->fecha_vencimiento,
                    'producido' => (float) $l->cantidad_producida,
                    'ofrecido' => (float) $l->cantidad_ofrecida,
                    'precio' => $l->precio_ofrecido !== null ? (float) $l->precio_ofrecido : null,
                    'estado' => $l->estado,
                ])->values()->all(),
            ])->all(),
            'paginacion' => ['pagina' => $pagina->currentPage(), 'paginas' => $pagina->lastPage(), 'total' => $pagina->total()],
        ];
    }

    public function comprasGestion(array $filtros): array
    {
        $pagina = $this->consultas->compras($filtros);
        $ids = collect($pagina->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $detalles = $this->consultas->detallesDeCompras($ids)->groupBy('compra_id');

        return [
            'compras' => collect($pagina->items())->map(fn ($c) => [
                'id' => (int) $c->id,
                'codigo' => $c->codigo,
                'fecha' => $c->fecha_compra,
                'estado' => $c->estado,
                'total' => (float) $c->total,
                'pagada_el' => $c->fecha_pago_productor,
                'productor' => $c->productor,
                'codigo_productor' => $c->codigo_productor,
                'observacion' => $c->observacion,
                'comprado' => (float) $c->comprado,
                'recibido' => (float) $c->recibido,
                'rechazado' => (float) $c->rechazado,
                'cancelado' => (float) $c->cancelado,
                'detalles' => collect($detalles->get($c->id, collect()))->map(fn ($d) => [
                    'id' => (int) $d->id,
                    'producto' => $d->producto,
                    'unidad_medida' => $d->unidad_medida,
                    'lote' => $d->codigo_lote,
                    'comprado' => (float) $d->cantidad_comprada,
                    'precio' => (float) $d->precio_unitario,
                    'recibido' => (float) $d->cantidad_recibida,
                    'rechazado' => (float) $d->cantidad_rechazada,
                    'cancelado' => (float) $d->cantidad_cancelada,
                ])->values()->all(),
            ])->all(),
            'paginacion' => ['pagina' => $pagina->currentPage(), 'paginas' => $pagina->lastPage(), 'total' => $pagina->total()],
        ];
    }

    public function producciones(int $usuarioId): array
    {
        return $this->consultas->produccionesDelProductor($usuarioId)->map(fn ($p) => [
            'id' => (int) $p->id,
            'codigo' => $p->codigo,
            'producto' => $p->producto,
            'unidad_medida' => $p->unidad_medida,
            'estado' => $p->estado,
            'comunidad' => $p->comunidad,
            'fecha_inicio' => $p->fecha_inicio,
            'disponible_desde' => $p->fecha_disponibilidad_estimada,
            'estimado' => $p->cantidad_estimada !== null ? (float) $p->cantidad_estimada : null,
            'lotes' => (int) $p->lotes,
            'producido' => (float) $p->producido,
        ])->all();
    }

    /** Lotes del propio productor autenticado (solo los suyos; los de otros productores no aparecen). */
    public function lotesDelProductor(int $usuarioId): array
    {
        return $this->consultas->lotesDelProductor($usuarioId)->map(fn ($l) => [
            'id' => (int) $l->id,
            'codigo_lote' => $l->codigo_lote,
            'produccion_id' => (int) $l->produccion_id,
            'produccion_codigo' => $l->produccion_codigo,
            'estado_produccion' => $l->estado_produccion,
            'producto' => $l->producto,
            'unidad_medida' => $l->unidad_medida,
            'fecha_elaboracion' => $l->fecha_elaboracion,
            'fecha_vencimiento' => $l->fecha_vencimiento,
            'cantidad_producida' => (float) $l->cantidad_producida,
            'cantidad_ofrecida' => (float) $l->cantidad_ofrecida,
            'comprometido' => (float) $l->comprometido,
            'precio_ofrecido' => $l->precio_ofrecido !== null ? (float) $l->precio_ofrecido : null,
            'estado' => $l->estado,
        ])->all();
    }

    /** Ventas del productor a FEDER y sus indicadores. */
    public function ventasDelProductor(int $usuarioId): array
    {
        $compras = $this->consultas->comprasAlProductor($usuarioId);
        $validas = $compras->where('estado', '<>', 'CANCELADA');

        return [
            'indicadores' => [
                'total_vendido' => round($validas->sum(fn ($c) => (float) $c->total_recibido), 2),
                'cobrado' => round($validas->whereNotNull('fecha_pago_productor')->sum(fn ($c) => (float) $c->total_recibido), 2),
                'por_cobrar' => round($validas->where('estado', 'RECIBIDA')->whereNull('fecha_pago_productor')->sum(fn ($c) => (float) $c->total), 2),
                'compras' => $validas->count(),
            ],
            'por_mes' => $validas->groupBy(fn ($c) => substr((string) $c->fecha_compra, 0, 7))
                ->map(fn ($grupo, $mes) => ['mes' => $mes, 'total' => round($grupo->sum(fn ($c) => (float) $c->total_recibido), 2)])
                ->sortKeys()->values()->all(),
            'compras' => $compras->map(fn ($c) => [
                'id' => (int) $c->id,
                'codigo' => $c->codigo,
                'fecha' => $c->fecha_compra,
                'estado' => $c->estado,
                'total' => (float) $c->total,
                'total_recibido' => (float) $c->total_recibido,
                'lineas' => (int) $c->lineas,
                'pagada_el' => $c->fecha_pago_productor,
            ])->all(),
        ];
    }
}
