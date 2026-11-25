<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\ConfirmacionOperacion;
use App\Models\Usuario;
use App\Repositories\InventarioRepository;
use App\Repositories\NotificacionRepository;
use App\Repositories\PagoRepository;
use App\Repositories\PedidoRepository;
use App\Repositories\UsuarioRepository;
use App\Support\AtribucionImporte;
use App\Support\Cantidad;
use App\Support\SeparacionFunciones;
use App\Support\TarjetaSimulada;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pagos del cliente (CU6): QR (simulado hasta habilitar PagoFácil), tarjeta simulada y efectivo contra entrega.
 * Sirve tanto para el pago único como para cada cuota de un plan.
 *
 * Reglas que sostiene este servicio:
 * - Orden de bloqueo único en todos los caminos: PEDIDO → plan de cuotas → PAGO. Como todos empiezan por la fila del
 *   pedido, dos operaciones sobre el mismo pedido nunca se esperan una a la otra en sentidos opuestos.
 * - Cada intento tiene una clave de idempotencia estable (pago.clave_idempotencia): repetir el mismo intento devuelve
 *   el resultado ya registrado y no cobra otra vez.
 * - Un pago PENDIENTE que todavía puede cobrarse no se rechaza automáticamente: solo termina RECHAZADO por una decisión
 *   explícita (del personal con su motivo o del propio cliente que declara que no lo pagó), y queda en la bitácora.
 * - Con plan de cuotas, el pedido se confirma al pagar la PRIMERA cuota (decisión del proyecto 2), pero solo con un plan
 *   coherente con el pedido y con un pago realmente PAGADO de esa cuota.
 */
class PagoService
{
    private const MINUTOS_QR = 15;

    public function __construct(
        private readonly PedidoRepository $pedidos,
        private readonly PagoRepository $pagos,
        private readonly InventarioRepository $inventario,
        private readonly NotificacionRepository $notificaciones,
        private readonly PlanPagoService $planes,
        private readonly CobroPedidoService $cobros,
        private readonly MetodoPagoService $metodos,
        private readonly PedidoService $pedidoService,
        private readonly UsuarioRepository $usuarios,
        private readonly AuditoriaService $auditoria,
    ) {
    }

