<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Carrito persistente del cliente (tabla item_carrito, un ítem por producto).
 */
class CarritoRepository
{
    public function __construct(private readonly CatalogoRepository $catalogo)
    {
    }

    /** Ítems con los datos vigentes del producto y su stock vendible. */
    public function items(int $clienteId, bool $bloquear = false): Collection
    {
        $consulta = DB::table('item_carrito as i')
            ->join('producto as p', 'p.id', '=', 'i.producto_id')
            ->join('categoria as c', 'c.id', '=', 'p.categoria_id')
            ->leftJoinSub($this->catalogo->stockPorProducto(), 's', 's.producto_id', '=', 'p.id')
            ->where('i.cliente_id', $clienteId)
            ->orderBy('i.fecha_agregado')
            ->select([
                'i.id', 'i.producto_id', 'i.cantidad', 'p.codigo', 'p.nombre', 'p.presentacion', 'p.unidad_medida',
                'p.precio_venta', 'p.imagen_url', 'p.permite_encargo', DB::raw('(p.activo AND c.activa) AS activo'), DB::raw('COALESCE(s.vendible, 0) AS stock'),
            ]);

        if ($bloquear) {
            // Bloquea los ítems del cliente para que dos confirmaciones simultáneas no conviertan el mismo carrito.
            DB::table('item_carrito')->where('cliente_id', $clienteId)->lockForUpdate()->get(['id']);
        }

        return $consulta->get();
    }

    public function sumar(int $clienteId, int $productoId, string $cantidad): void
    {
        DB::statement(
            'INSERT INTO item_carrito (cliente_id, producto_id, cantidad) VALUES (?, ?, ?)
             ON CONFLICT (cliente_id, producto_id) DO UPDATE SET cantidad = item_carrito.cantidad + EXCLUDED.cantidad',
            [$clienteId, $productoId, $cantidad]
        );
    }

    public function fijarCantidad(int $clienteId, int $productoId, string $cantidad): int
    {
        return DB::table('item_carrito')
            ->where('cliente_id', $clienteId)
            ->where('producto_id', $productoId)
            ->update(['cantidad' => $cantidad]);
    }

    public function quitar(int $clienteId, int $productoId): int
    {
        return DB::table('item_carrito')->where('cliente_id', $clienteId)->where('producto_id', $productoId)->delete();
    }

    public function quitarItems(array $itemIds): void
    {
        DB::table('item_carrito')->whereIn('id', $itemIds)->delete();
    }

    public function cantidadEnCarrito(int $clienteId, int $productoId): float
    {
        return (float) DB::table('item_carrito')->where('cliente_id', $clienteId)->where('producto_id', $productoId)->value('cantidad');
    }
}
