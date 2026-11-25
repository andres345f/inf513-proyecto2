<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de solo lectura: inventario por lote (personal) y portal del productor.
 */
class InventarioConsultaRepository
{
    public function producciones(array $filtros): LengthAwarePaginator
    {
        $consulta = DB::table('produccion as pr')
            ->join('productor as po', 'po.id', '=', 'pr.productor_id')
            ->join('usuario as u', 'u.id', '=', 'po.usuario_id')
            ->join('producto as p', 'p.id', '=', 'pr.producto_id')
            ->leftJoin('lote_produccion as lp', 'lp.produccion_id', '=', 'pr.id')
            ->groupBy('pr.id', 'po.codigo_productor', 'u.nombres', 'u.apellidos', 'p.nombre', 'p.unidad_medida')
            ->orderByDesc('pr.fecha_inicio')->orderByDesc('pr.id')
            ->select(['pr.id', 'pr.codigo', 'pr.estado', 'pr.fecha_inicio', 'pr.fecha_disponibilidad_estimada',
                'pr.cantidad_estimada', 'pr.ubicacion_produccion', 'pr.observacion', 'pr.nombre_comunidad_origen',
                'po.codigo_productor', DB::raw("u.nombres || ' ' || u.apellidos AS productor"),
                'p.nombre as producto', 'p.unidad_medida', DB::raw('COUNT(lp.id) AS lotes'),
                DB::raw('COALESCE(SUM(lp.cantidad_producida), 0) AS producido'),
                DB::raw("COALESCE(SUM(CASE WHEN lp.estado = 'DISPONIBLE' THEN lp.cantidad_ofrecida ELSE 0 END), 0) AS ofrecido")]);

        if (! empty($filtros['q'])) {
            $patron = '%'.addcslashes($filtros['q'], '%_\\').'%';
            $consulta->where(fn ($q) => $q->whereRaw('pr.codigo ILIKE ?', [$patron])
                ->orWhereRaw('p.nombre ILIKE ?', [$patron])
                ->orWhereRaw("(u.nombres || ' ' || u.apellidos) ILIKE ?", [$patron])
                ->orWhereRaw('po.codigo_productor ILIKE ?', [$patron]));
        }
        if (! empty($filtros['estado'])) {
            $consulta->where('pr.estado', $filtros['estado']);
        }

        return $consulta->paginate(20, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));
    }

    public function lotesDeProducciones(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return DB::table('lote_produccion')->whereIn('produccion_id', $ids)->orderBy('fecha_elaboracion')->orderBy('id')
            ->get(['id', 'produccion_id', 'codigo_lote', 'fecha_elaboracion', 'fecha_vencimiento', 'cantidad_producida',
                'cantidad_ofrecida', 'precio_ofrecido', 'estado']);
    }

    public function compras(array $filtros): LengthAwarePaginator
    {
        $consulta = DB::table('compra_feder as c')
            ->join('productor as po', 'po.id', '=', 'c.productor_id')
            ->join('usuario as u', 'u.id', '=', 'po.usuario_id')
            ->leftJoin('detalle_compra as d', 'd.compra_id', '=', 'c.id')
            ->groupBy('c.id', 'po.codigo_productor', 'u.nombres', 'u.apellidos')
            ->orderByRaw("CASE c.estado WHEN 'REGISTRADA' THEN 0 WHEN 'RECIBIDA_PARCIAL' THEN 1 ELSE 2 END")
            ->orderByDesc('c.fecha_compra')
            ->select(['c.id', 'c.codigo', 'c.fecha_compra', 'c.estado', 'c.total', 'c.fecha_pago_productor', 'c.observacion',
                'po.codigo_productor', DB::raw("u.nombres || ' ' || u.apellidos AS productor"),
                DB::raw('COUNT(d.id) AS lineas'), DB::raw('COALESCE(SUM(d.cantidad_comprada), 0) AS comprado'),
                DB::raw('COALESCE(SUM(d.cantidad_recibida), 0) AS recibido'),
                DB::raw('COALESCE(SUM(d.cantidad_rechazada), 0) AS rechazado'),
                DB::raw('COALESCE(SUM(d.cantidad_cancelada), 0) AS cancelado')]);

        if (! empty($filtros['q'])) {
            $patron = '%'.addcslashes($filtros['q'], '%_\\').'%';
            $consulta->where(fn ($q) => $q->whereRaw('c.codigo ILIKE ?', [$patron])
                ->orWhereRaw("(u.nombres || ' ' || u.apellidos) ILIKE ?", [$patron])
                ->orWhereRaw('po.codigo_productor ILIKE ?', [$patron]));
        }
        if (! empty($filtros['estado'])) {
            $consulta->where('c.estado', $filtros['estado']);
        }
        if (($filtros['pago'] ?? null) === 'PAGADA') {
            $consulta->whereNotNull('c.fecha_pago_productor');
        } elseif (($filtros['pago'] ?? null) === 'PENDIENTE') {
            $consulta->whereNull('c.fecha_pago_productor')->where('c.estado', '<>', 'CANCELADA');
        }
        if (! empty($filtros['desde'])) {
            $consulta->whereDate('c.fecha_compra', '>=', $filtros['desde']);
        }
        if (! empty($filtros['hasta'])) {
            $consulta->whereDate('c.fecha_compra', '<=', $filtros['hasta']);
        }

        return $consulta->paginate(20, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));
    }

    public function detallesDeCompras(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return DB::table('detalle_compra as d')->join('lote_produccion as lp', 'lp.id', '=', 'd.lote_origen_id')
            ->join('produccion as pr', 'pr.id', '=', 'lp.produccion_id')->join('producto as p', 'p.id', '=', 'pr.producto_id')
            ->whereIn('d.compra_id', $ids)->orderBy('d.id')
            ->get(['d.id', 'd.compra_id', 'p.nombre as producto', 'p.unidad_medida', 'lp.codigo_lote',
                'd.cantidad_comprada', 'd.precio_unitario', 'd.cantidad_recibida', 'd.cantidad_rechazada', 'd.cantidad_cancelada']);
    }

    public function lotes(array $filtros): LengthAwarePaginator
    {
        $consulta = DB::table('lote_feder as lf')
            ->join('detalle_compra as dc', 'dc.id', '=', 'lf.detalle_compra_id')
            ->join('lote_produccion as lp', 'lp.id', '=', 'dc.lote_origen_id')
            ->join('produccion as pr', 'pr.id', '=', 'lp.produccion_id')
            ->join('producto as p', 'p.id', '=', 'pr.producto_id')
            ->join('productor as po', 'po.id', '=', 'pr.productor_id')
            ->select(['lf.id', 'lf.codigo', 'lf.fecha_ingreso', 'lf.cantidad_disponible', 'lf.cantidad_reservada', 'lf.estado',
                'lf.ubicacion_almacen', 'lp.codigo_lote', 'lp.fecha_vencimiento', 'p.codigo as producto_codigo', 'p.nombre as producto',
                'p.unidad_medida', 'po.codigo_productor', 'pr.nombre_comunidad_origen as comunidad'])
            ->orderBy('p.nombre')->orderBy('lf.fecha_ingreso');

        if (! empty($filtros['q'])) {
            $patron = '%'.addcslashes($filtros['q'], '%_\\').'%';
            $consulta->where(fn ($q) => $q->whereRaw('p.nombre ILIKE ?', [$patron])->orWhereRaw('lf.codigo ILIKE ?', [$patron])
                ->orWhereRaw('p.codigo ILIKE ?', [$patron]));
        }
        if (! empty($filtros['estado'])) {
            $consulta->where('lf.estado', $filtros['estado']);
        }
        if (! empty($filtros['por_vencer'])) {
            $consulta->whereBetween('lp.fecha_vencimiento', [DB::raw('current_date'), DB::raw("current_date + interval '30 days'")]);
        }

        return $consulta->paginate(20, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));
    }

    public function produccionesDelProductor(int $usuarioId): Collection
    {
        return DB::table('produccion as pr')->join('productor as po', 'po.id', '=', 'pr.productor_id')
            ->join('producto as p', 'p.id', '=', 'pr.producto_id')
            ->leftJoin('lote_produccion as lp', 'lp.produccion_id', '=', 'pr.id')
            ->where('po.usuario_id', $usuarioId)
            ->groupBy('pr.id', 'p.nombre', 'p.unidad_medida')
            ->orderByDesc('pr.fecha_inicio')
            ->get(['pr.id', 'pr.codigo', 'pr.estado', 'pr.fecha_inicio', 'pr.fecha_disponibilidad_estimada', 'pr.cantidad_estimada',
                'pr.nombre_comunidad_origen as comunidad', 'p.nombre as producto', 'p.unidad_medida',
                DB::raw('COUNT(lp.id) AS lotes'), DB::raw('COALESCE(SUM(lp.cantidad_producida), 0) AS producido')]);
    }

    /** Lotes del propio productor autenticado, con metadatos de producción y producto. */
    public function lotesDelProductor(int $usuarioId): Collection
    {
        return DB::table('lote_produccion as lp')
            ->join('produccion as pr', 'pr.id', '=', 'lp.produccion_id')
            ->join('productor as po', 'po.id', '=', 'pr.productor_id')
            ->join('producto as p', 'p.id', '=', 'pr.producto_id')
            ->where('po.usuario_id', $usuarioId)
            ->orderByDesc('lp.id')
            ->get(['lp.*', 'pr.codigo as produccion_codigo', 'pr.estado as estado_produccion',
                'p.nombre as producto', 'p.unidad_medida',
                DB::raw("COALESCE((SELECT SUM(d.cantidad_comprada - d.cantidad_cancelada - d.cantidad_rechazada) FROM detalle_compra d
                 WHERE d.lote_origen_id = lp.id), 0) AS comprometido")]);
    }

    public function comprasAlProductor(int $usuarioId): Collection
    {
        return DB::table('compra_feder as c')->join('productor as po', 'po.id', '=', 'c.productor_id')
            ->leftJoin('detalle_compra as d', 'd.compra_id', '=', 'c.id')
            ->where('po.usuario_id', $usuarioId)
            ->groupBy('c.id')
            ->orderByDesc('c.fecha_compra')
            ->get(['c.id', 'c.codigo', 'c.fecha_compra', 'c.estado', 'c.total', 'c.fecha_pago_productor',
                DB::raw('COUNT(d.id) AS lineas'),
                DB::raw('COALESCE(SUM(d.cantidad_recibida * d.precio_unitario), 0) AS total_recibido')]);
    }
}