    /**
     * Registra un intento de pago del pedido o de la siguiente cuota de su plan.
     *
     * Campos de la petición además del medio de pago:
     * - clave_operacion (UUID): identifica el intento. Con la misma clave se devuelve el resultado ya registrado.
     * - cuota_numero: obligatorio si el pedido tiene plan; debe ser la siguiente cuota pendiente (si no, 409).
     * - anular_qr_pendiente: el cliente declara que no pagó su QR pendiente y quiere usar otro medio.
     *
     * @return array{resultado:string, mensaje:string, pago:array, qr:?array, repetido:bool, pedido:array}
     */
    public function pagar(Usuario $cliente, int $pedidoId, array $datos, ?string $ip = null): array
    {
        $resultado = DB::transaction(function () use ($cliente, $pedidoId, $datos, $ip) {
            $pedido = $this->pedidos->buscarDelCliente($pedidoId, $cliente->id, bloquear: true)
                ?? throw new NegocioException('Ese pedido no existe.', 404);
            $plan = $this->planes->delPedido($pedido->id, bloquear: true);
            $metodo = $datos['metodo'];

            // Reintento del mismo intento: se responde con lo ya registrado, sin tocar nada más.
            $claveCliente = $this->claveDelCliente($pedido, $datos['clave_operacion'] ?? null);
            if ($claveCliente !== null && ($previo = $this->pagos->buscarPorClave($claveCliente)) !== null) {
                return $this->resultadoRepetido($pedido, $plan, $previo, $metodo, isset($datos['cuota_numero']) ? (int) $datos['cuota_numero'] : null);
            }

            $numero = isset($datos['cuota_numero']) ? (int) $datos['cuota_numero'] : null;
            if ($plan !== null) {
                $this->exigirPlanCoherente($plan, $pedido);
                if ($numero !== null && $this->planes->siguienteCuota($plan) === null) {
                    // La cuota pedida ya no es la siguiente pendiente porque no queda ninguna (p. ej., el mismo envío de
                    // la última cuota llegó dos veces): es un conflicto con el estado del plan, como cualquier otra
                    // cuota que no corresponde (contrato H-06), y no se cobra nada.
                    throw new NegocioException('Este plan ya no tiene cuotas pendientes: no se cobró nada. Actualiza la página.', 409);
                }
            }
            $this->pedidoService->exigirPlazoDePagoVigente($pedido);
            if (! $this->pedidoService->puedePagar($pedido, $plan)) {
                throw new NegocioException('Este pedido no tiene pagos pendientes.');
            }

            $cuota = null;
            if ($plan !== null) {
                if ($metodo === 'EFECTIVO') {
                    throw new NegocioException('Las cuotas se pagan con QR o tarjeta.');
                }
                $cuota = $this->planes->exigirSiguienteCuota($plan, $numero);
                $monto = (float) $cuota['monto'];
            } else {
                if ($numero !== null) {
                    throw new NegocioException('Este pedido no se paga en cuotas.', 409);
                }
                $monto = round((float) $pedido->total - $this->pagos->totalPagado($pedido->id), 2);
            }
            if ($monto <= 0) {
                throw new NegocioException('Este pedido no tiene importe por cobrar.');
            }

            $qrPendiente = $this->revisarPagoPendiente($pedido, $metodo, $monto, ! empty($datos['anular_qr_pendiente']));
            if ($qrPendiente !== null && $metodo === 'QR') {
                return $this->respuestaQr($pedido, $qrPendiente, repetido: true);
            }

            $clave = $claveCliente ?? $this->claveGenerada($pedido);

            return match ($metodo) {
                'TARJETA' => $this->pagarConTarjeta($cliente, $pedido, $plan, $cuota, $monto, $datos, $clave, $qrPendiente, $ip),
                'QR' => $this->generarQr($cliente, $pedido, $cuota, $monto, $clave, $ip),
                'EFECTIVO' => $this->pagarEnEfectivo($cliente, $pedido, $monto, $clave, $qrPendiente, $ip),
            };
        });

        return $resultado + ['pedido' => $this->pedidoService->detalle($cliente->id, $pedidoId)];
    }

    /** Estado de un pago (la pantalla del QR lo consulta cada pocos segundos). */
    public function estado(Usuario $cliente, int $pagoId): array
    {
        $pago = $this->pagoDelCliente($cliente, $pagoId);

        return $this->formatearPago($pago);
    }

    /**
     * Confirma un QR simulado (en producción lo haría el aviso de PagoFácil).
     */
    public function confirmarQrSimulado(Usuario $cliente, int $pagoId, ?string $ip = null): array
    {
        if (! config('feder.qr_simulado')) {
            throw new NegocioException('La confirmación simulada de QR no está habilitada.', 403);
        }

        [$pedidoId, $tardio] = DB::transaction(function () use ($cliente, $pagoId, $ip) {
            [$pedido, $plan, $pago] = $this->bloquearParaResolver($this->pagoDelCliente($cliente, $pagoId));
            if ($pago->metodo !== 'QR' || $pago->estado !== 'PENDIENTE') {
                throw new NegocioException('Este QR ya no está pendiente de pago.');
            }

            return [$pedido->id, $this->confirmarCobro($cliente, $pedido, $plan, $pago, $cliente, 'QR_SIMULADO', $ip)];
        });

        return [
            'resultado' => 'PAGADO',
            'mensaje' => $tardio
                ? 'Recibimos tu pago por QR, pero el pedido ya estaba cancelado: te devolvimos el importe.'
                : 'Recibimos tu pago por QR.',
            'pedido' => $this->pedidoService->detalle($cliente->id, $pedidoId),
        ];
    }

