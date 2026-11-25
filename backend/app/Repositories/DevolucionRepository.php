<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Devoluciones (tabla devolucion). Cada una se refiere a un despacho concreto: el movimiento
 * SALIDA_VENTA de un lote para una línea de un pedido ENTREGADO.
 *
 * Los topes (por despacho, por línea y por pedido) son sumas sobre varias filas: la base no los impone. Por eso el
 * servicio bloquea primero la fila del PEDIDO y después lee estas sumas en una sentencia aparte, que ya ve lo que
 * otra operación confirmó mientras esta esperaba.
 */
class DevolucionRepository
{
    /**
     * Unidades de un despacho que ya no se pueden volver a pedir, devolución por devolución:
     * - en trámite (SOLICITADA): lo solicitado;
     * - aprobada o completada: lo mayor entre lo aprobado y lo que FEDER recibió físicamente (una unidad que ya
     *   volvió no puede volver otra vez, aunque no se haya aprobado);
     * - rechazada o anulada: solo lo que se hubiera recibido físicamente (la anulada con rectificación no cuenta:
     *   su mercancía pasó a la devolución que la corrige).
     */
    private const OCUPADO_POR_DEVOLUCION = "CASE
            WHEN d.estado = 'SOLICITADA' THEN GREATEST(d.cantidad, COALESCE(d.cantidad_recibida, 0))
            WHEN d.estado IN ('APROBADA', 'COMPLETADA') THEN GREATEST(COALESCE(d.cantidad_aprobada, 0), COALESCE(d.cantidad_recibida, 0))
            WHEN d.estado = 'ANULADA' AND EXISTS (SELECT 1 FROM devolucion r WHERE r.devolucion_anulada_id = d.id) THEN 0
            ELSE COALESCE(d.cantidad_recibida, 0) END";

