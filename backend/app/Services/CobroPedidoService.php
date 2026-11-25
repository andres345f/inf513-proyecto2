<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\ConfirmacionOperacion;
use App\Repositories\PagoRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Situación de cobro de un pedido para preparar, despachar y entregar (reglas R047 y R057 del modelo aprobado).
 *
 * El cobro está resuelto si:
 *  1. el pedido está pagado por completo: la suma de sus pagos PAGADO es igual al total, al centavo (R057); o
 *  2. tiene un pago en EFECTIVO pendiente por el saldo exacto (se cobra al entregar); o
 *  3. excepción del Proyecto 2 (PROMPT_FEDER_WEB 7.5): tiene un plan de cuotas válido con la primera cuota pagada.
 *
 * Un cobro mayor que el total no es un cobro completo: se detiene el avance para revisar los pagos.
 * La excepción 3 no aplica a pedidos sin plan, con el plan cancelado, de otro pedido o mal formado (ver
 * cuotasDelPlan), ni cuando los pagos de sus cuotas carecen de respaldo.
 * Quien llama tiene bloqueada la fila del pedido, así que las sumas no cambian mientras decide.
 */
class CobroPedidoService
{
    /** Días entre el vencimiento de una cuota y el de la siguiente (cuotas semanales, PROMPT 7.5). */
    private const DIAS_ENTRE_CUOTAS = 7;

    public function __construct(
        private readonly PagoRepository $pagos,
        private readonly PlanPagoService $planes,
    ) {}

    public function pagado(int $pedidoId): float
    {
        return $this->pagos->totalPagado($pedidoId);
    }

    /** R057: lo cobrado (pagos PAGADO) es exactamente el total del pedido, comparado en centavos. */
    public function pagadoPorCompleto(object $pedido): bool
    {
        return self::centavos($this->pagado((int) $pedido->id)) === self::centavos($pedido->total);
    }

    /** R030. La entrega con una cuota pagada y la emisión de factura tienen condiciones distintas. */
    public function puedeFacturar(object $pedido, ?ConfirmacionOperacion $plan): bool
    {
        if ($pedido->estado === 'CANCELADO' || ! $this->pagadoPorCompleto($pedido)) {
            return false;
        }

        return $plan === null || (
            $plan->parametros['estado'] === PlanPagoService::COMPLETADO
            && $this->planes->esCoherente($plan, $pedido)
            && collect($plan->parametros['cuotas'])->every(fn ($cuota) => $cuota['estado'] === 'PAGADA')
            && $this->cuotasCoincidenConPagos((int) $pedido->id, $plan->parametros['cuotas'])
        );
    }

    /** Lo cobrado supera el total del pedido: no es un cobro completo y hay que revisar los pagos. */
    public function cobradoDeMas(object $pedido): bool
    {
        return self::centavos($this->pagado((int) $pedido->id)) > self::centavos($pedido->total);
    }

    /** Pago EFECTIVO pendiente por el saldo exacto del pedido (contra entrega), o null. */
    public function efectivoContraEntrega(object $pedido): ?object
    {
        $pendiente = $this->pagos->pendienteDelPedido((int) $pedido->id);
        $saldo = self::centavos($pedido->total) - self::centavos($this->pagado((int) $pedido->id));

        return $pendiente !== null && $pendiente->metodo === 'EFECTIVO' && $saldo > 0 && self::centavos($pendiente->monto) === $saldo
            ? $pendiente
            : null;
    }

    /**
     * Excepción PROMPT 7.5: plan de cuotas válido del propio pedido con la primera cuota pagada por un pago real
     * PAGADO de ese pedido y por su importe. Lo cobrado debe coincidir exactamente con las cuotas que el plan da por pagadas.
     */
    public function planConPrimeraCuotaPagada(object $pedido, ?ConfirmacionOperacion $plan): bool
    {
        $cuotas = $this->cuotasDelPlan($pedido, $plan);
        if ($cuotas === null || $cuotas[0]['estado'] !== 'PAGADA') {
            return false;
        }

        return $this->planes->esCoherente($plan, $pedido)
            && $this->cuotasCoincidenConPagos((int) $pedido->id, $cuotas);
    }

    /** Cada cuota pagada debe tener su propio comprobante del pedido y todos los cobros deben quedar explicados. */
    public function cuotasCoincidenConPagos(int $pedidoId, array $cuotas): bool
    {
        $total = 0;
        $verificados = [];
        foreach ($cuotas as $cuota) {
            if ($cuota['estado'] !== 'PAGADA') {
                continue;
            }
            $pagoId = (int) $cuota['pago_id'];
            $pago = $this->pagos->buscar($pagoId);
            if (isset($verificados[$pagoId]) || $pago === null || $pago->estado !== 'PAGADO' || (int) $pago->pedido_id !== $pedidoId
                || self::centavos($pago->monto) !== self::centavos($cuota['monto'])) {
                return false;
            }
            $verificados[$pagoId] = true;
            $total += self::centavos($pago->monto);
        }

        return $total === self::centavos($this->pagado($pedidoId));
    }

    public function resuelto(object $pedido, ?ConfirmacionOperacion $plan): bool
    {
        return $this->pagadoPorCompleto($pedido)
            || $this->efectivoContraEntrega($pedido) !== null
            || $this->planConPrimeraCuotaPagada($pedido, $plan);
    }