    /**
     * El personal confirma un pago pendiente (QR verificado a mano o efectivo cobrado antes de la entrega).
     * Debe llamarse dentro de una transacción; devuelve el pago ya resuelto, con «cobro_tardio» si el pedido estaba
     * cancelado y el importe se reintegró.
     */
    public function confirmarPendiente(Usuario $personal, int $pagoId, ?string $ip = null): object
    {
        $previa = $this->pagos->buscar($pagoId) ?? throw new NegocioException('Ese pago no existe.', 404);
        [$pedido, $plan, $pago] = $this->bloquearParaResolver($previa);

        // Separación de funciones del Proyecto 1: nadie verifica el pago de su propio pedido.
        SeparacionFunciones::exigirAjeno($personal, $pedido, 'confirmar el pago');
        if ($pago->estado !== 'PENDIENTE') {
            throw new NegocioException('Este pago ya no está pendiente.');
        }

        $cliente = $this->usuarios->buscarPorId((int) $pedido->usuario_id);
        $tardio = $this->confirmarCobro($cliente, $pedido, $plan, $pago, $personal, 'MANUAL', $ip);

        $resuelto = $this->pagos->buscar($pago->id);
        $resuelto->cobro_tardio = $tardio;

        return $resuelto;
    }

    public function historial(int $clienteId): array
    {
        return $this->pagos->delCliente($clienteId)->map(fn ($p) => [
            'id' => (int) $p->id,
            'pedido' => ['id' => (int) $p->pedido_id, 'codigo' => $p->pedido],
            'metodo' => $p->metodo,
            'monto' => (float) $p->monto,
            'estado' => $p->estado,
            'fecha' => $p->fecha_pago ?? $p->fecha_registro,
            'referencia' => $p->referencia_externa,
        ])->all();
    }

    // ---- Registro del intento ------------------------------------------------------------------------------------

    private function pagarConTarjeta(Usuario $cliente, object $pedido, ?ConfirmacionOperacion $plan, ?array $cuota, float $monto,
        array $datos, string $clave, ?object $qrPendiente, ?string $ip): array
    {
        if (! empty($datos['metodo_pago_id'])) {
            $guardada = $this->metodos->buscar($cliente->id, (int) $datos['metodo_pago_id'])->parametros;
            if (TarjetaSimulada::vencida((int) $guardada['expira_mes'], (int) $guardada['expira_anio'])) {
                throw new NegocioException('Esa tarjeta está vencida. Elige otra o agrega una nueva.');
            }
            $simulacion = $guardada['simulacion'];
        } else {
            $tarjeta = $datos['tarjeta'];
            $this->metodos->validarTarjetaNueva($tarjeta);
            $simulacion = TarjetaSimulada::comportamiento($tarjeta['numero']);
            if (! empty($datos['guardar_tarjeta'])) {
                $this->metodos->registrar($cliente, $tarjeta);
            }
        }

        $aprobada = $simulacion === TarjetaSimulada::APROBADA;
        $pago = $this->pagos->crear($pedido->id, 'TARJETA', Cantidad::monto($monto), $aprobada ? 'PAGADO' : 'RECHAZADO', $this->referencia('TRJ'), $clave);
        $this->registrarIntento($cliente, $pedido, $pago, $cuota, $ip);

        if (! $aprobada) {
            // La tarjeta falló: un QR pendiente sigue vivo, todavía puede cobrarse.
            $this->notificaciones->crearParaPedido($cliente->id, $pedido->id, 'PAGO_RECHAZADO',
                "Pago rechazado · {$pedido->codigo}", 'Tu tarjeta fue rechazada por fondos insuficientes. Intenta con otro medio de pago.');

            return ['resultado' => 'RECHAZADO', 'mensaje' => 'La tarjeta fue rechazada por fondos insuficientes. Prueba con otro medio de pago.',
                'pago' => $this->formatearPago($pago), 'qr' => null, 'repetido' => false];
        }

        if ($qrPendiente !== null) {
            $this->anularQrPendiente($cliente, $pedido, $qrPendiente, 'El cliente declaró que no pagó el QR y pagó con tarjeta', $ip);
        }
        $this->alConfirmarPago($cliente, $pedido, $plan, $cuota, (int) $pago->id, null, $ip);

        return ['resultado' => 'PAGADO', 'mensaje' => 'Pago aprobado. ¡Gracias por tu compra!', 'pago' => $this->formatearPago($pago),
            'qr' => null, 'repetido' => false];
    }

