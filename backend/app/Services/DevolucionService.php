<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\ConfirmacionOperacion;
use App\Models\Usuario;
use App\Repositories\DevolucionRepository;
use App\Repositories\InventarioRepository;
use App\Repositories\NotificacionRepository;
use App\Repositories\PagoRepository;
use App\Repositories\PedidoRepository;
use App\Support\AtribucionImporte;
use App\Support\Cantidad;
use App\Support\SeparacionFunciones;
use Illuminate\Support\Facades\DB;

/**
 * Devoluciones (CU6), con el mismo recorrido que el Proyecto 1:
 * el cliente solicita sobre un pedido ENTREGADO → FEDER recibe e inspecciona y aprueba
 * (REPOSICION o REEMBOLSO) o rechaza → al completar se entrega la reposición o se reintegra el dinero.
 *
 * Topes que cuida este servicio (la base no los impone porque son sumas de varias filas):
 * - por despacho: lo solicitado, aprobado o recibido físicamente nunca supera lo despachado;
 * - reingreso al stock: solo min(apta, aprobada), en el lote del despacho;
 * - por línea: los importes de todas sus devoluciones no superan su subtotal (el residuo del redondeo va a la última);
 * - por pedido y por pago: nunca se reintegra más de lo realmente cobrado.
 *
 * Orden de bloqueo en todas las operaciones: PEDIDO → plan de cuotas → DEVOLUCION → despacho → PAGOS → lote.
 * Las sumas se leen después de bloquear, en sentencias aparte.
 */
class DevolucionService
{
    public const ESTADOS = ['SOLICITADA', 'APROBADA', 'RECHAZADA', 'COMPLETADA', 'ANULADA'];

    private const TOLERANCIA = 0.0005;

    public function __construct(
        private readonly DevolucionRepository $devoluciones,
        private readonly InventarioRepository $inventario,
        private readonly PagoRepository $pagos,
        private readonly NotificacionRepository $notificaciones,
        private readonly AuditoriaService $auditoria,
        private readonly PedidoRepository $pedidos,
        private readonly PlanPagoService $planes,
    ) {
    }

    // ---- Cliente -------------------------------------------------------------------------

    public function resumenCliente(int $clienteId): array
    {
        return [
            'devoluciones' => $this->devoluciones->delCliente($clienteId)->map(fn ($d) => $this->formatear($d))->all(),
            'devolvibles' => $this->devoluciones->devolvibles($clienteId)->map(fn ($m) => [
                'despacho_id' => (int) $m->id,
                'pedido' => $m->pedido,
                'producto' => $m->producto,
                'maximo' => round((float) $m->despachado - (float) $m->devuelto, 3),
                'unidad' => $m->unidad_medida,
            ])->all(),
        ];
    }

    public function solicitar(Usuario $cliente, int $despachoId, float $cantidad, string $motivo, ?string $ip): array
    {
        $devolucion = DB::transaction(function () use ($cliente, $despachoId, $cantidad, $motivo, $ip) {
            // Lectura inicial sin bloqueo, solo para saber de qué pedido es el despacho.
            $previa = $this->devoluciones->despachoDelCliente($despachoId, $cliente->id)
                ?? throw new NegocioException('Ese producto no está entre tus compras.', 404);

            // Primero la fila común (el pedido) y después el despacho; recién entonces se lee lo ya devuelto.
            $this->pedidos->buscar((int) $previa->pedido_id, bloquear: true);
            $this->devoluciones->bloquearDespacho($despachoId);
            $despacho = $this->devoluciones->despachoDelCliente($despachoId, $cliente->id);

            if ($despacho->estado_pedido !== 'ENTREGADO') {
                throw new NegocioException('Solo puedes devolver productos de un pedido ya entregado.');
            }
            $this->exigirEnteras($despacho, [$cantidad]);
            $maximo = round((float) $despacho->despachado - (float) $despacho->devuelto, 3);
            if ($cantidad > $maximo + self::TOLERANCIA) {
                throw new NegocioException($maximo > 0
                    ? 'Puedes devolver como máximo '.self::legible($maximo).' de este producto.'
                    : 'Ya no quedan unidades de este producto por devolver.');
            }

            $devolucion = $this->devoluciones->crear($despachoId, Cantidad::texto($cantidad), $motivo);
            $this->auditoria->operacion($cliente->id, 'CREAR', 'DEVOLUCION', $devolucion->id,
                ['codigo' => $devolucion->codigo, 'cantidad' => $cantidad, 'despacho_id' => $despachoId], $ip);

            return $devolucion;
        });

        return ['mensaje' => "Recibimos tu solicitud {$devolucion->codigo}. Te avisaremos cuando la revisemos."] + $this->resumenCliente($cliente->id);
    }

