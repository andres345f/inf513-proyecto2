<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Repositories\CarritoRepository;
use App\Repositories\CatalogoRepository;
use App\Support\Cantidad;
use Illuminate\Support\Collection;

/**
 * Carrito persistente del cliente (CU5).
 */
class CarritoService
{
    private const MAXIMO_POR_PRODUCTO = 999;

    public function __construct(
        private readonly CarritoRepository $carrito,
        private readonly CatalogoRepository $catalogo,
    ) {
    }

    public function ver(int $clienteId): array
    {
        return $this->resumen($this->carrito->items($clienteId));
    }

    public function agregar(int $clienteId, int $productoId, float $cantidad): array
    {
        $producto = $this->productoVendible($productoId);
        $this->validarCantidad($producto, $cantidad + $this->carrito->cantidadEnCarrito($clienteId, $productoId));

        $this->carrito->sumar($clienteId, $productoId, Cantidad::texto($cantidad));

        return $this->ver($clienteId);
    }

    public function cambiarCantidad(int $clienteId, int $productoId, float $cantidad): array
    {
        $this->validarCantidad($this->productoVendible($productoId), $cantidad);

        if ($this->carrito->fijarCantidad($clienteId, $productoId, Cantidad::texto($cantidad)) === 0) {
            throw new NegocioException('Ese producto no está en tu carrito.', 404);
        }

        return $this->ver($clienteId);
    }

    public function quitar(int $clienteId, int $productoId): array
    {
        $this->carrito->quitar($clienteId, $productoId);

        return $this->ver($clienteId);
    }

    /** Resumen del carrito con subtotales y avisos de stock. */
    public function resumen(Collection $items): array
    {
        $lineas = $items->map(function ($i) {
            $cantidad = (float) $i->cantidad;
            $stock = (float) $i->stock;

            return [
                'producto_id' => (int) $i->producto_id,
                'codigo' => $i->codigo,
                'nombre' => $i->nombre,
                'presentacion' => $i->presentacion,
                'unidad_medida' => $i->unidad_medida,
                'imagen_url' => $i->imagen_url,
                'precio' => (float) $i->precio_venta,
                'cantidad' => $cantidad,
                'subtotal' => round($cantidad * (float) $i->precio_venta, 2),
                'stock' => $stock,
                'permite_encargo' => (bool) $i->permite_encargo,
                'activo' => (bool) $i->activo,
                'alcanza_stock' => $stock >= $cantidad,
            ];
        })->values();

        return [
            'items' => $lineas->all(),
            'cantidad_productos' => $lineas->count(),
            'subtotal' => round($lineas->sum('subtotal'), 2),
            'todo_con_stock' => $lineas->every(fn ($l) => $l['alcanza_stock'] && $l['activo']),
            'todo_admite_encargo' => $lineas->isNotEmpty() && $lineas->every(fn ($l) => $l['permite_encargo'] && $l['activo']),
        ];
    }

    private function productoVendible(int $productoId): object
    {
        $producto = $this->catalogo->buscarActivo($productoId);

        if ($producto === null) {
            throw new NegocioException('El producto no existe o ya no está a la venta.', 404);
        }

        if ((float) $producto->stock <= 0 && ! $producto->permite_encargo) {
            throw new NegocioException("«{$producto->nombre}» está agotado y no admite encargos.");
        }

        return $producto;
    }

    private function validarCantidad(object $producto, float $cantidad): void
    {
        if ($producto->unidad_medida === 'UNIDAD' && ! Cantidad::esEntera($cantidad)) {
            throw new NegocioException("«{$producto->nombre}» se vende por unidades: la cantidad debe ser un número entero.");
        }

        if ($cantidad > self::MAXIMO_POR_PRODUCTO) {
            throw new NegocioException('Puedes llevar como máximo '.self::MAXIMO_POR_PRODUCTO.' de cada producto por pedido.');
        }
    }
}