    private function generarQr(Usuario $cliente, object $pedido, ?array $cuota, float $monto, string $clave, ?string $ip): array
    {
        $pago = $this->pagos->crear($pedido->id, 'QR', Cantidad::monto($monto), 'PENDIENTE', $this->referencia('QR'), $clave);
        $this->registrarIntento($cliente, $pedido, $pago, $cuota, $ip);

        return $this->respuestaQr($pedido, $pago, repetido: false);
    }

    /** Efectivo contra entrega: el pedido se confirma y el cobro lo registra quien entrega. */
    private function pagarEnEfectivo(Usuario $cliente, object $pedido, float $monto, string $clave, ?object $qrPendiente, ?string $ip): array
    {
        if ($qrPendiente !== null) {
            $this->anularQrPendiente($cliente, $pedido, $qrPendiente, 'El cliente declaró que no pagó el QR y eligió efectivo contra entrega', $ip);
        }
        $pago = $this->pagos->crear($pedido->id, 'EFECTIVO', Cantidad::monto($monto), 'PENDIENTE', $this->referencia('EFE'), $clave);
        $this->registrarIntento($cliente, $pedido, $pago, null, $ip);
        $this->confirmarPedido($cliente, $pedido, 'Pago en efectivo contra entrega');

        return ['resultado' => 'PENDIENTE', 'mensaje' => 'Listo: pagarás en efectivo al recibir o recoger tu pedido.',
            'pago' => $this->formatearPago($pago), 'qr' => null, 'repetido' => false];
    }

    /**
     * Un pedido admite un solo pago PENDIENTE (índice único del esquema) y un pendiente no se descarta por su cuenta.
     * Devuelve el QR pendiente cuando hay que reutilizarlo (mismo medio) o anularlo por declaración del cliente.
     */
    private function revisarPagoPendiente(object $pedido, string $metodo, float $monto, bool $anularQr): ?object
    {
        $pendiente = $this->pagos->pendienteDelPedido($pedido->id);
        if ($pendiente === null) {
            return null;
        }
        if ($pendiente->metodo === 'EFECTIVO') {
            throw new NegocioException('Ya elegiste pagar en efectivo contra entrega para este pedido.');
        }

        if ($pendiente->metodo !== 'QR') {
            throw new NegocioException('Hay un pago por tarjeta pendiente. FEDER debe verificarlo antes de que elijas otro medio.', 409);
        }
        if ($metodo === 'QR') {
            if (AtribucionImporte::centavos($pendiente->monto) !== AtribucionImporte::centavos($monto)) {
                throw new NegocioException('Este pedido tiene un QR pendiente por otro importe (Bs '
                    .number_format((float) $pendiente->monto, 2, ',', '.').'). FEDER debe verificarlo antes de generar otro.', 409);
            }

            return $pendiente;
        }
        if (! $anularQr) {
            $mensaje = "Este pedido tiene un pago por QR pendiente de verificación ({$pendiente->referencia_externa}). "
                .'Si ya lo pagaste, espera a que FEDER lo confirme. Si no lo pagaste y quieres usar otro medio, confirma que anulas ese QR.';
            throw new NegocioException($mensaje, 409, ['anular_qr_pendiente' => [$mensaje]]);
        }

        return $pendiente;
    }

