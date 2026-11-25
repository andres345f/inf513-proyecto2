<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos, líneas, entrega e historial de estados.
 */
class PedidoRepository
{
    public const ESTADOS = ['PENDIENTE', 'ESPERANDO_ABASTECIMIENTO', 'CONFIRMADO', 'EN_PREPARACION', 'DESPACHADO', 'ENTREGADO', 'CANCELADO'];

    /** Crea el pedido con el id de su secuencia para armar el código legible (PED-2026-000012). */
    public function crear(array $datos): object
    {
        $id = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('pedido', 'id')) AS id")->id;
        $codigo = sprintf('PED-%s-%06d', now()->format('Y'), $id);

        DB::table('pedido')->insert($datos + ['id' => $id, 'codigo' => $codigo]);

        return $this->buscar($id);
    }

    public function crearLinea(array $datos): int
    {
        return (int) DB::table('detalle_pedido')->insertGetId($datos);
    }

    public function crearEntrega(array $datos): int
    {
        return (int) DB::table('entrega')->insertGetId($datos);
    }

    public function registrarHistorial(int $pedidoId, ?int $usuarioId, ?string $anterior, string $nuevo, ?string $comentario): void
    {
        DB::table('historial_pedido')->insert([
            'pedido_id' => $pedidoId,
            'usuario_id' => $usuarioId,
            'estado_anterior' => $anterior,
            'estado_nuevo' => $nuevo,
            'comentario' => $comentario,
        ]);
    }

    /** Cambia el estado solo si sigue en el esperado (evita carreras). */
    public function cambiarEstado(int $pedidoId, string $desde, string $hacia): bool
    {
        return DB::table('pedido')->where('id', $pedidoId)->where('estado', $desde)->update(['estado' => $hacia]) === 1;
    }

    public function buscar(int $id, bool $bloquear = false): ?object
    {
        $consulta = DB::table('pedido')->where('id', $id);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    public function buscarDelCliente(int $id, int $clienteId, bool $bloquear = false): ?object
    {
        $consulta = DB::table('pedido')->where('id', $id)->where('usuario_id', $clienteId);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    public function listarDelCliente(int $clienteId, array $filtros): LengthAwarePaginator
    {
        $consulta = DB::table('pedido as p')
            ->leftJoin('entrega as e', 'e.pedido_id', '=', 'p.id')
            ->where('p.usuario_id', $clienteId)
            ->select(['p.id', 'p.codigo', 'p.tipo', 'p.estado', 'p.fecha_creacion', 'p.total', 'e.modalidad'])
            ->orderByDesc('p.fecha_creacion');

        if (! empty($filtros['estado'])) {
            $consulta->where('p.estado', $filtros['estado']);
        }
        if (! empty($filtros['tipo'])) {
            $consulta->where('p.tipo', $filtros['tipo']);
        }
        if (! empty($filtros['desde'])) {
            $consulta->whereDate('p.fecha_creacion', '>=', $filtros['desde']);
        }
        if (! empty($filtros['hasta'])) {
            $consulta->whereDate('p.fecha_creacion', '<=', $filtros['hasta']);
        }

        return $consulta->paginate(10, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));
    }

    /** Bandeja del personal: todos los pedidos con su cliente, entrega y monto pagado. */
    public function listarGestion(array $filtros): LengthAwarePaginator
    {
        $pagado = DB::table('pago')->selectRaw('pedido_id, SUM(monto) AS pagado')->where('estado', 'PAGADO')->groupBy('pedido_id');

        $consulta = DB::table('pedido as p')
            ->join('usuario as u', 'u.id', '=', 'p.usuario_id')
            ->leftJoin('entrega as e', 'e.pedido_id', '=', 'p.id')
            ->leftJoinSub($pagado, 'pg', 'pg.pedido_id', '=', 'p.id')
            ->select(['p.id', 'p.codigo', 'p.tipo', 'p.estado', 'p.fecha_creacion', 'p.total', 'e.modalidad', 'e.estado as estado_entrega',
                DB::raw("u.nombres || ' ' || u.apellidos AS cliente"), DB::raw('COALESCE(pg.pagado, 0) AS pagado')])
            ->orderByDesc('p.fecha_creacion');

        foreach (['estado' => 'p.estado', 'tipo' => 'p.tipo', 'modalidad' => 'e.modalidad'] as $filtro => $columna) {
            if (! empty($filtros[$filtro])) {
                $consulta->where($columna, $filtros[$filtro]);
            }
        }
        if (! empty($filtros['q'])) {
            $patron = '%'.addcslashes($filtros['q'], '%_\\').'%';
            $consulta->where(fn ($q) => $q->whereRaw('p.codigo ILIKE ?', [$patron])
                ->orWhereRaw("(u.nombres || ' ' || u.apellidos) ILIKE ?", [$patron]));
        }
        if (! empty($filtros['desde'])) {
            $consulta->whereDate('p.fecha_creacion', '>=', $filtros['desde']);
        }
        if (! empty($filtros['hasta'])) {
            $consulta->whereDate('p.fecha_creacion', '<=', $filtros['hasta']);
        }

        return $consulta->paginate(15, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));
    }

    /** Entregas (recojo o delivery) con su pedido y cliente; primero las que siguen en curso. */
    public function listarEntregas(array $filtros): LengthAwarePaginator
    {
        $consulta = DB::table('entrega as e')
            ->join('pedido as p', 'p.id', '=', 'e.pedido_id')
            ->join('usuario as u', 'u.id', '=', 'p.usuario_id')
            ->select(['e.id', 'e.modalidad', 'e.estado', 'e.direccion', 'e.referencia', 'e.nombre_receptor', 'e.telefono_contacto', 'e.fecha_entrega',
                'p.id as pedido_id', 'p.codigo', 'p.estado as estado_pedido', 'p.fecha_creacion', 'p.costo_envio', 'p.observacion',
                DB::raw("u.nombres || ' ' || u.apellidos AS cliente")])
            ->orderByRaw("e.estado IN ('PENDIENTE', 'LISTA_PARA_RECOJO', 'EN_CAMINO') DESC")
            ->orderByDesc('p.fecha_creacion');

        foreach (['estado' => 'e.estado', 'modalidad' => 'e.modalidad'] as $filtro => $columna) {
            if (! empty($filtros[$filtro])) {
                $consulta->where($columna, $filtros[$filtro]);
            }
        }
        if (! empty($filtros['q'])) {
            $patron = '%'.addcslashes($filtros['q'], '%_\\').'%';
            $consulta->where(fn ($q) => $q->whereRaw('p.codigo ILIKE ?', [$patron])
                ->orWhereRaw('e.direccion ILIKE ?', [$patron])
                ->orWhereRaw('e.nombre_receptor ILIKE ?', [$patron])
                ->orWhereRaw("(u.nombres || ' ' || u.apellidos) ILIKE ?", [$patron]));
        }
        if (! empty($filtros['desde'])) {
            $consulta->whereDate('p.fecha_creacion', '>=', $filtros['desde']);
        }
        if (! empty($filtros['hasta'])) {
            $consulta->whereDate('p.fecha_creacion', '<=', $filtros['hasta']);
        }

        return $consulta->paginate(15, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));
    }

    public function cambiarEstadoEntrega(int $pedidoId, string $estado): void
    {
        DB::table('entrega')->where('pedido_id', $pedidoId)->update([
            'estado' => $estado,
            'fecha_entrega' => $estado === 'ENTREGADA' ? now() : null,
        ]);
    }

    /** Entrega del pedido con su id, para las transiciones que hace el personal. */
    public function entregaDeGestion(int $pedidoId): ?object
    {
        return DB::table('entrega')->where('pedido_id', $pedidoId)->first(['id', 'modalidad', 'estado']);
    }

    /** Cambia el estado de la entrega solo si sigue en el esperado (transición condicional). */
    public function transicionEntrega(int $entregaId, string $desde, string $hacia): bool
    {
        return DB::table('entrega')->where('id', $entregaId)->where('estado', $desde)->update([
            'estado' => $hacia,
            'fecha_entrega' => $hacia === 'ENTREGADA' ? now() : null,
        ]) === 1;
    }

    public function lineas(int $pedidoId): Collection
    {
        return DB::table('detalle_pedido')->where('pedido_id', $pedidoId)->orderBy('id')
            ->get(['id', 'producto_id', 'cantidad', 'precio_unitario', 'subtotal', 'descripcion_venta']);
    }

    public function entrega(int $pedidoId): ?object
    {
        return DB::table('entrega')->where('pedido_id', $pedidoId)
            ->first(['modalidad', 'estado', 'direccion', 'referencia', 'nombre_receptor', 'telefono_contacto', 'fecha_programada', 'fecha_entrega']);
    }

    public function historial(int $pedidoId): Collection
    {
        return DB::table('historial_pedido')->where('pedido_id', $pedidoId)->orderBy('fecha')->orderBy('id')
            ->get(['estado_anterior', 'estado_nuevo', 'fecha', 'comentario']);
    }

    public function factura(int $pedidoId): ?object
    {
        return DB::table('factura')->where('pedido_id', $pedidoId)->first(['id', 'numero', 'fecha_emision', 'fecha_anulacion', 'nit_ci', 'razon_social', 'total', 'estado']);
    }

    public function facturaParaAnular(int $facturaId): ?object
    {
        return DB::table('factura')->where('id', $facturaId)->lockForUpdate()->first();
    }

    public function anularFactura(int $facturaId, string $fecha): void
    {
        DB::table('factura')->where('id', $facturaId)->where('estado', 'VIGENTE')
            ->update(['estado' => 'ANULADA', 'fecha_anulacion' => $fecha]);
    }

    public function facturasDelCliente(int $clienteId): Collection
    {
        return DB::table('factura as f')->join('pedido as p', 'p.id', '=', 'f.pedido_id')
            ->where('p.usuario_id', $clienteId)->orderByDesc('f.fecha_emision')
            ->get(['f.id', 'f.numero', 'f.fecha_emision', 'f.total', 'f.estado', 'f.nit_ci', 'f.razon_social', 'p.id as pedido_id', 'p.codigo as pedido']);
    }

    public function facturaDelCliente(int $facturaId, int $clienteId): ?object
    {
        return DB::table('factura as f')->join('pedido as p', 'p.id', '=', 'f.pedido_id')
            ->where('f.id', $facturaId)->where('p.usuario_id', $clienteId)
            ->first(['f.*', 'p.codigo as pedido', 'p.subtotal', 'p.costo_envio']);
    }

    /**
     * Emite la factura del pedido y deja su registro en la bitácora (CREAR · FACTURA).
     *
     * El número se arma con el id de la FACTURA, tomado antes de su secuencia, igual que el Proyecto 1
     * (ServicioFacturas.crear): así los dos sistemas no generan el mismo número sobre la misma base. Si ese número
     * ya existiera (facturas antiguas numeradas con el id del pedido), se toma el siguiente id libre.
     *
     * @param  int|null  $actorId  quién provocó la emisión (quien pagó o quien confirmó el cobro); null si no se conoce
     * @return object{id:int, numero:string}
     */
    public function emitirFactura(object $pedido, object $cliente, ?int $actorId = null, ?string $ip = null): object
    {
        $intentos = 0;
        do {
            $id = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('factura', 'id')) AS id")->id;
            $numero = sprintf('FAC-%s-%06d', now()->format('Y'), $id);
        } while (++$intentos < 50 && DB::table('factura')->where('numero', $numero)->exists());

        DB::table('factura')->insert([
            'id' => $id,
            'pedido_id' => $pedido->id,
            'numero' => $numero,
            'fecha_emision' => now(),
            'nit_ci' => trim($cliente->ci.' '.($cliente->ci_complemento ?? '')),
            'razon_social' => mb_substr($cliente->nombres.' '.$cliente->apellidos, 0, 150),
            'total' => $pedido->total,
            'codigo_autorizacion' => strtoupper(substr(hash('sha256', 'FEDER-'.$pedido->codigo), 0, 16)),
            'estado' => 'VIGENTE',
        ]);

        app(BitacoraRepository::class)->registrar($actorId, 'CREAR', 'FACTURA', $id, [
            'canal' => 'WEB', 'operacion' => 'EMITIR_FACTURA', 'numero' => $numero,
            'pedido_id' => (int) $pedido->id, 'pedido' => $pedido->codigo, 'total' => (float) $pedido->total,
        ], $ip);

        return (object) ['id' => $id, 'numero' => $numero];
    }
}