    private const DEVUELTO = 'COALESCE((SELECT SUM('.self::OCUPADO_POR_DEVOLUCION.') FROM devolucion d
                               WHERE d.movimiento_inventario_id = m.id), 0)';

    /** Despachos del cliente que todavía admiten devolución. */
    public function devolvibles(int $clienteId): Collection
    {
        return $this->despachos()
            ->where('p.usuario_id', $clienteId)
            ->where('p.estado', 'ENTREGADO')
            ->whereRaw('m.cantidad - '.self::DEVUELTO.' > 0')
            ->orderByDesc('p.fecha_creacion')
            ->get();
    }

    public function despachoDelCliente(int $movimientoId, int $clienteId, bool $bloquear = false): ?object
    {
        $consulta = $this->despachos()->where('m.id', $movimientoId)->where('p.usuario_id', $clienteId);

        return ($bloquear ? $consulta->lock('FOR UPDATE OF m') : $consulta)->first();
    }

    /** Bloquea la fila del despacho (movimiento SALIDA_VENTA). No calcula nada: las sumas se leen después. */
    public function bloquearDespacho(int $movimientoId): void
    {
        DB::table('movimiento_inventario')->where('id', $movimientoId)->lockForUpdate()->first(['id']);
    }

    /** Unidades ya ocupadas del despacho por las demás devoluciones (sin contar la indicada). */
    public function ocupadoDelDespacho(int $movimientoId, ?int $exceptoDevolucionId = null): float
    {
        $fila = DB::table('devolucion as d')
            ->where('d.movimiento_inventario_id', $movimientoId)
            ->when($exceptoDevolucionId !== null, fn ($q) => $q->where('d.id', '<>', $exceptoDevolucionId))
            ->selectRaw('COALESCE(SUM('.self::OCUPADO_POR_DEVOLUCION.'), 0) AS ocupado')
            ->first();

        return (float) $fila->ocupado;
    }

    /**
     * Lo ya aprobado con reembolso en una línea de pedido: cantidad e importe en dinero fijados
     * (devoluciones APROBADA o COMPLETADA con resolución REEMBOLSO de todos los despachos de la línea).
     */
    public function atribuidoDeLinea(int $detallePedidoId): object
    {
        return DB::table('devolucion as d')
            ->join('movimiento_inventario as m', 'm.id', '=', 'd.movimiento_inventario_id')
            ->join('reserva_stock as rs', 'rs.id', '=', 'm.reserva_id')
            ->where('rs.detalle_pedido_id', $detallePedidoId)
            ->where('d.resolucion', 'REEMBOLSO')
            ->whereIn('d.estado', ['APROBADA', 'COMPLETADA'])
            ->selectRaw('COALESCE(SUM(d.cantidad_aprobada), 0) AS cantidad, COALESCE(SUM(d.importe_reembolsable), 0) AS importe')
            ->first();
    }

    /** Importe en dinero ya comprometido en reembolsos por devoluciones no anuladas de todo el pedido. */
    public function atribuidoDelPedido(int $pedidoId): float
    {
        return (float) $this->conEfectoEconomico($pedidoId)->sum('d.importe_reembolsable');
    }

    public function conEfectoEconomico(int $pedidoId): \Illuminate\Database\Query\Builder
    {
        return DB::table('devolucion as d')
            ->join('movimiento_inventario as m', 'm.id', '=', 'd.movimiento_inventario_id')
            ->join('reserva_stock as rs', 'rs.id', '=', 'm.reserva_id')
            ->join('detalle_pedido as dp', 'dp.id', '=', 'rs.detalle_pedido_id')
            ->where('dp.pedido_id', $pedidoId)->whereIn('d.estado', ['APROBADA', 'COMPLETADA'])
            ->where('d.resolucion', 'REEMBOLSO');
    }

    /** Crea la solicitud con el id de su secuencia para armar el código legible (DEV-2026-0004). */
    public function crear(int $movimientoId, string $cantidad, string $motivo): object
    {
        $id = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('devolucion', 'id')) AS id")->id;
        DB::table('devolucion')->insert([
            'id' => $id,
            'movimiento_inventario_id' => $movimientoId,
            'codigo' => sprintf('DEV-%s-%04d', now()->format('Y'), $id),
            'cantidad' => $cantidad,
            'motivo' => $motivo,
            'estado' => 'SOLICITADA',
        ]);

        return $this->buscar($id);
    }

    public function buscar(int $id, bool $bloquear = false): ?object
    {
        $consulta = $this->conDatos()->where('d.id', $id);

        return ($bloquear ? $consulta->lock('FOR UPDATE OF d') : $consulta)->first();
    }

    public function delCliente(int $clienteId): Collection
    {
        return $this->conDatos()->where('p.usuario_id', $clienteId)->orderByDesc('d.fecha_solicitud')->get();
    }

    /** Bandeja del personal: primero las que esperan una acción (solicitadas y aprobadas). */
    public function listarGestion(array $filtros): LengthAwarePaginator
    {
        $consulta = $this->conDatos()
            ->orderByRaw("d.estado IN ('SOLICITADA', 'APROBADA') DESC")
            ->orderByDesc('d.fecha_solicitud');

        foreach (['estado' => 'd.estado', 'resolucion' => 'd.resolucion'] as $filtro => $columna) {
            if (! empty($filtros[$filtro])) {
                $consulta->where($columna, $filtros[$filtro]);
            }
        }
        if (! empty($filtros['q'])) {
            $patron = '%'.addcslashes($filtros['q'], '%_\\').'%';
            $consulta->where(fn ($q) => $q->whereRaw('d.codigo ILIKE ?', [$patron])
                ->orWhereRaw('p.codigo ILIKE ?', [$patron])
                ->orWhereRaw('dp.descripcion_venta ILIKE ?', [$patron])
                ->orWhereRaw("(u.nombres || ' ' || u.apellidos) ILIKE ?", [$patron]));
        }
        if (! empty($filtros['desde'])) {
            $consulta->whereDate('d.fecha_solicitud', '>=', $filtros['desde']);
        }
        if (! empty($filtros['hasta'])) {
            $consulta->whereDate('d.fecha_solicitud', '<=', $filtros['hasta']);
        }

        return $consulta->paginate(15, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));
    }

    /** Cambia la devolución solo si sigue en el estado esperado; devuelve false si otro la cambió antes. */
    public function actualizar(int $id, string $estadoEsperado, array $campos): bool
    {
        return DB::table('devolucion')->where('id', $id)->where('estado', $estadoEsperado)->update($campos) === 1;
    }

    public function aprobar(int $id, array $campos): bool
    {
        return $this->actualizar($id, 'SOLICITADA', $campos + [
            'fecha_recepcion' => DB::raw('now()'),
            'fecha_inspeccion' => DB::raw('now()'),
            'fecha_resolucion' => DB::raw('now()'),
        ]);
    }

    public function rechazar(int $id): bool
    {
        return $this->actualizar($id, 'SOLICITADA', [
            'estado' => 'RECHAZADA',
            'fecha_resolucion' => DB::raw('now()'),
        ]);
    }

    public function completar(int $id): bool
    {
        return $this->actualizar($id, 'APROBADA', [
            'estado' => 'COMPLETADA',
            'fecha_cierre' => DB::raw('now()'),
        ]);
    }

    /** Despachos de venta con su línea de pedido y lo ya devuelto. */
    private function despachos(): Builder
    {
        return DB::table('movimiento_inventario as m')
            ->join('reserva_stock as rs', 'rs.id', '=', 'm.reserva_id')
            ->join('detalle_pedido as dp', 'dp.id', '=', 'rs.detalle_pedido_id')
            ->join('pedido as p', 'p.id', '=', 'dp.pedido_id')
            ->join('producto as pr', 'pr.id', '=', 'dp.producto_id')
            ->where('m.tipo', 'SALIDA_VENTA')
            ->select(['m.id', 'm.cantidad as despachado', 'p.id as pedido_id', 'p.codigo as pedido', 'p.estado as estado_pedido',
                'dp.descripcion_venta as producto', 'dp.producto_id', 'pr.unidad_medida', DB::raw(self::DEVUELTO.' AS devuelto')]);
    }

    private function conDatos(): Builder
    {
        return DB::table('devolucion as d')
            ->join('movimiento_inventario as m', 'm.id', '=', 'd.movimiento_inventario_id')
            ->join('reserva_stock as rs', 'rs.id', '=', 'm.reserva_id')
            ->join('detalle_pedido as dp', 'dp.id', '=', 'rs.detalle_pedido_id')
            ->join('pedido as p', 'p.id', '=', 'dp.pedido_id')
            ->join('usuario as u', 'u.id', '=', 'p.usuario_id')
            ->join('producto as pr', 'pr.id', '=', 'dp.producto_id')
            ->select(['d.*', 'pr.unidad_medida', 'm.lote_feder_id', 'm.cantidad as despachado', 'p.id as pedido_id', 'p.codigo as pedido', 'p.usuario_id as cliente_id',
                'dp.id as detalle_pedido_id', 'dp.producto_id', 'dp.descripcion_venta as producto', 'dp.cantidad as cantidad_linea', 'dp.subtotal as subtotal_linea',
                DB::raw("u.nombres || ' ' || u.apellidos AS cliente")]);
    }
}