    /** El cliente declara que no pagó su QR: es una decisión explícita, con motivo y rastro en la bitácora. */
    private function anularQrPendiente(Usuario $cliente, object $pedido, object $qr, string $motivo, ?string $ip): void
    {
        if (! $this->pagos->resolver($qr->id, 'RECHAZADO')) {
            throw new NegocioException('El pago por QR cambió mientras procesábamos tu pago. Vuelve a intentarlo.', 409);
        }
        $this->auditoria->operacion($cliente->id, 'MODIFICAR', 'PAGO', (int) $qr->id, [
            'operacion' => 'ANULAR_QR', 'estado_anterior' => 'PENDIENTE', 'estado_nuevo' => 'RECHAZADO',
            'motivo' => $motivo, 'pedido_id' => (int) $pedido->id, 'decidido_por' => 'CLIENTE',
        ], $ip);
    }

    private function registrarIntento(Usuario $cliente, object $pedido, object $pago, ?array $cuota, ?string $ip): void
    {
        $this->auditoria->operacion($cliente->id, 'CREAR', 'PAGO', (int) $pago->id, [
            'operacion' => 'REGISTRAR_PAGO', 'pedido_id' => (int) $pedido->id, 'pedido' => $pedido->codigo,
            'metodo' => $pago->metodo, 'monto' => (float) $pago->monto, 'estado' => $pago->estado,
            'cuota' => $cuota ? (int) $cuota['numero'] : null, 'referencia' => $pago->referencia_externa,
        ], $ip);
    }

    // ---- Confirmación del cobro ----------------------------------------------------------------------------------

    /**
     * Pasa a PAGADO un pago PENDIENTE ya bloqueado y aplica sus efectos. Devuelve true si fue un cobro tardío
     * (pedido cancelado): en ese caso el pedido no se reactiva y el importe se reintegra.
     */
    private function confirmarCobro(Usuario $cliente, object $pedido, ?ConfirmacionOperacion $plan, object $pago, Usuario $actor,
        string $verificacion, ?string $ip): bool
    {
        $tardio = $pedido->estado === 'CANCELADO';
        $cuota = null;

        if ($tardio) {
            if ($pago->metodo === 'EFECTIVO') {
                throw new NegocioException('El pedido está cancelado: su pago en efectivo ya no se cobra.');
            }
        } elseif ($plan !== null) {
            $this->exigirPlanCoherente($plan, $pedido);
            $cuota = $plan->parametros['estado'] === PlanPagoService::ACTIVO ? $this->planes->siguienteCuota($plan) : null;
            if ($cuota === null || AtribucionImporte::centavos($cuota['monto']) !== AtribucionImporte::centavos($pago->monto)) {
                throw new NegocioException('El importe de este pago (Bs '.number_format((float) $pago->monto, 2, ',', '.')
                    .') no coincide con la cuota pendiente del plan. El pago sigue pendiente para revisarlo.', 409);
            }
        } elseif (AtribucionImporte::centavos($this->pagos->totalPagado($pedido->id)) + AtribucionImporte::centavos($pago->monto)
            > AtribucionImporte::centavos($pedido->total)) {
            throw new NegocioException('Confirmar este pago superaría el total del pedido. El pago sigue pendiente para revisarlo.', 409);
        }

        if (! $this->pagos->resolver($pago->id, 'PAGADO')) {
            throw new NegocioException('El pago cambió de estado mientras lo confirmabas. Recarga la página.', 409);
        }
        $this->auditoria->operacion($actor->id, 'MODIFICAR', 'PAGO', (int) $pago->id, [
            'operacion' => 'CONFIRMAR_PAGO', 'metodo' => $pago->metodo, 'pedido_id' => (int) $pedido->id,
            'estado_anterior' => 'PENDIENTE', 'estado_nuevo' => 'PAGADO', 'verificacion' => $verificacion,
            'monto' => (float) $pago->monto, 'cuota' => $cuota ? (int) $cuota['numero'] : null, 'cobro_tardio' => $tardio,
        ], $ip);

        if ($tardio) {
            $this->reintegrarCobroTardio($cliente, $pedido, $pago, $actor, $ip);
        } else {
            $this->alConfirmarPago($cliente, $pedido, $plan, $cuota, (int) $pago->id, $actor, $ip);
        }

        return $tardio;
    }