    // ---- Personal de FEDER ---------------------------------------------------------------

    public function listar(array $filtros): array
    {
        $pagina = $this->devoluciones->listarGestion($filtros);

        return [
            'devoluciones' => collect($pagina->items())->map(fn ($d) => $this->formatear($d) + ['cliente' => $d->cliente])->all(),
            'paginacion' => ['pagina' => $pagina->currentPage(), 'paginas' => $pagina->lastPage(), 'total' => $pagina->total()],
        ];
    }

    /**
     * Recepción, inspección y decisión en un solo paso: cuántas unidades llegaron, cuántas sirven
     * para volver a venderse y cómo se compensa al cliente.
     */
    public function aprobar(Usuario $personal, int $id, array $datos, ?string $ip): array
    {
        DB::transaction(function () use ($personal, $id, $datos, $ip) {
            [$pedido, $plan, $d] = $this->bloquear($id, 'SOLICITADA', 'Solo se aprueba una devolución que está solicitada.');
            SeparacionFunciones::exigirAjeno($personal, $pedido, 'aprobar la devolución');

            $recibida = (float) $datos['cantidad_recibida'];
            $apta = (float) $datos['cantidad_apta'];
            $aprobada = (float) $datos['cantidad_aprobada'];
            $this->exigirEnteras($d, [$recibida, $apta, $aprobada]);

            if ($recibida > (float) $d->cantidad + self::TOLERANCIA) {
                throw new NegocioException('No puedes recibir más unidades de las que se pidió devolver.');
            }
            if ($apta > $recibida + self::TOLERANCIA) {
                throw new NegocioException('Las unidades aptas no pueden superar las recibidas.');
            }
            if ($aprobada > $recibida + self::TOLERANCIA) {
                throw new NegocioException('Solo se aprueban unidades que FEDER recibió.');
            }

            // Tope por despacho, releído con el pedido y el despacho ya bloqueados: entre todas las devoluciones no
            // puede volver (ni aprobarse) más de lo que salió.
            $ocupado = $this->devoluciones->ocupadoDelDespacho((int) $d->movimiento_inventario_id, (int) $d->id);
            $libre = round((float) $d->despachado - $ocupado, 3);
            if (max($recibida, $aprobada) > $libre + self::TOLERANCIA) {
                throw new NegocioException('De este despacho salieron '.self::legible((float) $d->despachado).' y otras devoluciones ya ocupan '
                    .self::legible($ocupado).': aquí solo puedes recibir o aprobar hasta '.self::legible(max($libre, 0)).'.');
            }

            $reembolso = $datos['resolucion'] === 'REEMBOLSO';
            [$importe, $descuento] = $reembolso ? $this->atribuir($d, $pedido, $plan, $aprobada) : [null, 0];

            $this->devoluciones->aprobar($d->id, [
                'estado' => 'APROBADA',
                'resolucion' => $datos['resolucion'],
                'cantidad_aprobada' => Cantidad::texto($aprobada),
                'cantidad_recibida' => Cantidad::texto($recibida),
                'cantidad_apta' => Cantidad::texto($apta),
                'importe_reembolsable' => $reembolso ? Cantidad::monto(AtribucionImporte::bolivianos($importe)) : null,
            ]) || throw new NegocioException('La devolución cambió mientras la revisabas. Recarga la página.', 409);

            $cuotas = [];
            if ($descuento > 0) {
                $cuotas = $this->planes->descontarPorDevolucion($plan, (int) $d->id, (int) $d->detalle_pedido_id, $descuento);
            }

            // Solo vuelven al stock las unidades aptas que además se aprobaron; el resto queda en custodia de FEDER.
            $reingreso = min($apta, $aprobada);
            if ($reingreso > 0) {
                $this->inventario->reingresarDevolucion((int) $d->lote_feder_id, Cantidad::texto($reingreso), $d->id, $personal->id, $d->codigo);
            }

            $compensacion = $reembolso
                ? 'te reintegraremos Bs '.number_format(AtribucionImporte::bolivianos($importe), 2, ',', '.')
                    .($descuento > 0 ? ' y descontamos Bs '.number_format(AtribucionImporte::bolivianos($descuento), 2, ',', '.').' de tus cuotas pendientes' : '')
                : 'te entregaremos '.self::legible($aprobada).' de reposición';
            $this->notificaciones->crearParaPedido((int) $d->cliente_id, (int) $d->pedido_id, 'DEVOLUCION_RESUELTA',
                "Devolución aprobada · {$d->codigo}", "Aprobamos tu devolución de {$d->producto}: {$compensacion}.");
            $this->auditoria->operacion($personal->id, 'MODIFICAR', 'DEVOLUCION', $d->id, [
                'operacion' => 'APROBAR', 'resolucion' => $datos['resolucion'], 'cantidad_aprobada' => $aprobada,
                'cantidad_recibida' => $recibida, 'cantidad_apta' => $apta, 'cantidad_reingresada' => $reingreso,
                'importe_reembolsable' => $reembolso ? AtribucionImporte::bolivianos($importe) : null,
                'descuento_en_cuotas' => $descuento > 0 ? AtribucionImporte::bolivianos($descuento) : null, 'cuotas' => $cuotas ?: null,
            ], $ip);
        });

        return ['mensaje' => 'Devolución aprobada.'];
    }

