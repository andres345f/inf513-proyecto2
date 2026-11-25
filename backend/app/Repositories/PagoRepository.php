<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Intentos de cobro (tabla pago). Un pedido tiene como máximo un pago PENDIENTE.
 *
 * Orden de bloqueo de todo lo que toca pagos: primero la fila del PEDIDO, después su plan de cuotas y al final el
 * PAGO. Quien llama a los métodos «bloquear…» de esta clase ya tiene bloqueado el pedido.
 */
class PagoRepository
{
    /**
     * @param  string  $claveIdempotencia  clave estable del intento (la arma PagoService): repetir el mismo intento
     *                                     no crea otro pago porque la columna es única
     */
    public function crear(int $pedidoId, string $metodo, string $monto, string $estado, string $referencia, string $claveIdempotencia): object
    {
        $id = (int) DB::table('pago')->insertGetId([
            'pedido_id' => $pedidoId,
            'metodo' => $metodo,
            'monto' => $monto,
            'estado' => $estado,
            'fecha_pago' => $estado === 'PAGADO' ? now() : null,
            'referencia_externa' => $referencia,
            'clave_idempotencia' => $claveIdempotencia,
        ]);

        return $this->buscar($id);
    }

    public function buscar(int $id, bool $bloquear = false): ?object
    {
        $consulta = DB::table('pago')->where('id', $id);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    public function buscarPorClave(string $claveIdempotencia): ?object
    {
        return DB::table('pago')->where('clave_idempotencia', $claveIdempotencia)->first();
    }

    /** El registro de creación conserva la cuota aunque el cobro siga pendiente o termine rechazado. */
    public function cuotaDelIntento(int $pagoId): ?int
    {
        $numero = DB::table('bitacora')->where('entidad', 'PAGO')->where('entidad_id', $pagoId)
            ->where('accion', 'CREAR')->where('detalle->operacion', 'REGISTRAR_PAGO')->value('detalle->cuota');

        return $numero === null ? null : (int) $numero;
    }

    public function contarDelPedido(int $pedidoId): int
    {
        return DB::table('pago')->where('pedido_id', $pedidoId)->count();
    }

    /** PENDIENTE → PAGADO o RECHAZADO; devuelve false si ya no estaba pendiente. */
    public function resolver(int $id, string $estado): bool
    {
        return DB::table('pago')->where('id', $id)->where('estado', 'PENDIENTE')->update([
            'estado' => $estado,
            'fecha_pago' => $estado === 'PAGADO' ? now() : null,
        ]) === 1;
    }

    public function pendienteDelPedido(int $pedidoId, bool $bloquear = true): ?object
    {
        $consulta = DB::table('pago')->where('pedido_id', $pedidoId)->where('estado', 'PENDIENTE');

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    public function totalPagado(int $pedidoId): float
    {
        return (float) DB::table('pago')->where('pedido_id', $pedidoId)->where('estado', 'PAGADO')->sum('monto');
    }

    /**
     * Plazo de pago vencido de un pedido que todavía no tiene ningún pago cobrado. Es el mismo criterio que usa la
     * tarea feder:vencer-pedidos (InventarioRepository::pedidosImpagosVencidos), comprobado aquí para no depender de
     * que haya corrido:
     * - PENDIENTE: conserva reservas ACTIVAS cuya expiración ya pasó (compra inmediata) o se creó hace más de $horas
     *   (encargos, que no reservan stock);
     * - ENCARGO ya aceptado (ESPERANDO_ABASTECIMIENTO) sin efectivo contra entrega pendiente y creado hace más de
     *   $horas (un encargo que el personal aceptó antes de cobrar).
     * Las fechas se comparan en la base (timestamptz contra now()), sin conversiones de zona en PHP.
     */
    public function plazoDePagoVencido(int $pedidoId, int $horas): bool
    {
        return DB::table('pedido as p')
            ->where('p.id', $pedidoId)
            ->whereNotExists(fn ($q) => $q->from('pago as pg')->whereColumn('pg.pedido_id', 'p.id')->where('pg.estado', 'PAGADO'))
            ->where(fn ($q) => $q
                ->where(fn ($pendiente) => $pendiente->where('p.estado', 'PENDIENTE')
                    ->where(fn ($r) => $r->whereRaw('p.fecha_creacion < now() - make_interval(hours => ?)', [$horas])
                        ->orWhereExists(fn ($v) => $v->from('reserva_stock as rs')
                            ->join('detalle_pedido as dp', 'dp.id', '=', 'rs.detalle_pedido_id')
                            ->whereColumn('dp.pedido_id', 'p.id')
                            ->where('rs.estado', 'ACTIVA')
                            ->whereRaw('rs.fecha_expiracion < now()'))))
                ->orWhere(fn ($espera) => $espera->where('p.estado', 'ESPERANDO_ABASTECIMIENTO')->where('p.tipo', 'ENCARGO')
                    ->whereRaw('p.fecha_creacion < now() - make_interval(hours => ?)', [$horas])
                    ->whereNotExists(fn ($e) => $e->from('pago as pe')->whereColumn('pe.pedido_id', 'p.id')
                        ->where('pe.estado', 'PENDIENTE')->where('pe.metodo', 'EFECTIVO'))))
            ->exists();
    }

    /** Bloquea los pagos PAGADO del pedido, siempre en el mismo orden (por id). */
    public function bloquearPagados(int $pedidoId): Collection
    {
        return DB::table('pago')->where('pedido_id', $pedidoId)->where('estado', 'PAGADO')
            ->orderBy('id')->lockForUpdate()->get(['id', 'metodo', 'monto']);
    }

    /**
     * Lo que aún se puede reintegrar de cada pago PAGADO (monto menos reembolsos no fallidos).
     * Es una sentencia aparte de la que bloquea: así la suma ya incluye lo que confirmó otra operación mientras
     * esta esperaba el bloqueo (bajo READ COMMITTED cada sentencia ve los datos confirmados más recientes).
     */
    public function saldosReintegrables(int $pedidoId): Collection
    {
        return DB::table('pago as pg')
            ->where('pg.pedido_id', $pedidoId)->where('pg.estado', 'PAGADO')
            ->orderBy('pg.id')
            ->get(['pg.id', 'pg.metodo', 'pg.monto', DB::raw("pg.monto - COALESCE((SELECT SUM(r.monto) FROM reembolso r
                WHERE r.pago_id = pg.id AND r.estado <> 'FALLIDO'), 0) AS saldo")]);
    }

    /** Pagos PAGADO del pedido con su saldo reintegrable: primero bloquea y después, en otra sentencia, suma. */
    public function pagadosConSaldo(int $pedidoId): Collection
    {
        $this->bloquearPagados($pedidoId);

        return $this->saldosReintegrables($pedidoId);
    }

    /**
     * Reintegro simulado: se registra ya EJECUTADO (no hay pasarela real para devolver dinero).
     * Sin devolución solo procede sobre un pedido CANCELADO (lo exige el disparador de reembolso).
     */
    public function crearReembolso(int $pagoId, ?int $devolucionId, string $monto, string $motivo, ?string $claveIdempotencia = null): object
    {
        $referencia = 'RDV-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
        $id = (int) DB::table('reembolso')->insertGetId([
            'pago_id' => $pagoId,
            'devolucion_id' => $devolucionId,
            'monto' => $monto,
            'estado' => 'EJECUTADO',
            'motivo' => mb_substr($motivo, 0, 200),
            'fecha_ejecucion' => DB::raw('now()'),
            'referencia_externa' => $referencia,
            'clave_idempotencia' => $claveIdempotencia ?? hash('sha256', 'REEMBOLSO|'.($devolucionId ?? 'SIN_DEVOLUCION').'|'.$pagoId),
        ]);

        return (object) ['id' => $id, 'referencia' => $referencia, 'monto' => $monto, 'pago_id' => $pagoId];
    }

    public function reembolsosDelPedido(int $pedidoId): Collection
    {
        return DB::table('reembolso as r')->join('pago as pg', 'pg.id', '=', 'r.pago_id')
            ->where('pg.pedido_id', $pedidoId)->orderBy('r.id')
            ->get(['r.id', 'r.pago_id', 'r.devolucion_id', 'r.monto', 'r.estado', 'r.fecha_solicitud', 'r.fecha_ejecucion']);
    }

    public function delPedido(int $pedidoId): Collection
    {
        return DB::table('pago')->where('pedido_id', $pedidoId)->orderBy('fecha_registro')
            ->get(['id', 'metodo', 'monto', 'estado', 'fecha_registro', 'fecha_pago', 'referencia_externa']);
    }

    public function delCliente(int $clienteId): Collection
    {
        return DB::table('pago as pg')->join('pedido as p', 'p.id', '=', 'pg.pedido_id')
            ->where('p.usuario_id', $clienteId)->orderByDesc('pg.fecha_registro')
            ->get(['pg.id', 'p.id as pedido_id', 'p.codigo as pedido', 'pg.metodo', 'pg.monto', 'pg.estado', 'pg.fecha_registro', 'pg.fecha_pago', 'pg.referencia_externa']);
    }

    /** Bandeja de pagos del personal, con filtros por estado, método, cliente o pedido y fechas. */
    public function listarGestion(array $filtros): LengthAwarePaginator
    {
        $consulta = DB::table('pago as pg')
            ->join('pedido as p', 'p.id', '=', 'pg.pedido_id')
            ->join('usuario as u', 'u.id', '=', 'p.usuario_id')
            ->select(['pg.id', 'pg.metodo', 'pg.monto', 'pg.estado', 'pg.fecha_registro', 'pg.fecha_pago', 'pg.referencia_externa',
                'p.id as pedido_id', 'p.codigo as pedido', 'p.estado as estado_pedido', DB::raw("u.nombres || ' ' || u.apellidos AS cliente")])
            ->orderByRaw("pg.estado = 'PENDIENTE' DESC")
            ->orderByDesc('pg.fecha_registro');

        foreach (['estado' => 'pg.estado', 'metodo' => 'pg.metodo'] as $filtro => $columna) {
            if (! empty($filtros[$filtro])) {
                $consulta->where($columna, $filtros[$filtro]);
            }
        }
        if (! empty($filtros['q'])) {
            $patron = '%'.addcslashes($filtros['q'], '%_\\').'%';
            $consulta->where(fn ($q) => $q->whereRaw('p.codigo ILIKE ?', [$patron])
                ->orWhereRaw('pg.referencia_externa ILIKE ?', [$patron])
                ->orWhereRaw("(u.nombres || ' ' || u.apellidos) ILIKE ?", [$patron]));
        }
        if (! empty($filtros['desde'])) {
            $consulta->whereDate('pg.fecha_registro', '>=', $filtros['desde']);
        }
        if (! empty($filtros['hasta'])) {
            $consulta->whereDate('pg.fecha_registro', '<=', $filtros['hasta']);
        }

        return $consulta->paginate(15, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));
    }
}
