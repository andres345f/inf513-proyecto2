<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Repositories\BitacoraRepository;
use App\Repositories\InventarioRepository;
use App\Repositories\NotificacionRepository;
use App\Repositories\PagoRepository;
use App\Repositories\PedidoRepository;
use Illuminate\Support\Facades\DB;
use Throwable;

class AbastecimientoService
{
    public function __construct(
        private readonly PedidoRepository $pedidos,
        private readonly InventarioRepository $inventario,
        private readonly ReservaService $reservas,
        private readonly CobroPedidoService $cobros,
        private readonly PlanPagoService $planes,
        private readonly PagoRepository $pagos,
        private readonly BitacoraRepository $bitacora,
        private readonly NotificacionRepository $avisos,
    ) {}

    /** La operación que liberó stock o resolvió el pago ya es definitiva cuando comienza la revisión. */
    public static function revisarTrasCommit(): void
    {
        DB::afterCommit(static function () {
            try {
                app(self::class)->revisar();
            } catch (Throwable $e) {
                // El pedido en espera y los avisos pendientes siguen en PostgreSQL para la tarea periódica.
                report($e);
            }
        });
    }

    /** La prioridad se aplica también cuando el operador elige un encargo concreto. */
    public function manual(int $pedidoId, int $actorId, ?string $ip): void
    {
        $this->revisar($pedidoId);
        $this->procesar($pedidoId, $actorId, $ip);
    }

    public function revisar(?int $antesDe = null): int
    {
        $confirmados = 0;
        foreach ($this->inventario->encargosEnEspera($antesDe) as $id) {
            try {
                $confirmados += (int) $this->procesar($id, null, null);
            } catch (NegocioException $e) {
                // Por ejemplo, una línea sin stock: su transacción ya se revirtió. Continúa con el siguiente encargo.
                continue;
            }
        }
        $this->avisos->publicarAvisosDeAbastecimiento();

        return $confirmados;
    }

    private function procesar(int $id, ?int $actor, ?string $ip): bool
    {
        return DB::transaction(function () use ($id, $actor, $ip) {
            $pedido = $this->pedidos->buscar($id, bloquear: true);
            $espera = $pedido && $pedido->tipo === 'ENCARGO' && $pedido->estado === 'ESPERANDO_ABASTECIMIENTO';
            if (! $espera) {
                if ($actor === null) {
                    return false;
                }
                if (! $pedido) {
                    throw new NegocioException('Ese pedido no existe.', 404);
                }
                if (! in_array($pedido->estado, ['CONFIRMADO', 'EN_PREPARACION'], true)) {
                    throw new NegocioException('El pedido ya no está en un estado que permita reservar mercancía.', 409);
                }
            }

            if ($espera) {
                $plan = $this->planes->delPedido($id, bloquear: true);
                if (! $this->cobros->resuelto($pedido, $plan)) {
                    $pendiente = $this->pagos->pendienteDelPedido($id, false);
                    if ($pendiente && $pendiente->metodo !== 'EFECTIVO') {
                        throw new NegocioException('Hay un pago pendiente por conciliar. Revísalo en Gestión de pagos antes de abastecer.', 409);
                    }
                    throw new NegocioException('El encargo no tiene ningún pago ni acuerdo de efectivo válido para reservar stock. Revisa el pago o el acuerdo de efectivo contra entrega.');
                }
                if ($this->pagos->plazoDePagoVencido($id, PedidoService::HORAS_PARA_PAGAR)) {
                    throw new NegocioException('El plazo de pago del encargo ha vencido.');
                }
            }

            $liberadas = $this->inventario->liberarReservasNoUtilizables($id);
            $lineas = $this->inventario->coberturaDePedido($id);
            if ($lineas->isEmpty()) {
                throw new NegocioException('El pedido no tiene productos para abastecer.');
            }
            $pendientes = $lineas->filter(fn ($l) => $l->pendiente > 0);
            if (! $espera && $pendientes->isEmpty()) {
                return false; // Ya fue atendido por otra petición o por la tarea periódica.
            }

            $creadas = $this->reservas->reservarLineas($pendientes->map(fn ($l) => [
                'id' => $l->id, 'producto_id' => $l->producto_id, 'cantidad' => $l->pendiente, 'nombre' => $l->descripcion_venta,
            ])->values(), null);

            if ($espera) {
                if (! $this->pedidos->cambiarEstado($id, 'ESPERANDO_ABASTECIMIENTO', 'CONFIRMADO')) {
                    throw new NegocioException('El estado del encargo cambió durante el abastecimiento.', 409);
                }
                $this->pedidos->registrarHistorial($id, $actor, $pedido->estado, 'CONFIRMADO', 'Encargo abastecido');
                $this->avisos->prepararAvisoDeAbastecimiento($pedido);
                DB::afterCommit(function () {
                    try {
                        $this->avisos->publicarAvisosDeAbastecimiento();
                    } catch (Throwable $e) {
                        report($e);
                    }
                });
            }
            $this->bitacora->registrar($actor, 'MODIFICAR', 'PEDIDO', $id, [
                'canal' => 'WEB', 'origen' => $actor === null ? 'AUTOMATICO' : 'PERSONAL',
                'operacion' => 'ABASTECER', 'codigo' => $pedido->codigo,
                'estado_anterior' => $pedido->estado, 'estado_nuevo' => $espera ? 'CONFIRMADO' : $pedido->estado,
                'cantidad_reservada' => round(array_sum(array_column($creadas, 'cantidad')), 3),
                'reservas_creadas' => count($creadas), 'reservas_liberadas' => $liberadas,
            ], $ip);

            return true;
        }, 3);
    }
}