    /** @param  string  $accion  verbo en infinitivo para el mensaje: preparar, despachar o entregar */
    public function exigirResuelto(object $pedido, ?ConfirmacionOperacion $plan, string $accion): void
    {
        if ($this->resuelto($pedido, $plan)) {
            return;
        }

        $pagado = self::dinero($this->pagado((int) $pedido->id));
        $total = self::dinero((float) $pedido->total);
        if ($this->cobradoDeMas($pedido)) {
            throw new NegocioException("No se puede {$accion} el pedido {$pedido->codigo}: lo cobrado (Bs {$pagado}) supera el total del pedido "
                ."(Bs {$total}). Revisa sus pagos en Gestión de pagos antes de continuar.");
        }

        $detalle = match (true) {
            $plan === null => 'y no tiene un pago en efectivo contra entrega por el saldo',
            $this->cuotasDelPlan($pedido, $plan) === null => 'y su plan de cuotas no es válido (deben ser 2 o 3 cuotas semanales que sumen el total)',
            default => 'y su plan no acredita la primera cuota pagada con pagos válidos del titular y del pedido',
        };

        throw new NegocioException("No se puede {$accion} el pedido {$pedido->codigo}: lleva pagado Bs {$pagado} de Bs {$total} {$detalle}.");
    }

    public static function centavos(string|float|int $monto): int
    {
        return (int) round((float) $monto * 100);
    }

    public static function dinero(float $monto): string
    {
        return number_format($monto, 2, ',', '.');
    }

    /**
     * Cuotas del plan, en orden, si el plan es del pedido, está ACTIVO o COMPLETADO y tiene la forma de PROMPT 7.5;
     * null si no. Un plan válido:
     *  - es de un pedido desde Bs 200, con «total» igual al del pedido y «num_cuotas» igual a sus cuotas;
     *  - tiene 2 o 3 cuotas numeradas 1, 2[, 3], con importes positivos que suman el total, al centavo;
     *  - vence semanalmente: cada cuota vence 7 días después de la anterior (fechas AAAA-MM-DD);
     *  - cada cuota está PAGADA (con su pago, sin repetirlo) o PENDIENTE, las pagadas van primero y un plan
     *    COMPLETADO no tiene cuotas pendientes.
     *
     * @return list<array{numero: int, monto: int|float|string, vence: string, estado: string, pago_id: mixed}>|null
     */
    private function cuotasDelPlan(object $pedido, ?ConfirmacionOperacion $plan): ?array
    {
        $datos = $plan?->parametros;
        if (! is_array($datos) || self::entero($datos['pedido_id'] ?? null) !== (int) $pedido->id
            || ! in_array($datos['estado'] ?? null, ['ACTIVO', 'COMPLETADO'], true)) {
            return null;
        }

        $total = self::centavos($pedido->total);
        $cuotas = $datos['cuotas'] ?? null;
        if ($total < self::centavos(PlanPagoService::MONTO_MINIMO) || ! is_numeric($datos['total'] ?? null)
            || self::centavos($datos['total']) !== $total || ! is_array($cuotas) || ! array_is_list($cuotas)
            || ! in_array(count($cuotas), PlanPagoService::CUOTAS_PERMITIDAS, true)
            || self::entero($datos['num_cuotas'] ?? null) !== count($cuotas)) {
            return null;
        }
        foreach ($cuotas as $cuota) {
            if (! is_array($cuota) || self::entero($cuota['numero'] ?? null) === null) {
                return null;
            }
        }
        usort($cuotas, fn ($a, $b) => self::entero($a['numero']) <=> self::entero($b['numero']));

        $suma = 0;
        $primerVencimiento = null;
        $pendientes = 0;
        $pagos = [];
        foreach ($cuotas as $i => $cuota) {
            $vence = self::fecha($cuota['vence'] ?? null);
            if (self::entero($cuota['numero']) !== $i + 1 || ! is_numeric($cuota['monto'] ?? null)
                || self::centavos($cuota['monto']) <= 0 || $vence === null) {
                return null;
            }
            $primerVencimiento ??= $vence;
            if ($primerVencimiento->modify('+'.(self::DIAS_ENTRE_CUOTAS * $i).' days')->format('Y-m-d') !== $vence->format('Y-m-d')) {
                return null;
            }

            $estado = $cuota['estado'] ?? null;
            $pagoId = self::entero($cuota['pago_id'] ?? null);
            if ($estado === 'PAGADA') {
                if ($pendientes > 0 || $pagoId === null || in_array($pagoId, $pagos, true)) {
                    return null;
                }
                $pagos[] = $pagoId;
            } elseif ($estado === 'PENDIENTE') {
                $pendientes++;
            } else {
                return null;
            }
            $suma += self::centavos($cuota['monto']);
        }

        if ($suma !== $total || ($datos['estado'] === 'COMPLETADO' && $pendientes > 0)) {
            return null;
        }

        return $cuotas;
    }

    /** Entero no negativo guardado como número o como texto de dígitos; null si es otra cosa. */
    private static function entero(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor >= 0 ? $valor : null;
        }

        return is_string($valor) && ctype_digit($valor) ? (int) $valor : null;
    }

    /** Fecha civil AAAA-MM-DD válida (sin hora ni zona), o null. */
    private static function fecha(mixed $valor): ?DateTimeImmutable
    {
        if (! is_string($valor)) {
            return null;
        }
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor, new DateTimeZone('UTC'));

        return $fecha !== false && $fecha->format('Y-m-d') === $valor ? $fecha : null;
    }
}
