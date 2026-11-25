<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Usuario;
use App\Repositories\ConfiguracionWebRepository;
use App\Repositories\ProductoRepository;
use App\Support\Cantidad;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mantenimiento del catálogo por el Personal de FEDER (CU4): productos, precios, peso por unidad y categorías.
 * Cada cambio de precio queda en la bitácora como CAMBIO_PRECIO.
 */
class ProductoService
{
    public function __construct(
        private readonly ProductoRepository $productos,
        private readonly ConfiguracionWebRepository $configuracion,
        private readonly AuditoriaService $auditoria,
        private readonly FotoProductoService $fotos,
    ) {
    }

    public function listar(array $filtros): array
    {
        $pagina = $this->productos->listar($filtros);
        $pesos = $this->configuracion->pesosDeProductos(collect($pagina->items())->pluck('id')->map(fn ($id) => (int) $id)->all());

        return [
            'productos' => collect($pagina->items())->map(fn ($p) => $this->formatear($p) + ['peso_kg' => $pesos[(int) $p->id] ?? null])->all(),
            'paginacion' => ['pagina' => $pagina->currentPage(), 'paginas' => $pagina->lastPage(), 'total' => $pagina->total()],
        ];
    }

    public function guardar(Usuario $personal, ?int $id, array $datos, ?string $ip): array
    {
        if ($this->productos->existeCodigo($datos['codigo'], $id)) {
            throw ValidationException::withMessages(['codigo' => ['Ya existe un producto con ese código.']]);
        }

        $fila = [
            'categoria_id' => (int) $datos['categoria_id'],
            'codigo' => strtoupper(trim($datos['codigo'])),
            'nombre' => trim($datos['nombre']),
            'descripcion' => $datos['descripcion'] ?? null,
            'presentacion' => trim($datos['presentacion']),
            'unidad_medida' => $datos['unidad_medida'],
            'precio_venta' => Cantidad::monto((float) $datos['precio_venta']),
            'stock_minimo' => Cantidad::texto((float) $datos['stock_minimo']),
            'permite_encargo' => (bool) ($datos['permite_encargo'] ?? false),
            'activo' => (bool) ($datos['activo'] ?? true),
        ];

        if ($fila['unidad_medida'] === 'UNIDAD' && ! Cantidad::esEntera((float) $datos['stock_minimo'])) {
            throw ValidationException::withMessages(['stock_minimo' => ['Para productos por unidad, el stock mínimo debe ser entero.']]);
        }

        $nueva = isset($datos['foto']) ? $this->fotos->guardar($datos['foto']) : null;
        if ($nueva !== null || ! empty($datos['retirar_foto'])) {
            $fila['imagen_url'] = $nueva;
        }
        // Una propuesta puede envolver el alta del producto en otra transacción.
        if ($nueva !== null && DB::transactionLevel() > 0) {
            DB::afterRollBack(fn () => $this->fotos->eliminarSiLibre($nueva));
        }
        try {
            $productoId = $this->guardarProducto($personal, $id, $fila, $datos, $ip);
        } catch (\Throwable $error) {
            $this->fotos->eliminarSiLibre($nueva);
            throw $error;
        }

        return ['id' => $productoId, 'message' => $id === null ? 'Producto creado.' : 'Producto actualizado.'];
    }

    private function guardarProducto(Usuario $personal, ?int $id, array $fila, array $datos, ?string $ip): int
    {
        return DB::transaction(function () use ($personal, $id, $fila, $datos, $ip) {
            if ($id === null) {
                $nuevoId = $this->productos->crear($fila);
                $this->auditoria->operacion($personal->id, 'CREAR', 'PRODUCTO', $nuevoId, ['codigo' => $fila['codigo']], $ip);
            } else {
                $actual = $this->productos->buscar($id, bloquear: true) ?? throw new NegocioException('Ese producto no existe.', 404);
                $this->productos->actualizar($id, $fila);
                if (array_key_exists('imagen_url', $fila) && $actual->imagen_url !== $fila['imagen_url']) {
                    DB::afterCommit(fn () => $this->fotos->eliminarSiLibre($actual->imagen_url));
                }
                if ((float) $actual->precio_venta !== (float) $fila['precio_venta']) {
                    $this->auditoria->operacion($personal->id, 'CAMBIO_PRECIO', 'PRODUCTO', $id,
                        ['precio_anterior' => (float) $actual->precio_venta, 'precio_nuevo' => (float) $fila['precio_venta']], $ip);
                }
                $this->auditoria->operacion($personal->id, 'MODIFICAR', 'PRODUCTO', $id, ['codigo' => $fila['codigo']], $ip);
                $nuevoId = $id;
            }

            if (isset($datos['peso_kg']) && $datos['peso_kg'] !== null && $datos['peso_kg'] !== '') {
                $this->configuracion->guardarPesoProducto($nuevoId, (float) $datos['peso_kg'], config('feder.correo_sistema'));
            }

            return $nuevoId;
        });
    }

    public function categorias(): array
    {
        return $this->productos->categorias()->map(fn ($c) => [
            'id' => (int) $c->id, 'nombre' => $c->nombre, 'descripcion' => $c->descripcion,
            'activa' => (bool) $c->activa, 'productos' => (int) $c->productos,
        ])->all();
    }

    public function guardarCategoria(Usuario $personal, ?int $id, array $datos, ?string $ip): array
    {
        if ($this->productos->existeCategoria($datos['nombre'], $id)) {
            throw ValidationException::withMessages(['nombre' => ['Ya existe una categoría con ese nombre.']]);
        }

        $categoriaId = DB::transaction(function () use ($personal, $id, $datos, $ip) {
            $guardada = $this->productos->guardarCategoria($id, [
                'nombre' => trim($datos['nombre']),
                'descripcion' => $datos['descripcion'] ?? null,
                'activa' => (bool) ($datos['activa'] ?? true),
            ]) ?? throw new NegocioException('Esa categoría no existe.', 404);
            $this->auditoria->operacion($personal->id, $id === null ? 'CREAR' : 'MODIFICAR', 'CATEGORIA', $guardada, ['nombre' => $datos['nombre']], $ip);

            return $guardada;
        });

        return ['id' => $categoriaId, 'message' => $id === null ? 'Categoría creada.' : 'Categoría actualizada.'];
    }

    private function formatear(object $p): array
    {
        return [
            'id' => (int) $p->id,
            'codigo' => $p->codigo,
            'nombre' => $p->nombre,
            'descripcion' => $p->descripcion,
            'presentacion' => $p->presentacion,
            'imagen_url' => $p->imagen_url,
            'unidad_medida' => $p->unidad_medida,
            'precio_venta' => (float) $p->precio_venta,
            'stock_minimo' => (float) $p->stock_minimo,
            'stock' => (float) $p->stock,
            'permite_encargo' => (bool) $p->permite_encargo,
            'activo' => (bool) $p->activo,
            'categoria_id' => (int) $p->categoria_id,
            'categoria' => $p->categoria,
        ];
    }
}