    /**
     * Un cobro que llega cuando el pedido ya está cancelado nunca lo reactiva: se registra como cobrado y se devuelve
     * con un reembolso sin devolución (el esquema solo lo admite sobre un pedido CANCELADO). No se factura.
     */
    private function reintegrarCobroTardio(Usuario $cliente, object $pedido, object $pago, Usuario $actor, ?string $ip): void
    {
        $motivo = "Cobro recibido después de la cancelación del pedido {$pedido->codigo}";
        $reembolso = $this->pagos->crearReembolso((int) $pago->id, null, Cantidad::monto((float) $pago->monto), $motivo,
            hash('sha256', 'REEMBOLSO|COBRO_TARDIO|'.$pago->id));

        $this->auditoria->operacion($actor->id, 'CREAR', 'REEMBOLSO', (int) $reembolso->id, [
            'operacion' => 'REINTEGRO_COBRO_TARDIO', 'pago_id' => (int) $pago->id, 'devolucion_id' => null, 'pedido_id' => (int) $pedido->id,
            'monto' => (float) $pago->monto, 'estado' => 'EJECUTADO', 'referencia' => $reembolso->referencia, 'motivo' => $motivo,
        ], $ip);
        $this->notificaciones->crearParaPedido($cliente->id, $pedido->id, 'REEMBOLSO_EJECUTADO', "Reintegro · {$pedido->codigo}",
            'Recibimos tu pago de Bs '.number_format((float) $pago->monto, 2, ',', '.')
            .' cuando el pedido ya estaba cancelado. El pedido no se reactiva y te devolvimos ese importe.');
    }

    private function alConfirmarPago(Usuario $cliente, object $pedido, ?ConfirmacionOperacion $plan, ?array $cuota, int $pagoId,
        ?Usuario $actor, ?string $ip): void
    {
        // Nada se confirma sin un pago de este pedido que esté realmente PAGADO.
        $pago = $this->pagos->buscar($pagoId);
        if ($pago === null || $pago->estado !== 'PAGADO' || (int) $pago->pedido_id !== (int) $pedido->id) {
            throw new NegocioException('El pago no quedó registrado como cobrado: el pedido no se confirma.', 409);
        }

        $medio = strtolower($pago->metodo);
        if ($plan !== null) {
            if ($cuota === null) {
                throw new NegocioException('El plan de cuotas no tiene una cuota pendiente para este pago.', 409);
            }
            $parametros = $this->planes->registrarPagoDeCuota($plan, (int) $cuota['numero'], $pago);
            $comentario = "Cuota {$cuota['numero']} de {$parametros['num_cuotas']} pagada ({$medio})";
            $aviso = "Recibimos la cuota {$cuota['numero']} de {$parametros['num_cuotas']}.";
        } else {
            $comentario = "Pago confirmado ({$medio})";
            $aviso = 'Recibimos el pago de tu pedido.';
        }

        $actual = $this->pedidos->buscar($pedido->id);
        $completo = $this->cobros->pagadoPorCompleto($actual);

        if ($plan !== null || $completo) {
            $this->confirmarPedido($actor ?? $cliente, $pedido, $comentario);
        } elseif ($actual->estado === 'PENDIENTE' && $actual->tipo === 'INMEDIATO') {
            // Sin plan y sin cubrir el total, el pedido sigue pendiente; sus reservas dejan de vencer.
            $this->inventario->fijarReservas($actual->id);
        }

        $this->notificaciones->crearParaPedido($cliente->id, $pedido->id, 'PAGO_CONFIRMADO', "Pago recibido · {$pedido->codigo}", $aviso);

        // R030 exige cobrar el total original, incluso si una devolución saldó el plan.
        if ($completo && $this->pedidos->factura($pedido->id) === null && $this->cobros->puedeFacturar($actual, $plan)) {
            $this->pedidos->emitirFactura($actual, $cliente, ($actor ?? $cliente)->id, $ip);
        }
    }