    public function rechazar(Usuario $personal, int $id, string $motivo, ?string $ip): array
    {
        DB::transaction(function () use ($personal, $id, $motivo, $ip) {
            [, , $d] = $this->bloquear($id, 'SOLICITADA', 'Solo se rechaza una devolución que está solicitada.');
            $this->devoluciones->rechazar($d->id)
                || throw new NegocioException('La devolución cambió mientras la revisabas. Recarga la página.', 409);

            $this->notificaciones->crearParaPedido((int) $d->cliente_id, (int) $d->pedido_id, 'DEVOLUCION_RESUELTA',
                "Devolución rechazada · {$d->codigo}", "No pudimos aceptar tu devolución de {$d->producto}: {$motivo}.");
            $this->auditoria->operacion($personal->id, 'MODIFICAR', 'DEVOLUCION', $d->id, ['operacion' => 'RECHAZAR', 'motivo' => $motivo], $ip);
        });

        return ['mensaje' => 'Devolución rechazada.'];
    }

    /** Cierra la devolución: entrega la reposición (sale del stock) o ejecuta el reintegro sobre los pagos del pedido. */
    public function completar(Usuario $personal, int $id, ?string $ip): array
    {
        DB::transaction(function () use ($personal, $id, $ip) {
            [$pedido, , $d] = $this->bloquear($id, 'APROBADA', 'Solo se completa una devolución aprobada.');

            if ($d->resolucion === 'REPOSICION') {
                if (! $this->inventario->salidaReposicion((int) $d->producto_id, (float) $d->cantidad_aprobada, $d->id, $personal->id, $d->codigo)) {
                    throw new NegocioException('No hay stock suficiente para la reposición. Abastece el producto e inténtalo de nuevo.');
                }
                $mensaje = "Tu reposición de {$d->producto} está lista.";
                $tipo = 'DEVOLUCION_RESUELTA';
            } else {
                // Separación de funciones del Proyecto 1: nadie registra el reintegro de su propio pedido.
                SeparacionFunciones::exigirAjeno($personal, $pedido, 'registrar el reembolso');
                $this->reintegrar($d, $personal, $ip);
                $hayDinero = (float) $d->importe_reembolsable > 0;
                $mensaje = $hayDinero
                    ? 'Te reintegramos Bs '.number_format((float) $d->importe_reembolsable, 2, ',', '.')." por la devolución de {$d->producto}."
                    : "Cerramos la devolución de {$d->producto}: su valor ya se descontó de tus cuotas pendientes.";
                $tipo = $hayDinero ? 'REEMBOLSO_EJECUTADO' : 'DEVOLUCION_RESUELTA';
            }

            $this->devoluciones->completar($d->id)
                || throw new NegocioException('La devolución cambió mientras la procesabas. Recarga la página.', 409);
            $this->notificaciones->crearParaPedido((int) $d->cliente_id, (int) $d->pedido_id, $tipo, "Devolución completada · {$d->codigo}", $mensaje);
            $this->auditoria->operacion($personal->id, 'MODIFICAR', 'DEVOLUCION', $d->id, ['operacion' => 'COMPLETAR', 'resolucion' => $d->resolucion], $ip);
        });

        return ['mensaje' => 'Devolución completada.'];
    }

