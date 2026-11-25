<?php

namespace App\Services;

use App\Repositories\NotificacionRepository;

/**
 * Bandeja de notificaciones dentro de la web (canal SISTEMA) para cualquier usuario.
 */
class NotificacionService
{
    public function __construct(private readonly NotificacionRepository $notificaciones)
    {
    }

    public function listar(int $usuarioId, bool $soloNoLeidas, int $pagina): array
    {
        $resultado = $this->notificaciones->listar($usuarioId, $soloNoLeidas, $pagina);

        return [
            'notificaciones' => collect($resultado->items())->map(fn ($n) => [
                'id' => (int) $n->id,
                'tipo' => $n->tipo,
                'titulo' => $n->titulo,
                'mensaje' => $n->mensaje,
                'fecha' => $n->fecha_creacion,
                'leida' => $n->fecha_lectura !== null,
                'pedido_id' => $n->pedido_id ? (int) $n->pedido_id : null,
                'pedido' => $n->pedido,
            ])->all(),
            'no_leidas' => $this->notificaciones->contarNoLeidas($usuarioId),
            'paginacion' => ['pagina' => $resultado->currentPage(), 'paginas' => $resultado->lastPage(), 'total' => $resultado->total()],
        ];
    }

    public function contarNoLeidas(int $usuarioId): int
    {
        return $this->notificaciones->contarNoLeidas($usuarioId);
    }

    public function marcarLeida(int $usuarioId, int $id): int
    {
        $this->notificaciones->marcarLeida($usuarioId, $id);

        return $this->notificaciones->contarNoLeidas($usuarioId);
    }

    public function marcarTodas(int $usuarioId): int
    {
        $this->notificaciones->marcarTodas($usuarioId);

        return 0;
    }
}
