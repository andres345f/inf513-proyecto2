<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Notificaciones del canal SISTEMA (se muestran dentro de la web).
 */
class NotificacionRepository
{
    public function crearParaPedido(int $usuarioId, int $pedidoId, string $tipo, string $titulo, string $mensaje): void
    {
        $this->crear($usuarioId, $tipo, $titulo, $mensaje, $pedidoId);
    }

    public function crear(int $usuarioId, string $tipo, string $titulo, string $mensaje, ?int $pedidoId = null): void
    {
        DB::table('notificacion')->insert([
            'usuario_id' => $usuarioId,
            'pedido_id' => $pedidoId,
            'tipo' => $tipo,
            'canal' => 'SISTEMA',
            'titulo' => mb_substr($titulo, 0, 120),
            'mensaje' => $mensaje,
            'estado' => 'ENVIADA',
            'fecha_envio' => now(),
        ]);
    }

    /** La fila pendiente se confirma junto al encargo. Su publicación ocurre después del commit. */
    public function prepararAvisoDeAbastecimiento(object $pedido): void
    {
        DB::table('notificacion')->insert([
            'usuario_id' => $pedido->usuario_id, 'pedido_id' => $pedido->id,
            'tipo' => 'ENCARGO_ABASTECIDO', 'canal' => 'SISTEMA',
            'titulo' => "Tu encargo {$pedido->codigo} llegó",
            'mensaje' => 'Ya tenemos tus productos y empezamos a preparar el pedido.',
            'estado' => 'PENDIENTE',
        ]);
    }

    public function publicarAvisosDeAbastecimiento(): void
    {
        DB::table('notificacion')->where('tipo', 'ENCARGO_ABASTECIDO')->where('canal', 'SISTEMA')
            ->where('estado', 'PENDIENTE')->update(['estado' => 'ENVIADA', 'fecha_envio' => now()]);
    }

    public function listar(int $usuarioId, bool $soloNoLeidas, int $pagina): LengthAwarePaginator
    {
        return DB::table('notificacion as n')
            ->leftJoin('pedido as p', 'p.id', '=', 'n.pedido_id')
            ->where('n.usuario_id', $usuarioId)
            ->where('n.canal', 'SISTEMA')->where('n.estado', 'ENVIADA')
            ->when($soloNoLeidas, fn ($q) => $q->whereNull('n.fecha_lectura'))
            ->orderByDesc('n.fecha_creacion')
            ->orderByDesc('n.id')
            ->select(['n.id', 'n.tipo', 'n.titulo', 'n.mensaje', 'n.fecha_creacion', 'n.fecha_lectura', 'n.pedido_id', 'p.codigo as pedido'])
            ->paginate(15, ['*'], 'pagina', $pagina);
    }

    public function contarNoLeidas(int $usuarioId): int
    {
        return DB::table('notificacion')->where('usuario_id', $usuarioId)->where('canal', 'SISTEMA')->where('estado', 'ENVIADA')->whereNull('fecha_lectura')->count();
    }

    public function marcarLeida(int $usuarioId, int $id): int
    {
        return DB::table('notificacion')->where('id', $id)->where('usuario_id', $usuarioId)->where('estado', 'ENVIADA')->whereNull('fecha_lectura')
            ->update(['fecha_lectura' => now()]);
    }

    public function marcarTodas(int $usuarioId): int
    {
        return DB::table('notificacion')->where('usuario_id', $usuarioId)->where('canal', 'SISTEMA')->where('estado', 'ENVIADA')->whereNull('fecha_lectura')
            ->update(['fecha_lectura' => now()]);
    }
}