    /**
     * Importe en dinero de una devolución aprobada con REEMBOLSO y, si hace falta, lo que se descuenta de las cuotas.
     *
     * 1. Valor de lo devuelto dentro de su línea (R018): acumulado redondeado menos lo ya atribuido a la línea.
     * 2. Tope por pedido (R020): el dinero a reintegrar no supera lo realmente cobrado y aún no comprometido.
     * 3. Con un plan de cuotas a medio pagar, la parte del valor que no se puede reintegrar porque todavía no se
     *    cobró se descuenta de las cuotas pendientes (decisión conservadora: no se devuelve dinero que no entró y el
     *    cliente deja de deber lo que devolvió). Sin plan activo, se rechaza y se pide otra resolución.
     *
     * @return array{0:int, 1:int} [importe en centavos, descuento de cuotas en centavos]
     */
    private function atribuir(object $d, object $pedido, ?ConfirmacionOperacion $plan, float $aprobada): array
    {
        $linea = $this->devoluciones->atribuidoDeLinea((int) $d->detalle_pedido_id);
        $yaDescontado = $plan ? $this->planes->descontadoDeLineaCentavos($plan, (int) $d->detalle_pedido_id) : 0;

        $valor = AtribucionImporte::calcular(
            AtribucionImporte::centavos($d->subtotal_linea),
            AtribucionImporte::milesimas($d->cantidad_linea),
            AtribucionImporte::milesimas($linea->cantidad),
            AtribucionImporte::centavos($linea->importe) + $yaDescontado,
            AtribucionImporte::milesimas($aprobada),
        );

        $pagado = AtribucionImporte::centavos($this->pagos->totalPagado((int) $pedido->id));
        $comprometido = AtribucionImporte::centavos($this->devoluciones->atribuidoDelPedido((int) $pedido->id));
        $disponible = max(0, $pagado - $comprometido);
        if ($valor <= $disponible) {
            return [$valor, 0];
        }

        $falta = $valor - $disponible;
        $planActivo = $plan !== null && $plan->parametros['estado'] === PlanPagoService::ACTIVO;
        if (! $planActivo || $falta > $this->planes->saldoPendienteCentavos($plan)) {
            throw new NegocioException('El reintegro de Bs '.number_format(AtribucionImporte::bolivianos($valor), 2, ',', '.')
                .' superaría lo cobrado de este pedido (cobrado Bs '.number_format(AtribucionImporte::bolivianos($pagado), 2, ',', '.')
                .', ya comprometido en otros reintegros Bs '.number_format(AtribucionImporte::bolivianos($comprometido), 2, ',', '.')
                .'). Aprueba menos unidades o resuelve con reposición.');
        }
        if ($this->pagos->pendienteDelPedido((int) $pedido->id, false) !== null) {
            throw new NegocioException('Este pedido tiene un pago pendiente de verificación. Confírmalo o recházalo antes de aprobar '
                .'una devolución que cambia sus cuotas.', 409);
        }

        return [$disponible, $falta];
    }

