<?php

namespace App\Repositories;

use App\Support\TextoBusqueda;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas del catálogo público: productos activos con su stock vendible y su origen.
 * Stock vendible = saldo libre (disponible − reservado) de lotes DISPONIBLE y no vencidos.
 */
class CatalogoRepository
{
    public const POR_PAGINA = 20;

    public function listar(array $filtros): LengthAwarePaginator
    {
        $consulta = DB::table('producto as p')
            ->join('categoria as c', 'c.id', '=', 'p.categoria_id')
            ->leftJoinSub($this->stockPorProducto(), 's', 's.producto_id', '=', 'p.id')
            ->where('p.activo', true)
            ->where('c.activa', true)
            ->select([
                'p.id', 'p.codigo', 'p.nombre', 'p.descripcion', 'p.presentacion', 'p.unidad_medida',
                'p.precio_venta', 'p.imagen_url', 'p.permite_encargo', 'c.id as categoria_id', 'c.nombre as categoria',
                DB::raw('COALESCE(s.vendible, 0) AS stock'),
            ]);

        $this->aplicarFiltros($consulta, $filtros);

        match ($filtros['orden'] ?? 'nombre') {
            'precio_asc' => $consulta->orderBy('p.precio_venta')->orderBy('p.nombre'),
            'precio_desc' => $consulta->orderByDesc('p.precio_venta')->orderBy('p.nombre'),
            default => $consulta->orderBy('p.nombre'),
        };

        return $consulta->paginate(self::POR_PAGINA, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));
    }

    public function buscarActivo(int $id): ?object
    {
        return DB::table('producto as p')
            ->join('categoria as c', 'c.id', '=', 'p.categoria_id')
            ->leftJoinSub($this->stockPorProducto(), 's', 's.producto_id', '=', 'p.id')
            ->where('p.id', $id)
            ->where('c.activa', true)
            ->where('p.activo', true)
            ->first([
                'p.id', 'p.codigo', 'p.nombre', 'p.descripcion', 'p.presentacion', 'p.unidad_medida',
                'p.precio_venta', 'p.imagen_url', 'p.permite_encargo', 'c.nombre as categoria',
                DB::raw('COALESCE(s.vendible, 0) AS stock'),
            ]);
    }

    /** Comunidades de origen de cada producto: [producto_id => [nombre, ...]]. */
    public function comunidadesDeProductos(array $productoIds): array
    {
        if ($productoIds === []) {
            return [];
        }

        return DB::table('produccion')
            ->whereIn('producto_id', $productoIds)
            ->distinct()
            ->orderBy('nombre_comunidad_origen')
            ->get(['producto_id', 'nombre_comunidad_origen'])
            ->groupBy('producto_id')
            ->map(fn (Collection $filas) => $filas->pluck('nombre_comunidad_origen')->unique()->values()->all())
            ->all();
    }

    /**
     * Trazabilidad de los lotes vendibles de un producto: lote FEDER → lote del productor → producción → comunidad.
     */
    public function trazabilidad(int $productoId): Collection
    {
        return DB::table('lote_feder as lf')
            ->join('detalle_compra as dc', 'dc.id', '=', 'lf.detalle_compra_id')
            ->join('lote_produccion as lp', 'lp.id', '=', 'dc.lote_origen_id')
            ->join('produccion as pr', 'pr.id', '=', 'lp.produccion_id')
            ->join('productor as po', 'po.id', '=', 'pr.productor_id')
            ->join('usuario as u', 'u.id', '=', 'po.usuario_id')
            ->where('pr.producto_id', $productoId)
            ->whereIn('lf.estado', ['DISPONIBLE', 'AGOTADO'])
            ->orderByDesc('lf.fecha_ingreso')
            ->limit(10)
            ->get([
                'lf.codigo as lote_feder', 'lf.fecha_ingreso', 'lf.estado as estado_lote',
                DB::raw('(lf.cantidad_disponible - lf.cantidad_reservada) AS saldo'),
                'lp.codigo_lote', 'lp.fecha_elaboracion', 'lp.fecha_vencimiento',
                'pr.codigo as produccion', 'pr.nombre_comunidad_origen as comunidad', 'pr.municipio_origen as municipio',
                'pr.departamento_origen as departamento', 'po.codigo_productor',
                DB::raw("u.nombres || ' ' || u.apellidos AS productor"),
            ]);
    }

    public function categoriasActivas(): Collection
    {
        return DB::table('categoria')->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']);
    }

    public function comunidadesActivas(): Collection
    {
        return DB::table('comunidad_agricola')->where('activa', true)->orderBy('nombre')->get(['id', 'nombre', 'municipio']);
    }

    /** Subconsulta reutilizable: stock vendible por producto. */
    public function stockPorProducto(): Builder
    {
        return DB::table('lote_feder as lf')
            ->join('detalle_compra as dc', 'dc.id', '=', 'lf.detalle_compra_id')
            ->join('lote_produccion as lp', 'lp.id', '=', 'dc.lote_origen_id')
            ->join('produccion as pr', 'pr.id', '=', 'lp.produccion_id')
            ->where('lf.estado', 'DISPONIBLE')
            ->where(fn ($q) => $q->whereNull('lp.fecha_vencimiento')->orWhere('lp.fecha_vencimiento', '>=', DB::raw('current_date')))
            ->groupBy('pr.producto_id')
            ->select('pr.producto_id', DB::raw('SUM(lf.cantidad_disponible - lf.cantidad_reservada) AS vendible'));
    }

    private function aplicarFiltros(Builder $consulta, array $filtros): void
    {
        if (! empty($filtros['q'])) {
            // Igual que la búsqueda global: sin distinguir tildes ni mayúsculas («totai» encuentra «totaí»).
            $texto = (string) $filtros['q'];
            $consulta->where(fn ($q) => TextoBusqueda::contiene($q, 'p.nombre', $texto)
                ->orWhere(fn ($o) => TextoBusqueda::contiene($o, 'p.descripcion', $texto))
                ->orWhere(fn ($o) => TextoBusqueda::contiene($o, 'c.nombre', $texto)));
        }

        if (! empty($filtros['categoria'])) {
            $consulta->where('p.categoria_id', (int) $filtros['categoria']);
        }

        if (! empty($filtros['comunidad'])) {
            $consulta->whereExists(fn ($q) => $q->from('produccion as pr2')
                ->whereColumn('pr2.producto_id', 'p.id')
                ->where('pr2.comunidad_origen_id', (int) $filtros['comunidad']));
        }

        if (isset($filtros['precio_min']) && $filtros['precio_min'] !== '') {
            $consulta->where('p.precio_venta', '>=', $filtros['precio_min']);
        }

        if (isset($filtros['precio_max']) && $filtros['precio_max'] !== '') {
            $consulta->where('p.precio_venta', '<=', $filtros['precio_max']);
        }

        if (! empty($filtros['con_stock'])) {
            $consulta->whereRaw('COALESCE(s.vendible, 0) > 0');
        }
    }
}
