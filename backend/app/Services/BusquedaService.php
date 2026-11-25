<?php

namespace App\Services;

use App\Repositories\BusquedaRepository;

/**
 * Búsqueda global del encabezado: productos, categorías y comunidades.
 */
class BusquedaService
{
    private const LIMITE_POR_GRUPO = 5;

    public function __construct(private readonly BusquedaRepository $busqueda)
    {
    }

    public function buscar(string $texto): array
    {
        $texto = trim($texto);

        return [
            'consulta' => $texto,
            'productos' => $this->busqueda->productos($texto, self::LIMITE_POR_GRUPO)->map(fn ($p) => [
                'id' => (int) $p->id,
                'nombre' => $p->nombre,
                'detalle' => $p->presentacion.' · '.$p->categoria,
                'precio' => (float) $p->precio_venta,
            ])->all(),
            'categorias' => $this->busqueda->categorias($texto, self::LIMITE_POR_GRUPO)->map(fn ($c) => [
                'id' => (int) $c->id,
                'nombre' => $c->nombre,
            ])->all(),
            'comunidades' => $this->busqueda->comunidades($texto, self::LIMITE_POR_GRUPO)->map(fn ($c) => [
                'id' => (int) $c->id,
                'nombre' => $c->nombre,
                'detalle' => $c->municipio,
            ])->all(),
        ];
    }
}