    /**
     * Reparte el importe entre los pagos del pedido (con cuotas puede haber varios), sin superar lo que queda por
     * reintegrar de cada pago. Primero bloquea los pagos y después, en otra sentencia, lee sus saldos.
     */
    private function reintegrar(object $d, Usuario $personal, ?string $ip): void
    {
        $falta = AtribucionImporte::centavos($d->importe_reembolsable);
        if ($falta <= 0) {
            return;
        }

        $this->pagos->bloquearPagados((int) $d->pedido_id);
        $intentos = $this->pagos->reembolsosDelPedido((int) $d->pedido_id)->where('devolucion_id', $d->id);
        if ($intentos->contains('estado', 'PENDIENTE')) {
            throw new NegocioException('Hay un reintegro pendiente de conciliación. Verifica su resultado antes de volver a intentar.', 409);
        }
        $falta -= AtribucionImporte::centavos($intentos->where('estado', 'EJECUTADO')->sum('monto'));
        if ($falta <= 0) {
            return;
        }

        $pagos = $this->pagos->saldosReintegrables((int) $d->pedido_id);
        if ($pagos->sum(fn ($p) => max(0, AtribucionImporte::centavos($p->saldo))) < $falta) {
            throw new NegocioException('Los pagos del pedido no alcanzan para este reintegro.');
        }

        foreach ($pagos as $pago) {
            if ($falta <= 0) {
                break;
            }
            $monto = min($falta, AtribucionImporte::centavos($pago->saldo));
            if ($monto <= 0) {
                continue;
            }
            $motivo = "Devolución {$d->codigo}: {$d->producto}";
            $anterior = $intentos->where('pago_id', $pago->id)->last();
            $clave = $anterior ? hash('sha256', "REEMBOLSO|{$d->id}|{$pago->id}|DESPUES|{$anterior->id}") : null;
            $reembolso = $this->pagos->crearReembolso((int) $pago->id, (int) $d->id, Cantidad::monto(AtribucionImporte::bolivianos($monto)), $motivo, $clave);
            $this->auditoria->operacion($personal->id, 'CREAR', 'REEMBOLSO', (int) $reembolso->id, [
                'operacion' => 'REINTEGRO_DEVOLUCION', 'pago_id' => (int) $pago->id, 'devolucion_id' => (int) $d->id,
                'pedido_id' => (int) $d->pedido_id, 'monto' => AtribucionImporte::bolivianos($monto), 'estado' => 'EJECUTADO',
                'referencia' => $reembolso->referencia, 'motivo' => $motivo,
            ], $ip);
            $falta -= $monto;
        }
    }

    /**
     * Bloquea en el orden único y devuelve [pedido, plan, devolución] ya releídos:
     * pedido → plan de cuotas → devolución → despacho.
     */
    private function bloquear(int $id, string $estado, string $error): array
    {
        $previa = $this->devoluciones->buscar($id) ?? throw new NegocioException('Esa devolución no existe.', 404);
        $pedido = $this->pedidos->buscar((int) $previa->pedido_id, bloquear: true);
        $plan = $this->planes->delPedido((int) $pedido->id, bloquear: true);
        $d = $this->devoluciones->buscar($id, bloquear: true);
        $this->devoluciones->bloquearDespacho((int) $d->movimiento_inventario_id);

        if ($d->estado !== $estado) {
            throw new NegocioException($error);
        }

        return [$pedido, $plan, $d];
    }

    /** Los productos que se venden por UNIDAD solo se devuelven en unidades enteras. */
    private function exigirEnteras(object $fila, array $cantidades): void
    {
        foreach ($cantidades as $cantidad) {
            if ($fila->unidad_medida === 'UNIDAD' && ! Cantidad::esEntera($cantidad)) {
                throw new NegocioException("«{$fila->producto}» se vende por unidades: las cantidades deben ser números enteros.");
            }
        }
    }

    /** 2 → "2"; 1.5 → "1,5" (para mensajes al cliente). */
    private static function legible(float $cantidad): string
    {
        return str_replace('.', ',', rtrim(rtrim(Cantidad::texto($cantidad), '0'), '.'));
    }

    private function formatear(object $d): array
    {
        return [
            'id' => (int) $d->id,
            'codigo' => $d->codigo,
            'pedido' => ['id' => (int) $d->pedido_id, 'codigo' => $d->pedido],
            'producto' => $d->producto,
            'cantidad' => (float) $d->cantidad,
            'unidad' => $d->unidad_medida,
            'motivo' => $d->motivo,
            'estado' => $d->estado,
            'resolucion' => $d->resolucion,
            'cantidad_aprobada' => $d->cantidad_aprobada !== null ? (float) $d->cantidad_aprobada : null,
            'cantidad_recibida' => $d->cantidad_recibida !== null ? (float) $d->cantidad_recibida : null,
            'cantidad_apta' => $d->cantidad_apta !== null ? (float) $d->cantidad_apta : null,
            'importe' => $d->importe_reembolsable !== null ? (float) $d->importe_reembolsable : null,
            'fecha_solicitud' => $d->fecha_solicitud,
            'fecha_resolucion' => $d->fecha_resolucion,
            'fecha_cierre' => $d->fecha_cierre,
        ];
    }
}
