<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Usuario;
use App\Repositories\NotificacionRepository;
use App\Repositories\PagoRepository;
use App\Repositories\PedidoRepository;
use App\Support\SeparacionFunciones;
use Illuminate\Support\Facades\DB;

/**
 * Pagos vistos por el Personal de FEDER (CU6): consulta de todos los cobros y resolución
 * manual de los pendientes. Cada resolución queda en la bitácora.
 */
class GestionPagoService
{
    public function __construct(
        private readonly PagoRepository $pagos,
        private readonly PedidoRepository $pedidos,
        private readonly PagoService $pagoService,
        private readonly NotificacionRepository $notificaciones,
        private readonly AuditoriaService $auditoria,
    ) {
    }

    public function listar(array $filtros): array
    {
        $pagina = $this->pagos->listarGestion($filtros);

        return [
            'pagos' => collect($pagina->items())->map(fn ($p) => [
                'id' => (int) $p->id,
                'pedido' => ['id' => (int) $p->pedido_id, 'codigo' => $p->pedido, 'estado' => $p->estado_pedido],
                'cliente' => $p->cliente,
                'metodo' => $p->metodo,
                'monto' => (float) $p->monto,
                'estado' => $p->estado,
                'referencia' => $p->referencia_externa,
                'fecha' => $p->fecha_registro,
                'fecha_pago' => $p->fecha_pago,
                'acciones' => [
                    'confirmar' => $p->estado === 'PENDIENTE',
                    'rechazar' => $p->estado === 'PENDIENTE' && $p->metodo !== 'EFECTIVO',
                ],
            ])->all(),
            'paginacion' => ['pagina' => $pagina->currentPage(), 'paginas' => $pagina->lastPage(), 'total' => $pagina->total()],
        ];
    }

    /**
     * Confirma un pago pendiente. La confirmación (con su bitácora) la hace PagoService::confirmarPendiente, que
     * bloquea pedido → plan → pago e impide que alguien confirme el pago de su propio pedido.
     */
    public function confirmar(Usuario $personal, int $pagoId, ?string $ip): array
    {
        $pago = DB::transaction(fn () => $this->pagoService->confirmarPendiente($personal, $pagoId, $ip));

        return [
            'mensaje' => $pago->cobro_tardio
                ? 'Pago registrado. El pedido ya estaba cancelado: no se reactiva y el importe se reintegró al cliente.'
                : 'Pago confirmado.',
            'estado' => $pago->estado,
            'cobro_tardio' => (bool) $pago->cobro_tardio,
        ];
    }

    /**
     * Se rechaza un cobro por QR o tarjeta cuando FEDER verificó que no se recibió. El efectivo se resuelve al entregar el pedido
     * (se da por cobrado) o cancelando el pedido.
     * Bloquea en el orden único (pedido → pago) y aplica la separación de funciones del Proyecto 1.
     */
    public function rechazar(Usuario $personal, int $pagoId, string $motivo, ?string $ip): array
    {
        DB::transaction(function () use ($personal, $pagoId, $motivo, $ip) {
            $previa = $this->pagos->buscar($pagoId) ?? throw new NegocioException('Ese pago no existe.', 404);
            $pedido = $this->pedidos->buscar((int) $previa->pedido_id, bloquear: true);
            $pago = $this->pagos->buscar($pagoId, bloquear: true);

            SeparacionFunciones::exigirAjeno($personal, $pedido, 'rechazar el pago');
            if ($pago->estado !== 'PENDIENTE') {
                throw new NegocioException('Este pago ya no está pendiente.');
            }
            if ($pago->metodo === 'EFECTIVO') {
                throw new NegocioException('El efectivo se da por cobrado al entregar el pedido. Si el cliente no pagó, cancela el pedido.');
            }
            if (! $this->pagos->resolver($pago->id, 'RECHAZADO')) {
                throw new NegocioException('El pago cambió de estado mientras lo rechazabas. Recarga la página.', 409);
            }

            $cancelado = $pedido->estado === 'CANCELADO';
            $this->notificaciones->crearParaPedido((int) $pedido->usuario_id, $pedido->id, 'PAGO_RECHAZADO',
                "Pago no recibido · {$pedido->codigo}", "No pudimos verificar tu pago: {$motivo}."
                    .($cancelado ? '' : ' Puedes intentarlo de nuevo desde Mis pedidos.'));
            $this->auditoria->operacion($personal->id, 'MODIFICAR', 'PAGO', (int) $pago->id, [
                'operacion' => 'RECHAZAR_PAGO', 'motivo' => $motivo, 'pedido_id' => (int) $pedido->id,
                'estado_anterior' => 'PENDIENTE', 'estado_nuevo' => 'RECHAZADO', 'metodo' => $pago->metodo,
            ], $ip);
        });

        return ['mensaje' => 'Pago marcado como no recibido.', 'estado' => 'RECHAZADO'];
    }
}
