<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mantenimiento del catálogo (CU4): productos (activos e inactivos) y categorías.
 */
class ProductoRepository
{
    public function __construct(private readonly CatalogoRepository $catalogo)
    {
    }

    public function listar(array $filtros): LengthAwarePaginator
    {
        $consulta = DB::table('producto as p')->join('categoria as c', 'c.id', '=', 'p.categoria_id')
            ->leftJoinSub($this->catalogo->stockPorProducto(), 's', 's.producto_id', '=', 'p.id')
            ->select(['p.*', 'c.nombre as categoria', DB::raw('COALESCE(s.vendible, 0) AS stock')])
            ->orderBy('p.nombre');

        if (! empty($filtros['q'])) {
            $patron = '%'.addcslashes($filtros['q'], '%_\\').'%';
            $consulta->where(fn ($q) => $q->whereRaw('p.nombre ILIKE ?', [$patron])->orWhereRaw('p.codigo ILIKE ?', [$patron]));
        }
        if (! empty($filtros['categoria'])) {
            $consulta->where('p.categoria_id', (int) $filtros['categoria']);
        }
        if (isset($filtros['activo']) && $filtros['activo'] !== '') {
            $consulta->where('p.activo', (bool) $filtros['activo']);
        }
        if (! empty($filtros['stock_bajo'])) {
            $consulta->whereRaw('COALESCE(s.vendible, 0) < p.stock_minimo');
        }

        return $consulta->paginate(15, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));
    }

    public function buscar(int $id, bool $bloquear = false): ?object
    {
        $consulta = DB::table('producto')->where('id', $id);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    public function existeCodigo(string $codigo, ?int $excepto = null): bool
    {
        return DB::table('producto')->whereRaw('upper(codigo) = upper(?)', [$codigo])
            ->when($excepto, fn ($q) => $q->where('id', '<>', $excepto))->exists();
    }

    public function usaImagen(string $ruta): bool
    {
        return DB::table('producto')->where('imagen_url', $ruta)->exists();
    }

    public function crear(array $datos): int
    {
        return (int) DB::table('producto')->insertGetId($datos);
    }

    public function actualizar(int $id, array $datos): void
    {
        DB::table('producto')->where('id', $id)->update($datos);
    }

    // ---------------- Categorías ----------------

    public function categorias(): Collection
    {
        return DB::table('categoria as c')->leftJoin('producto as p', 'p.categoria_id', '=', 'c.id')
            ->groupBy('c.id')->orderBy('c.nombre')
            ->get(['c.id', 'c.nombre', 'c.descripcion', 'c.activa', DB::raw('COUNT(p.id) AS productos')]);
    }

    public function existeCategoria(string $nombre, ?int $excepto = null): bool
    {
        return DB::table('categoria')->whereRaw('lower(nombre) = lower(?)', [$nombre])
            ->when($excepto, fn ($q) => $q->where('id', '<>', $excepto))->exists();
    }

    public function guardarCategoria(?int $id, array $datos): ?int
    {
        if ($id === null) {
            return (int) DB::table('categoria')->insertGetId($datos);
        }

        return DB::table('categoria')->where('id', $id)->update($datos) ? $id : null;
    }
}
