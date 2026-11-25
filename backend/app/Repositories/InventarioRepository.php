<?php

namespace App\Repositories;

use App\Exceptions\NegocioException;
use App\Services\AbastecimientoService;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reservas y salidas de stock sobre lote_feder, reserva_stock y movimiento_inventario.
 *
 * Reglas del modelo aprobado que cuida esta clase (matriz de restricciones R026, R035, R037, R043 y R055):
 * - FEFO: se toma primero el lote que vence antes; los lotes sin vencimiento van al final; después deciden
 *   la fecha de ingreso y el id (igual que AsignadorFefo del Proyecto 1).
 * - Un lote es utilizable si está DISPONIBLE y no venció (vence al día siguiente de su fecha de vencimiento, según el
 *   reloj real en el calendario de Bolivia); el descuento del despacho lo vuelve a comprobar dentro de su UPDATE.
 * - Los lotes se bloquean siempre por orden de id y cada cambio comprueba que afectó exactamente su fila:
 *   si algo no cuadra se lanza una excepción y la transacción de quien llama se revierte completa.
 * - Quien llama ya tiene bloqueada la fila del pedido; así dos operaciones del mismo pedido no se cruzan.
 */
class InventarioRepository
{
    /**
     * Fecha civil de hoy según el reloj real, en la zona de la sesión (America/La_Paz, H-14). No se usa current_date:
     * PostgreSQL la fija al inicio de la transacción y un despacho que cruza la medianoche seguiría viendo el día anterior.
     */
    private const HOY = 'CAST(clock_timestamp() AS date)';

    /** Condición SQL de «lote vencido» (alias lp = lote_produccion de origen), con el reloj real. */
    private const VENCIDO = '(lp.fecha_vencimiento IS NOT NULL AND lp.fecha_vencimiento < '.self::HOY.')';

    // ------------------------------------------------------------------ lotes

    /** Lotes vendibles de un producto en orden FEFO, bloqueados para reservar. */
    public function lotesVendibles(int $productoId): Collection
    {
        return $this->lotesVendiblesDeProductos([$productoId])->get($productoId, collect());
    }

    /**
     * Lotes vendibles de varios productos, bloqueados en una sola pasada por orden de id.
     *
     * @param  list<int>  $productoIds
     * @return Collection<int, Collection<int, object>> por producto, cada grupo en orden FEFO
     */
    public function lotesVendiblesDeProductos(array $productoIds): Collection
    {
        if ($productoIds === []) {
            return collect();
        }

        return $this->consultaLotes()
            ->whereIn('pr.producto_id', $productoIds)
            ->where(fn (Builder $q) => $this->soloVendibles($q))
            ->orderBy('lf.id')
            ->lock('FOR UPDATE OF lf')
            ->get()
            ->groupBy(fn ($lote) => (int) $lote->producto_id)
            ->map(fn (Collection $grupo) => self::ordenFefo($grupo));
    }

    /**
     * Bloquea por orden de id un lote y los lotes vendibles de su mismo producto (candidatos para reasignar).
     *
     * @return array{0: ?object, 1: Collection<int, object>} el lote y los candidatos en orden FEFO (sin el lote)
     */
    public function bloquearLoteConVendibles(int $loteId): array
    {
        $productoId = DB::table('lote_feder as lf')
            ->join('detalle_compra as dc', 'dc.id', '=', 'lf.detalle_compra_id')
            ->join('lote_produccion as lp', 'lp.id', '=', 'dc.lote_origen_id')
            ->join('produccion as pr', 'pr.id', '=', 'lp.produccion_id')
            ->where('lf.id', $loteId)->value('pr.producto_id');
        if ($productoId === null) {
            return [null, collect()];
        }

        $filas = $this->consultaLotes()
            ->where(fn (Builder $q) => $q->where('lf.id', $loteId)
                ->orWhere(fn (Builder $v) => $this->soloVendibles($v->where('pr.producto_id', $productoId))))
            ->orderBy('lf.id')
            ->lock('FOR UPDATE OF lf')
            ->get();

        return [
            $filas->first(fn ($l) => (int) $l->id === $loteId),
            self::ordenFefo($filas->reject(fn ($l) => (int) $l->id === $loteId)),
        ];
    }