    /** Primer pago (o efectivo): INMEDIATO pasa a CONFIRMADO y fija sus reservas; ENCARGO espera abastecimiento. */
    private function confirmarPedido(Usuario $actor, object $pedido, string $comentario): void
    {
        $actual = $this->pedidos->buscar($pedido->id);
        if ($actual->tipo === 'ENCARGO' && in_array($actual->estado, ['PENDIENTE', 'ESPERANDO_ABASTECIMIENTO'], true)) {
            AbastecimientoService::revisarTrasCommit();
        }
        if ($actual->estado !== 'PENDIENTE') {
            return;
        }

        $nuevo = $actual->tipo === 'INMEDIATO' ? 'CONFIRMADO' : 'ESPERANDO_ABASTECIMIENTO';
        if ($this->pedidos->cambiarEstado($actual->id, 'PENDIENTE', $nuevo)) {
            if ($actual->tipo === 'INMEDIATO') {
                $this->inventario->fijarReservas($actual->id);
            }
            $this->pedidos->registrarHistorial($actual->id, $actor->id, 'PENDIENTE', $nuevo, $comentario);
        }
    }

    /**
     * El plan es el del cliente y el del pedido (PlanPagoService::esCoherente) y cada cuota marcada como PAGADA tiene
     * detrás un pago PAGADO de este pedido por el mismo importe. Sin eso no se cobra ni se confirma nada.
     */
    private function exigirPlanCoherente(ConfirmacionOperacion $plan, object $pedido): void
    {
        $this->planes->exigirCoherente($plan, $pedido);

        if (! $this->cobros->cuotasCoincidenConPagos((int) $pedido->id, $plan->parametros['cuotas'])) {
            throw new NegocioException("Las cuotas pagadas del pedido {$pedido->codigo} no coinciden con sus pagos. "
                .'No se registró ningún cobro: comunícate con FEDER para revisarlo.', 409);
        }
    }

    // ---- Apoyos --------------------------------------------------------------------------------------------------

    /** Bloquea en el orden único (pedido → plan → pago) y devuelve las tres filas releídas. */
    private function bloquearParaResolver(object $pagoLeido): array
    {
        $pedido = $this->pedidos->buscar((int) $pagoLeido->pedido_id, bloquear: true);
        $plan = $this->planes->delPedido((int) $pedido->id, bloquear: true);
        $pago = $this->pagos->buscar((int) $pagoLeido->id, bloquear: true);

        return [$pedido, $plan, $pago];
    }

    /** Clave estable del intento cuando el cliente envía su clave_operacion (se acota al pedido). */
    private function claveDelCliente(object $pedido, ?string $claveOperacion): ?string
    {
        $claveOperacion = trim((string) $claveOperacion);

        return $claveOperacion === '' ? null : hash('sha256', 'PAGO_WEB|'.$pedido->id.'|'.mb_strtolower($claveOperacion));
    }

    /**
     * Clave de un intento sin clave del cliente: correlativo del pedido, con el formato del Proyecto 1
     * (PAG-<código del pedido>-NN). Es estable porque se calcula con el pedido bloqueado.
     */
    private function claveGenerada(object $pedido): string
    {
        $n = $this->pagos->contarDelPedido((int) $pedido->id);
        do {
            $n++;
            $clave = sprintf('PAG-%s-%02d', $pedido->codigo, $n);
        } while ($this->pagos->buscarPorClave($clave) !== null);

        return $clave;
    }

