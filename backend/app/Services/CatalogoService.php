<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Repositories\CatalogoRepository;

/**
 * Catálogo público (CU4) con trazabilidad de origen (CU3).
 */
class CatalogoService
{
    public function __construct(private readonly CatalogoRepository $catalogo)
    {
    }

    public function listar(array $filtros): array
    {
        $pagina = $this->catalogo->listar($filtros);
        $comunidades = $this->catalogo->comunidadesDeProductos(collect($pagina->items())->pluck('id')->all());

        return [
            'productos' => collect($pagina->items())->map(fn ($p) => $this->formatear($p) + [
                'comunidades' => $comunidades[$p->id] ?? [],
            ])->all(),
            'paginacion' => [
                'pagina' => $pagina->currentPage(),
                'paginas' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'por_pagina' => $pagina->perPage(),
            ],
        ];
    }

    public function detalle(int $id): array
    {
        $producto = $this->catalogo->buscarActivo($id);

        if ($producto === null) {
            throw new NegocioException('El producto no existe o ya no está a la venta.', 404);
        }

        return $this->formatear($producto) + [
            'comunidades' => $this->catalogo->comunidadesDeProductos([$id])[$id] ?? [],
            'trazabilidad' => $this->catalogo->trazabilidad($id)->map(fn ($l) => [
                'lote_feder' => $l->lote_feder,
                'fecha_ingreso' => $l->fecha_ingreso,
                'disponible' => $l->estado_lote === 'DISPONIBLE' && (float) $l->saldo > 0,
                'lote_productor' => $l->codigo_lote,
                'fecha_elaboracion' => $l->fecha_elaboracion,
                'fecha_vencimiento' => $l->fecha_vencimiento,
                'produccion' => $l->produccion,
                'productor' => $l->productor,
                'codigo_productor' => $l->codigo_productor,
                'comunidad' => $l->comunidad,
                'municipio' => $l->municipio,
                'departamento' => $l->departamento,
            ])->all(),
        ];
    }

    public function filtros(): array
    {
        return [
            'categorias' => $this->catalogo->categoriasActivas(),
            'comunidades' => $this->catalogo->comunidadesActivas(),
        ];
    }

    private function formatear(object $p): array
    {
        $stock = (float) $p->stock;

        return [
            'id' => (int) $p->id,
            'codigo' => $p->codigo,
            'nombre' => $p->nombre,
            'descripcion' => $p->descripcion,
            'presentacion' => $p->presentacion,
            'unidad_medida' => $p->unidad_medida,
            'precio' => (float) $p->precio_venta,
            'imagen_url' => $p->imagen_url,
            'categoria' => $p->categoria,
            'stock' => $stock,
            'permite_encargo' => (bool) $p->permite_encargo,
            'disponibilidad' => $stock > 0 ? 'DISPONIBLE' : ($p->permite_encargo ? 'POR_ENCARGO' : 'AGOTADO'),
        ];
    }
}
