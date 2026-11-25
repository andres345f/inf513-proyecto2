<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Consultas de los reportes descargables (CU8). Devuelven cursores para no cargar todo en memoria.
 */
class ReporteRepository
{
    public function __construct(private readonly CatalogoRepository $catalogo)
    {
    }

    public function ventas(string $desde, string $hasta): LazyCollection
    {
        return DB::table('pago as pg')->join('pedido as p', 'p.id', '=', 'pg.pedido_id')->join('usuario as u', 'u.id', '=', 'p.usuario_id')
            ->where('pg.estado', 'PAGADO')->whereBetween(DB::raw('pg.fecha_pago::date'), [$desde, $hasta])
            ->orderBy('pg.fecha_pago')
            ->select(['pg.fecha_pago', 'p.codigo', DB::raw("u.nombres || ' ' || u.apellidos AS cliente"), 'pg.metodo', 'pg.monto', 'pg.referencia_externa'])
            ->cursor();
    }

    public function pedidos(string $desde, string $hasta): LazyCollection
    {
        $pagado = DB::table('pago')->selectRaw('pedido_id, SUM(monto) AS pagado')->where('estado', 'PAGADO')->groupBy('pedido_id');

        return DB::table('pedido as p')->join('usuario as u', 'u.id', '=', 'p.usuario_id')
            ->leftJoin('entrega as e', 'e.pedido_id', '=', 'p.id')->leftJoinSub($pagado, 'pg', 'pg.pedido_id', '=', 'p.id')
            ->whereBetween(DB::raw('p.fecha_creacion::date'), [$desde, $hasta])
            ->orderBy('p.fecha_creacion')
            ->select(['p.codigo', 'p.fecha_creacion', DB::raw("u.nombres || ' ' || u.apellidos AS cliente"), 'p.tipo', 'p.estado',
                'e.modalidad', 'p.subtotal', 'p.costo_envio', 'p.total', DB::raw('COALESCE(pg.pagado, 0) AS pagado')])
            ->cursor();
    }

    public function productos(): LazyCollection
    {
        return DB::table('producto as p')->join('categoria as c', 'c.id', '=', 'p.categoria_id')
            ->leftJoinSub($this->catalogo->stockPorProducto(), 's', 's.producto_id', '=', 'p.id')
            ->orderBy('p.nombre')
            ->select(['p.codigo', 'p.nombre', 'c.nombre as categoria', 'p.presentacion', 'p.unidad_medida', 'p.precio_venta',
                DB::raw('COALESCE(s.vendible, 0) AS stock'), 'p.stock_minimo', 'p.activo'])
            ->cursor();
    }
}