    /**
     * Respuesta de un reintento: el mismo intento (misma clave, mismo medio y misma cuota) devuelve lo ya registrado.
     * Si la clave se reutiliza para otra cosa, se rechaza: una clave identifica un solo intento.
     */
    private function resultadoRepetido(object $pedido, ?ConfirmacionOperacion $plan, object $pago, string $metodo, ?int $cuotaPedida): array
    {
        $cuotaOriginal = null;
        if ($plan !== null) {
            if ($cuotaPedida === null) {
                throw new NegocioException('Indica la cuota que corresponde a este intento de pago.');
            }
            $cuotaOriginal = $this->pagos->cuotaDelIntento((int) $pago->id);
            if ($cuotaOriginal === null) {
                // En un pago histórico sin registro de creación, el plan identifica una cuota ya pagada.
                $cuota = collect($plan->parametros['cuotas'] ?? [])->firstWhere('pago_id', $pago->id);
                $cuotaOriginal = isset($cuota['numero']) ? (int) $cuota['numero'] : null;
            }
        }

        if ((int) $pago->pedido_id !== (int) $pedido->id || $pago->metodo !== $metodo || $cuotaOriginal !== $cuotaPedida) {
            throw new NegocioException('Esa clave de operación ya identifica otro intento de pago. Usa una clave nueva para este intento.', 409);
        }
        if ($pago->estado === 'PENDIENTE' && $pago->metodo === 'QR') {
            return $this->respuestaQr($pedido, $pago, repetido: true);
        }

        $mensaje = match ($pago->estado) {
            'PAGADO' => 'Este pago ya estaba registrado y aprobado: no se cobró de nuevo.',
            'RECHAZADO' => 'Este intento ya terminó rechazado y no se cobró. Para volver a intentar, inicia un pago nuevo.',
            default => 'Este pago ya estaba registrado: pagarás en efectivo al recibir o recoger tu pedido.',
        };

        return ['resultado' => $pago->estado, 'mensaje' => $mensaje, 'pago' => $this->formatearPago($pago), 'qr' => null, 'repetido' => true];
    }

    private function respuestaQr(object $pedido, object $pago, bool $repetido): array
    {
        return [
            'resultado' => 'PENDIENTE',
            'mensaje' => $repetido
                ? 'Ya tienes un QR pendiente para este pago: es el mismo que generaste antes.'
                : 'Escanea el QR con la aplicación de tu banco.',
            'pago' => $this->formatearPago($pago),
            'qr' => [
                'contenido' => implode('|', ['FEDER', $pedido->codigo, Cantidad::monto((float) $pago->monto), $pago->referencia_externa]),
                'simulado' => (bool) config('feder.qr_simulado'),
                'vence_en' => Carbon::parse($pago->fecha_registro)->addMinutes(self::MINUTOS_QR)->toIso8601String(),
            ],
            'repetido' => $repetido,
        ];
    }

    private function pagoDelCliente(Usuario $cliente, int $pagoId, bool $bloquear = false): object
    {
        $pago = $this->pagos->buscar($pagoId, $bloquear);
        if ($pago === null || $this->pedidos->buscarDelCliente($pago->pedido_id, $cliente->id) === null) {
            throw new NegocioException('Ese pago no existe.', 404);
        }

        return $pago;
    }

    private function referencia(string $prefijo): string
    {
        return $prefijo.'-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
    }

    private function formatearPago(object $pago): array
    {
        return [
            'id' => (int) $pago->id,
            'metodo' => $pago->metodo,
            'monto' => (float) $pago->monto,
            'estado' => $pago->estado,
            'referencia' => $pago->referencia_externa,
            'fecha' => $pago->fecha_pago ?? $pago->fecha_registro,
        ];
    }
}
