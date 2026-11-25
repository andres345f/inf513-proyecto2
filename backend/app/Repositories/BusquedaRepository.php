<?php

namespace App\Repositories;

use App\Support\TextoBusqueda;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de la búsqueda global del encabezado (solo datos públicos y activos).
 *
 * La comparación no distingue tildes ni mayúsculas (App\Support\TextoBusqueda): «cafe» encuentra «Café».
 */
class BusquedaRepository
{
    public function productos(string $texto, int $limite): Collection
    {
        return DB::table('producto as p')
            ->join('categoria as c', 'c.id', '=', 'p.categoria_id')
            ->where('p.activo', true)
            ->where('c.activa', true)
            ->where(fn ($q) => $this->contiene($q, 'p.nombre', $texto)->orWhere(fn ($o) => $this->contiene($o, 'p.codigo', $texto)))
            ->orderBy('p.nombre')
            ->limit($limite)
            ->get(['p.id', 'p.nombre', 'p.presentacion', 'p.precio_venta', 'c.nombre as categoria']);
    }

    public function categorias(string $texto, int $limite): Collection
    {
        return DB::table('categoria')
            ->where('activa', true)
            ->where(fn ($q) => $this->contiene($q, 'nombre', $texto))
            ->orderBy('nombre')
            ->limit($limite)
            ->get(['id', 'nombre']);
    }

    public function comunidades(string $texto, int $limite): Collection
    {
        return DB::table('comunidad_agricola')
            ->where('activa', true)
            ->where(fn ($q) => $this->contiene($q, 'nombre', $texto)->orWhere(fn ($o) => $this->contiene($o, 'municipio', $texto)))
            ->orderBy('nombre')
            ->limit($limite)
            ->get(['id', 'nombre', 'municipio']);
    }

    /** columna (sin tildes) ILIKE %texto (sin tildes)%. El nombre de columna es fijo en esta clase, nunca del usuario. */
    private function contiene(Builder $consulta, string $columna, string $texto): Builder
    {
        return TextoBusqueda::contiene($consulta, $columna, $texto);
    }
}