    /** Lotes DISPONIBLE o AGOTADO cuyo lote de origen ya venció: los que el proceso automático pasa a VENCIDO. */
    public function lotesPorVencer(): array
    {
        return $this->consultaLotes()
            ->whereIn('lf.estado', ['DISPONIBLE', 'AGOTADO'])
            ->whereRaw(self::VENCIDO)
            ->orderBy('lf.id')
            ->pluck('lf.id')->map(fn ($id) => (int) $id)->all();
    }

    /** DISPONIBLE o AGOTADO → VENCIDO; false si el lote ya había cambiado de estado. */
    public function marcarVencido(int $loteId): bool
    {
        return DB::update("UPDATE lote_feder SET estado = 'VENCIDO' WHERE id = ? AND estado IN ('DISPONIBLE', 'AGOTADO')", [$loteId]) === 1;
    }

    /** Prioridad compartida por el evento de stock, la tarea y el botón del personal. */
    public function encargosEnEspera(?int $antesDe = null): array
    {
        return DB::table('pedido')->where('tipo', 'ENCARGO')->where('estado', 'ESPERANDO_ABASTECIMIENTO')
            ->when($antesDe !== null, fn ($q) => $q->whereRaw(
                '(fecha_creacion, id) < (SELECT fecha_creacion, id FROM pedido WHERE id = ?)', [$antesDe]
            ))
            ->orderBy('fecha_creacion')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    // ------------------------------------------------------------------ reservas

    /** Suma la cantidad a lo reservado del lote solo si sigue habiendo saldo libre (UPDATE condicional). */
    public function reservarEnLote(int $loteId, string $cantidad): bool
    {
        return DB::update(
            "UPDATE lote_feder SET cantidad_reservada = cantidad_reservada + ?
              WHERE id = ? AND estado = 'DISPONIBLE' AND cantidad_disponible - cantidad_reservada >= ?
                AND NOT EXISTS (SELECT 1 FROM detalle_compra dc JOIN lote_produccion lp ON lp.id = dc.lote_origen_id
                                 WHERE dc.id = lote_feder.detalle_compra_id AND ".self::VENCIDO.')',
            [$cantidad, $loteId, $cantidad]
        ) === 1;
    }

    /** @param  string|null  $expiraEn  momento en que vence la reserva; null si no vence (pedido ya confirmado o encargo abastecido) */
    public function crearReserva(int $loteId, int $detallePedidoId, string $cantidad, ?string $expiraEn): int
    {
        return (int) DB::table('reserva_stock')->insertGetId([
            'lote_feder_id' => $loteId,
            'detalle_pedido_id' => $detallePedidoId,
            'cantidad' => $cantidad,
            'estado' => 'ACTIVA',
            'fecha_expiracion' => $expiraEn,
        ]);
    }

    /** Con el pago confirmado, las reservas del pedido dejan de vencer. */
    public function fijarReservas(int $pedidoId): void
    {
        DB::update(
            "UPDATE reserva_stock SET fecha_expiracion = NULL
              WHERE estado = 'ACTIVA' AND detalle_pedido_id IN (SELECT id FROM detalle_pedido WHERE pedido_id = ?)",
            [$pedidoId]
        );
    }

    /**
     * Cierra las reservas ACTIVAS del pedido devolviendo lo reservado a cada lote.
     * Estado final: LIBERADA (cancelación) o EXPIRADA (venció el plazo de pago). Devuelve cuántas cerró.
     */
    public function liberarReservas(int $pedidoId, string $estadoFinal = 'LIBERADA'): int
    {
        $liberadas = $this->cerrarYDevolver($this->reservasActivas($pedidoId), $estadoFinal);
        if ($liberadas > 0) {
            AbastecimientoService::revisarTrasCommit();
        }

        return $liberadas;
    }

    /**
     * Libera las reservas ACTIVAS del pedido que quedaron en un lote que ya no sirve para despachar
     * (vencido, bloqueado o en otro estado), para poder reasignarlas. Devuelve cuántas liberó.
     */
    public function liberarReservasNoUtilizables(int $pedidoId): int
    {
        return $this->cerrarYDevolver($this->reservasActivas($pedidoId)->reject(fn ($r) => (bool) $r->utilizable), 'LIBERADA');
    }

    /** Reservas ACTIVAS que tiene un lote, con su pedido, en orden de creación. */
    public function reservasActivasDeLote(int $loteId): Collection
    {
        return $this->consultaReservasActivas()->where('rs.lote_feder_id', $loteId)->get();
    }

    /** Pedidos que tienen alguna reserva ACTIVA en el lote, por orden de id. */
    public function pedidosConReservaActivaEnLote(int $loteId): array
    {
        return DB::table('reserva_stock as rs')
            ->join('detalle_pedido as dp', 'dp.id', '=', 'rs.detalle_pedido_id')
            ->where('rs.lote_feder_id', $loteId)->where('rs.estado', 'ACTIVA')
            ->distinct()->orderBy('dp.pedido_id')->pluck('dp.pedido_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Cierra reservas ACTIVAS de un lote ya bloqueado por quien llama (vencimiento del lote). */
    public function liberarReservasDeLote(Collection $reservas): int
    {
        return $this->cerrarYDevolver($reservas, 'LIBERADA', bloquearLotes: false);
    }

    /**
     * Cobertura de cada línea del pedido: lo pedido, lo reservado (ACTIVA) y cuánto de eso está en lotes
     * que ya no sirven para despachar. «pendiente» es lo que falta reservar en lotes utilizables.
     */
    public function coberturaDePedido(int $pedidoId): Collection
    {
        $noUtilizable = "rs.estado = 'ACTIVA' AND (lf.estado <> 'DISPONIBLE' OR ".self::VENCIDO.')';

        return DB::table('detalle_pedido as dp')
            ->leftJoin('reserva_stock as rs', 'rs.detalle_pedido_id', '=', 'dp.id')
            ->leftJoin('lote_feder as lf', 'lf.id', '=', 'rs.lote_feder_id')
            ->leftJoin('detalle_compra as dc', 'dc.id', '=', 'lf.detalle_compra_id')
            ->leftJoin('lote_produccion as lp', 'lp.id', '=', 'dc.lote_origen_id')
            ->where('dp.pedido_id', $pedidoId)
            ->groupBy('dp.id', 'dp.producto_id', 'dp.cantidad', 'dp.descripcion_venta')
            ->orderBy('dp.id')
            ->get(['dp.id', 'dp.producto_id', 'dp.cantidad', 'dp.descripcion_venta',
                DB::raw("COALESCE(SUM(rs.cantidad) FILTER (WHERE rs.estado = 'ACTIVA'), 0) AS reservado"),
                DB::raw("COALESCE(SUM(rs.cantidad) FILTER (WHERE {$noUtilizable}), 0) AS no_utilizable")])
            ->map(function ($linea) {
                $linea->pendiente = round((float) $linea->cantidad - ((float) $linea->reservado - (float) $linea->no_utilizable), 3);

                return $linea;
            });
    }

    // ------------------------------------------------------------------ despacho

    /**
     * Despacho atómico (regla R055): exige que cada línea esté cubierta exactamente por reservas ACTIVAS y que
     * todos sus lotes sigan utilizables; después cada reserva pasa a CONSUMIDA, su lote descuenta saldo físico y
     * reservado (queda AGOTADO si llega a cero) y se registra una SALIDA_VENTA por reserva.
     * Si una comprobación o un UPDATE falla, lanza NegocioException (409) y no deja nada consumido.
     *
     * @return list<array{movimiento_id:int, reserva_id:int, lote_feder_id:int, cantidad:string}>
     */
    public function consumirReservas(int $pedidoId, int $usuarioId): array
    {
        $lineas = DB::table('detalle_pedido')->where('pedido_id', $pedidoId)->orderBy('id')->lockForUpdate()
            ->get(['id', 'cantidad', 'descripcion_venta']);
        $reservas = $this->reservasActivas($pedidoId);

        if ($lineas->isEmpty()) {
            throw new NegocioException('El pedido no tiene productos que despachar.', 409);
        }
        foreach ($lineas as $linea) {
            $reservado = $reservas->where('detalle_pedido_id', $linea->id)->sum(fn ($r) => self::milesimas($r->cantidad));
            if ($reservado !== self::milesimas($linea->cantidad)) {
                throw new NegocioException("El pedido no puede despacharse: «{$linea->descripcion_venta}» no está cubierto por completo con stock reservado "
                    .'(reservado '.self::legible($reservado / 1000).' de '.self::legible((float) $linea->cantidad).'). '
                    .'Usa «Abastecer» para reservar lo que falta o cancela el pedido.', 409);
            }
        }

        $lotes = $this->bloquearLotes($reservas->pluck('lote_feder_id')->all());
        foreach ($reservas as $reserva) {
            $lote = $lotes->get((int) $reserva->lote_feder_id)
                ?? throw new NegocioException('Un lote reservado para este pedido ya no existe. Recarga la página.', 409);
            if (! $lote->utilizable) {
                $motivo = $lote->vencido ? 'vencido' : mb_strtolower($lote->estado);
                throw new NegocioException("El lote {$lote->codigo} está {$motivo}: no se despacha desde él. "
                    .'Usa «Abastecer» para reasignar el stock a otro lote o cancela el pedido.', 409);
            }
        }

        $salidas = [];
        foreach ($reservas as $reserva) {
            self::exigirUnaFila(DB::update(
                "UPDATE reserva_stock SET estado = 'CONSUMIDA', fecha_cierre = now() WHERE id = ? AND estado = 'ACTIVA'",
                [$reserva->id]
            ), 'Una reserva del pedido cambió de estado durante el despacho.');
            if (! $this->descontarReservaDelLote($reserva)) {
                $lote = $lotes->get((int) $reserva->lote_feder_id);
                throw new NegocioException($this->vencidoAhora((int) $reserva->lote_feder_id)
                    ? "El lote {$lote->codigo} venció mientras se despachaba: no se despacha desde él. "
                        .'Usa «Abastecer» para reasignar el stock a otro lote o cancela el pedido.'
                    : 'El saldo de un lote no coincide con lo reservado para este pedido: no se descontó nada. Recarga la página e inténtalo de nuevo.', 409);
            }

            $movimientoId = (int) DB::table('movimiento_inventario')->insertGetId([
                'lote_feder_id' => $reserva->lote_feder_id,
                'usuario_id' => $usuarioId,
                'reserva_id' => $reserva->id,
                'tipo' => 'SALIDA_VENTA',
                'cantidad' => $reserva->cantidad,
                'motivo' => 'Despacho del pedido',
                'clave_operacion' => hash('sha256', 'SALIDA_VENTA|'.$reserva->id),
            ]);
            $salidas[] = ['movimiento_id' => $movimientoId, 'reserva_id' => (int) $reserva->id,
                'lote_feder_id' => (int) $reserva->lote_feder_id, 'cantidad' => (string) $reserva->cantidad];
        }

        return $salidas;
    }

    /**
     * Descuento condicional de una reserva en su lote (despacho): resta la cantidad del saldo físico y de lo reservado
     * solo si el lote sigue DISPONIBLE, con saldo suficiente y sin vencer según el reloj real en el momento del UPDATE
     * (no la fecha del inicio de la transacción). El lote queda AGOTADO si llega a cero. false si no se descontó nada.
     */
    public function descontarReservaDelLote(object $reserva): bool
    {
        return DB::update(
            "UPDATE lote_feder SET cantidad_disponible = cantidad_disponible - ?, cantidad_reservada = cantidad_reservada - ?,
                    estado = CASE WHEN cantidad_disponible - ? = 0 THEN 'AGOTADO' ELSE estado END
              WHERE id = ? AND estado = 'DISPONIBLE' AND cantidad_reservada >= ? AND cantidad_disponible >= ?
                AND NOT EXISTS (SELECT 1 FROM detalle_compra dc JOIN lote_produccion lp ON lp.id = dc.lote_origen_id
                                 WHERE dc.id = lote_feder.detalle_compra_id AND ".self::VENCIDO.')',
            [$reserva->cantidad, $reserva->cantidad, $reserva->cantidad, $reserva->lote_feder_id, $reserva->cantidad, $reserva->cantidad]
        ) === 1;
    }

    /** ¿El lote de origen ya venció según el reloj real? */
    private function vencidoAhora(int $loteId): bool
    {
        return DB::table('lote_feder as lf')
            ->join('detalle_compra as dc', 'dc.id', '=', 'lf.detalle_compra_id')
            ->join('lote_produccion as lp', 'lp.id', '=', 'dc.lote_origen_id')
            ->where('lf.id', $loteId)->whereRaw(self::VENCIDO)->exists();
    }

    // ------------------------------------------------------------------ devoluciones

    /** Reingresa al lote del despacho las unidades aptas de una devolución (ENTRADA_DEVOLUCION). */
    public function reingresarDevolucion(int $loteId, string $cantidad, int $devolucionId, int $usuarioId, string $codigo): void
    {
        DB::update(
            "UPDATE lote_feder SET cantidad_disponible = cantidad_disponible + ?,
                    estado = CASE WHEN estado = 'AGOTADO' THEN 'DISPONIBLE' ELSE estado END
              WHERE id = ?",
            [$cantidad, $loteId]
        );
        DB::table('movimiento_inventario')->insert([
            'lote_feder_id' => $loteId,
            'usuario_id' => $usuarioId,
            'devolucion_id' => $devolucionId,
            'tipo' => 'ENTRADA_DEVOLUCION',
            'cantidad' => $cantidad,
            'motivo' => "Reingreso de las unidades aptas de la devolución {$codigo}",
            'clave_operacion' => hash('sha256', 'ENTRADA_DEVOLUCION|'.$devolucionId),
        ]);
        AbastecimientoService::revisarTrasCommit();
    }

    /**
     * Saca del stock libre, por FEFO, las unidades de reposición de una devolución (SALIDA_REPOSICION).
     * Devuelve false si no hay stock suficiente.
     */
    public function salidaReposicion(int $productoId, float $cantidad, int $devolucionId, int $usuarioId, string $codigo): bool
    {
        $lotes = $this->lotesVendibles($productoId);
        if ($lotes->sum(fn ($l) => (float) $l->libre) + 0.0005 < $cantidad) {
            return false;
        }

        $falta = $cantidad;
        foreach ($lotes as $lote) {
            if ($falta <= 0) {
                break;
            }
            $tomar = number_format(min($falta, (float) $lote->libre), 3, '.', '');
            self::exigirUnaFila(DB::update(
                "UPDATE lote_feder SET cantidad_disponible = cantidad_disponible - ?,
                        estado = CASE WHEN cantidad_disponible - ? = 0 THEN 'AGOTADO' ELSE estado END
                  WHERE id = ? AND estado = 'DISPONIBLE' AND cantidad_disponible - cantidad_reservada >= ?",
                [$tomar, $tomar, $lote->id, $tomar]
            ), 'El stock libre del lote cambió durante la reposición.');
            DB::table('movimiento_inventario')->insert([
                'lote_feder_id' => $lote->id,
                'usuario_id' => $usuarioId,
                'devolucion_id' => $devolucionId,
                'tipo' => 'SALIDA_REPOSICION',
                'cantidad' => $tomar,
                'motivo' => "Reposición de la devolución {$codigo}",
                'clave_operacion' => hash('sha256', 'SALIDA_REPOSICION|'.$devolucionId.'|'.$lote->id),
            ]);
            $falta -= (float) $tomar;
        }

        return true;
    }

    // ------------------------------------------------------------------ plazos de pago

    /**
     * Pedidos impagos cuyo plazo de pago venció (PROMPT 7.2, 48 h):
     * - PENDIENTE con reservas ACTIVAS ya vencidas (compra inmediata) o creados antes del límite (encargos, que no
     *   reservan stock, y pedidos que perdieron sus reservas);
     * - ENCARGO en ESPERANDO_ABASTECIMIENTO creados antes del límite sin ningún pago PAGADO ni efectivo contra entrega
     *   pendiente. Los que tienen un QR o TARJETA pendiente también se devuelven, para informarlos (R053).
     * Quien llama vuelve a comprobar cada pedido con su fila bloqueada.
     */
    public function pedidosImpagosVencidos(CarbonInterface $creadosAntesDe): array
    {
        $limite = $creadosAntesDe->toDateTimeString();

        return DB::table('pedido as p')
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $pendiente) => $pendiente->where('p.estado', 'PENDIENTE')
                    ->where(fn (Builder $r) => $r->where('p.fecha_creacion', '<', $limite)
                        ->orWhereExists(fn (Builder $v) => $this->reservaVencidaDe($v)->whereColumn('dp.pedido_id', 'p.id'))))
                ->orWhere(fn (Builder $espera) => $espera->where('p.estado', 'ESPERANDO_ABASTECIMIENTO')->where('p.tipo', 'ENCARGO')
                    ->where('p.fecha_creacion', '<', $limite)
                    ->whereNotExists(fn (Builder $g) => $g->from('pago as g')->whereColumn('g.pedido_id', 'p.id')
                        ->where(fn (Builder $c) => $c->where('g.estado', 'PAGADO')
                            ->orWhere(fn (Builder $e) => $e->where('g.estado', 'PENDIENTE')->where('g.metodo', 'EFECTIVO'))))))
            ->orderBy('p.id')
            ->pluck('p.id')->map(fn ($id) => (int) $id)->all();
    }

    public function tieneReservasVencidas(int $pedidoId): bool
    {
        return $this->reservaVencidaDe(DB::query())->where('dp.pedido_id', $pedidoId)->exists();
    }

    // ------------------------------------------------------------------ apoyo interno

    /** Lotes con su producto, vencimiento del origen, saldo libre y si siguen sirviendo para vender o despachar. */
    private function consultaLotes(): Builder
    {
        return DB::table('lote_feder as lf')
            ->join('detalle_compra as dc', 'dc.id', '=', 'lf.detalle_compra_id')
            ->join('lote_produccion as lp', 'lp.id', '=', 'dc.lote_origen_id')
            ->join('produccion as pr', 'pr.id', '=', 'lp.produccion_id')
            ->select(['lf.id', 'lf.codigo', 'lf.estado', 'lf.cantidad_disponible', 'lf.cantidad_reservada', 'pr.producto_id', 'lp.fecha_vencimiento',
                DB::raw('extract(epoch FROM lf.fecha_ingreso) AS ingreso'),
                DB::raw('(lf.cantidad_disponible - lf.cantidad_reservada) AS libre'),
                DB::raw(self::VENCIDO.' AS vencido'),
                DB::raw("(lf.estado = 'DISPONIBLE' AND NOT ".self::VENCIDO.') AS utilizable')]);
    }

    /** Filtros de «lote vendible»: DISPONIBLE, no vencido y con saldo libre. */
    private function soloVendibles(Builder $consulta): Builder
    {
        return $consulta->where('lf.estado', 'DISPONIBLE')
            ->whereRaw('NOT '.self::VENCIDO)
            ->whereRaw('lf.cantidad_disponible - lf.cantidad_reservada > 0');
    }

    /** FEFO: vencimiento ascendente con los nulos al final, luego fecha de ingreso y por último id. */
    private static function ordenFefo(Collection $lotes): Collection
    {
        return $lotes->sort(fn ($a, $b) => [$a->fecha_vencimiento === null, (string) $a->fecha_vencimiento, (float) $a->ingreso, (int) $a->id]
            <=> [$b->fecha_vencimiento === null, (string) $b->fecha_vencimiento, (float) $b->ingreso, (int) $b->id])->values();
    }

    /** @param  list<int|string>  $ids */
    private function bloquearLotes(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return collect();
        }

        return $this->consultaLotes()->whereIn('lf.id', $ids)->orderBy('lf.id')->lock('FOR UPDATE OF lf')->get()
            ->keyBy(fn ($lote) => (int) $lote->id);
    }

    private function consultaReservasActivas(): Builder
    {
        return DB::table('reserva_stock as rs')
            ->join('detalle_pedido as dp', 'dp.id', '=', 'rs.detalle_pedido_id')
            ->join('lote_feder as lf', 'lf.id', '=', 'rs.lote_feder_id')
            ->join('detalle_compra as dc', 'dc.id', '=', 'lf.detalle_compra_id')
            ->join('lote_produccion as lp', 'lp.id', '=', 'dc.lote_origen_id')
            ->where('rs.estado', 'ACTIVA')
            ->orderBy('rs.id')
            ->select(['rs.id', 'rs.lote_feder_id', 'rs.detalle_pedido_id', 'rs.cantidad', 'rs.fecha_expiracion',
                'dp.pedido_id', 'dp.producto_id', 'dp.descripcion_venta',
                DB::raw("(lf.estado = 'DISPONIBLE' AND NOT ".self::VENCIDO.') AS utilizable')]);
    }

    /** Reservas ACTIVAS del pedido. El pedido ya está bloqueado por quien llama, así que no cambian mientras se usan. */
    private function reservasActivas(int $pedidoId): Collection
    {
        return $this->consultaReservasActivas()->where('dp.pedido_id', $pedidoId)->get();
    }

    /** Cierra cada reserva y devuelve su cantidad a lo reservado del lote, comprobando cada fila afectada. */
    private function cerrarYDevolver(Collection $reservas, string $estadoFinal, bool $bloquearLotes = true): int
    {
        if ($bloquearLotes) {
            $this->bloquearLotes($reservas->pluck('lote_feder_id')->all());
        }

        foreach ($reservas as $reserva) {
            self::exigirUnaFila(DB::update(
                "UPDATE reserva_stock SET estado = ?, fecha_cierre = now() WHERE id = ? AND estado = 'ACTIVA'",
                [$estadoFinal, $reserva->id]
            ), 'Una reserva del pedido cambió de estado mientras se liberaba.');
            self::exigirUnaFila(DB::update(
                'UPDATE lote_feder SET cantidad_reservada = cantidad_reservada - ? WHERE id = ? AND cantidad_reservada >= ?',
                [$reserva->cantidad, $reserva->lote_feder_id, $reserva->cantidad]
            ), 'Lo reservado en un lote no coincide con las reservas del pedido: no se liberó nada.');
        }

        return $reservas->count();
    }

    private function reservaVencidaDe(Builder $consulta): Builder
    {
        return $consulta->from('reserva_stock as rs')
            ->join('detalle_pedido as dp', 'dp.id', '=', 'rs.detalle_pedido_id')
            ->where('rs.estado', 'ACTIVA')
            ->where('rs.fecha_expiracion', '<', now()->toDateTimeString());
    }

    private static function exigirUnaFila(int $afectadas, string $mensaje): void
    {
        if ($afectadas !== 1) {
            throw new NegocioException($mensaje.' Recarga la página e inténtalo de nuevo.', 409);
        }
    }

    /** Cantidad NUMERIC(12,3) en milésimas enteras, para comparar sin errores de coma flotante. */
    private static function milesimas(string|float|int $cantidad): int
    {
        return (int) round((float) $cantidad * 1000);
    }

    private static function legible(float $cantidad): string
    {
        return rtrim(rtrim(number_format($cantidad, 3, ',', ''), '0'), ',');
    }
}
