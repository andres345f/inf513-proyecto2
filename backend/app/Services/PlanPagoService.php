<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\ConfirmacionOperacion;
use App\Models\Usuario;
use App\Repositories\DatosWebUsuarioRepository;
use App\Support\AtribucionImporte;

/**
 * Planes de pago en cuotas (requisito "planes de pagos por usuario").
 * Reglas: pedidos desde Bs 200, 2 o 3 cuotas semanales; el pedido se entrega al pagar la primera cuota;
 * la factura se emite al pagar la última; una cuota vencida bloquea nuevas compras.
 *
 * El plan vive como JSON (comando PLAN_PAGO): la base no lo protege, así que este servicio comprueba que sea
 * coherente con su pedido antes de cobrar o confirmar nada (ver esCoherente).
 *
 * Estados del plan: ACTIVO · COMPLETADO (todas las cuotas pagadas) · CANCELADO (pedido cancelado) ·
 * CERRADO_POR_DEVOLUCION (no quedan cuotas por pagar porque lo devuelto compensó el saldo).
 * Estados de una cuota: PENDIENTE · PAGADA · ANULADA (su importe se descontó por una devolución).
 */
class PlanPagoService
{
    public const MONTO_MINIMO = 200.0;
    public const CUOTAS_PERMITIDAS = [2, 3];
    private const DIAS_ENTRE_CUOTAS = 7;

    public const ACTIVO = 'ACTIVO';
    public const COMPLETADO = 'COMPLETADO';
    public const CANCELADO = 'CANCELADO';
    public const CERRADO_POR_DEVOLUCION = 'CERRADO_POR_DEVOLUCION';

    public function __construct(private readonly DatosWebUsuarioRepository $datos)
    {
    }

    public function validarElegible(float $total, int $numCuotas): void
    {
        if ($total < self::MONTO_MINIMO) {
            throw new NegocioException('El pago en cuotas está disponible para compras desde Bs '.number_format(self::MONTO_MINIMO, 2, ',', '.').'.');
        }
        if (! in_array($numCuotas, self::CUOTAS_PERMITIDAS, true)) {
            throw new NegocioException('Puedes pagar en 2 o 3 cuotas.');
        }
    }

    public function crear(Usuario $cliente, object $pedido, int $numCuotas): ConfirmacionOperacion
    {
        $total = (float) $pedido->total;
        $base = floor($total / $numCuotas * 100) / 100;
        $cuotas = [];

        for ($n = 1; $n <= $numCuotas; $n++) {
            $monto = $n < $numCuotas ? $base : round($total - $base * ($numCuotas - 1), 2);
            $cuotas[] = [
                'numero' => $n,
                'monto' => $monto,
                'vence' => now()->addDays(self::DIAS_ENTRE_CUOTAS * ($n - 1))->toDateString(),
                'estado' => 'PENDIENTE',
                'pago_id' => null,
                'pagada_en' => null,
            ];
        }

        return $this->datos->crear($cliente->id, $cliente->correo, DatosWebUsuarioRepository::PLAN_PAGO, [
            'pedido_id' => (int) $pedido->id,
            'pedido_codigo' => $pedido->codigo,
            'total' => $total,
            'num_cuotas' => $numCuotas,
            'estado' => self::ACTIVO,
            'cuotas' => $cuotas,
        ]);
    }

    public function delPedido(int $pedidoId, bool $bloquear = false): ?ConfirmacionOperacion
    {
        return $this->datos->buscarPlanDePedido($pedidoId, $bloquear);
    }

    /**
     * El plan es el del cliente y el del pedido, y sus cuotas cuadran con el total: mismo dueño, mismo pedido, un
     * solo plan vigente, 2 o 3 cuotas numeradas en orden y suma de cuotas (más lo descontado por devoluciones)
     * igual al total del pedido. Sin esto no se cobra ni se confirma nada con el plan.
     */
    public function esCoherente(ConfirmacionOperacion $plan, object $pedido): bool
    {
        $p = $plan->parametros;
        $cuotas = $p['cuotas'] ?? null;

        if (! is_array($cuotas)
            || (int) $plan->usuario_id !== (int) $pedido->usuario_id
            || (int) ($p['pedido_id'] ?? 0) !== (int) $pedido->id
            || ! in_array($p['estado'] ?? null, [self::ACTIVO, self::COMPLETADO, self::CANCELADO, self::CERRADO_POR_DEVOLUCION], true)
            || ! in_array((int) ($p['num_cuotas'] ?? 0), self::CUOTAS_PERMITIDAS, true)
            || count($cuotas) !== (int) $p['num_cuotas']
            || AtribucionImporte::centavos($p['total'] ?? 0) !== AtribucionImporte::centavos($pedido->total)
            || $this->datos->contarPlanesDePedido((int) $pedido->id) !== 1) {
            return false;
        }

        $suma = 0;
        foreach (array_values($cuotas) as $i => $cuota) {
            $estado = $cuota['estado'] ?? null;
            $monto = AtribucionImporte::centavos($cuota['monto'] ?? -1);
            if ((int) ($cuota['numero'] ?? 0) !== $i + 1
                || ! in_array($estado, ['PENDIENTE', 'PAGADA', 'ANULADA'], true)
                || $monto < 0
                || ($estado === 'PAGADA' && empty($cuota['pago_id']))
                || ($estado === 'PENDIENTE' && ($monto === 0 || ! empty($cuota['pago_id'])))) {
                return false;
            }
            $suma += $monto;
        }
        foreach ($p['ajustes'] ?? [] as $ajuste) {
            $suma += AtribucionImporte::centavos($ajuste['monto'] ?? 0);
        }

        return $suma === AtribucionImporte::centavos($pedido->total);
    }

    public function exigirCoherente(ConfirmacionOperacion $plan, object $pedido): void
    {
        if (! $this->esCoherente($plan, $pedido)) {
            throw new NegocioException("El plan de cuotas del pedido {$pedido->codigo} no coincide con el pedido. "
                .'No se registró ningún cobro: comunícate con FEDER para revisarlo.', 409);
        }
    }

    /** Primera cuota sin pagar del plan, o null si ya no queda ninguna. Las cuotas se pagan en orden. */
    public function siguienteCuota(ConfirmacionOperacion $plan): ?array
    {
        return collect($plan->parametros['cuotas'])->firstWhere('estado', 'PENDIENTE');
    }

    /**
     * La petición de pago dice qué cuota quiere pagar: debe ser la siguiente pendiente.
     * Así, si el mismo envío llega dos veces, el segundo no cobra la cuota siguiente por error.
     */
    public function exigirSiguienteCuota(ConfirmacionOperacion $plan, ?int $numeroPedido): array
    {
        $siguiente = $this->siguienteCuota($plan)
            ?? throw new NegocioException('Este plan ya no tiene cuotas pendientes.', 409);

        if ($numeroPedido === null) {
            throw new NegocioException("Indica qué cuota vas a pagar (corresponde la cuota {$siguiente['numero']}).");
        }
        if ($numeroPedido !== (int) $siguiente['numero']) {
            throw new NegocioException("La cuota {$numeroPedido} no es la que corresponde pagar ahora: sigue la cuota {$siguiente['numero']}. "
                .'Actualiza la página antes de volver a intentar.', 409);
        }

        return $siguiente;
    }

    /**
     * Marca la cuota como PAGADA con un pago que ya está PAGADO, es de este pedido y vale lo mismo que la cuota.
     * Solo acepta la siguiente cuota pendiente, y un pago no puede cubrir dos cuotas.
     */
    public function registrarPagoDeCuota(ConfirmacionOperacion $plan, int $numero, object $pago): array
    {
        $parametros = $plan->parametros;
        $siguiente = $this->siguienteCuota($plan);
        $yaUsado = collect($parametros['cuotas'])->contains(fn ($c) => (int) ($c['pago_id'] ?? 0) === (int) $pago->id);

        if ($pago->estado !== 'PAGADO'
            || (int) $pago->pedido_id !== (int) $parametros['pedido_id']
            || $siguiente === null
            || (int) $siguiente['numero'] !== $numero
            || $yaUsado
            || AtribucionImporte::centavos($siguiente['monto']) !== AtribucionImporte::centavos($pago->monto)) {
            throw new NegocioException('Ese pago no corresponde a la cuota pendiente del plan. No se registró la cuota.', 409);
        }

        foreach ($parametros['cuotas'] as &$cuota) {
            if ((int) $cuota['numero'] === $numero) {
                $cuota['estado'] = 'PAGADA';
                $cuota['pago_id'] = (int) $pago->id;
                $cuota['pagada_en'] = now()->toIso8601String();
            }
        }
        unset($cuota);

        $parametros['estado'] = $this->estadoSegunCuotas($parametros);
        $this->datos->actualizarParametros($plan, $parametros);

        return $parametros;
    }

    /** Suma de las cuotas PENDIENTE (lo que el cliente aún debe del plan), en centavos. */
    public function saldoPendienteCentavos(ConfirmacionOperacion $plan): int
    {
        return (int) collect($plan->parametros['cuotas'])->where('estado', 'PENDIENTE')
            ->sum(fn ($c) => AtribucionImporte::centavos($c['monto']));
    }

    /** Valor de lo devuelto de una línea que ya se descontó de las cuotas (no se reintegró en dinero), en centavos. */
    public function descontadoDeLineaCentavos(ConfirmacionOperacion $plan, int $detallePedidoId): int
    {
        return (int) collect($plan->parametros['ajustes'] ?? [])
            ->filter(fn ($a) => (int) ($a['detalle_pedido_id'] ?? 0) === $detallePedidoId)
            ->sum(fn ($a) => AtribucionImporte::centavos($a['monto']));
    }

    /**
     * Devolución de productos que el cliente todavía no terminó de pagar: la parte del valor devuelto que no se
     * puede reintegrar (porque aún no se cobró) se descuenta de las cuotas pendientes, de la última a la primera.
     * Una cuota que queda en cero pasa a ANULADA y, si ya no queda ninguna pendiente, el plan se cierra: así una
     * cuota de algo ya devuelto no vence ni bloquea compras. Devuelve cómo quedó cada cuota tocada.
     *
     * @return array<int, array{numero:int, antes:float, despues:float}>
     */
    public function descontarPorDevolucion(ConfirmacionOperacion $plan, int $devolucionId, int $detallePedidoId, int $centavos): array
    {
        $parametros = $plan->parametros;
        if (($parametros['estado'] ?? null) !== self::ACTIVO || $centavos <= 0 || $centavos > $this->saldoPendienteCentavos($plan)) {
            throw new NegocioException('El descuento por la devolución no cabe en las cuotas pendientes del plan.', 409);
        }

        $falta = $centavos;
        $tocadas = [];
        for ($i = count($parametros['cuotas']) - 1; $i >= 0 && $falta > 0; $i--) {
            $cuota = $parametros['cuotas'][$i];
            if ($cuota['estado'] !== 'PENDIENTE') {
                continue;
            }
            $antes = AtribucionImporte::centavos($cuota['monto']);
            $quita = min($antes, $falta);
            $despues = $antes - $quita;
            $falta -= $quita;

            $cuota['monto_original'] = $cuota['monto_original'] ?? $cuota['monto'];
            $cuota['monto'] = AtribucionImporte::bolivianos($despues);
            if ($despues === 0) {
                $cuota['estado'] = 'ANULADA';
            }
            $parametros['cuotas'][$i] = $cuota;
            $tocadas[] = ['numero' => (int) $cuota['numero'], 'antes' => AtribucionImporte::bolivianos($antes), 'despues' => AtribucionImporte::bolivianos($despues)];
        }

        $parametros['ajustes'][] = [
            'devolucion_id' => $devolucionId,
            'detalle_pedido_id' => $detallePedidoId,
            'monto' => AtribucionImporte::bolivianos($centavos),
            'cuotas' => $tocadas,
            'fecha' => now()->toIso8601String(),
        ];
        $parametros['estado'] = $this->estadoSegunCuotas($parametros);
        $this->datos->actualizarParametros($plan, $parametros);

        return $tocadas;
    }

    public function cancelar(ConfirmacionOperacion $plan): void
    {
        $parametros = $plan->parametros;
        $parametros['estado'] = self::CANCELADO;
        $this->datos->actualizarParametros($plan, $parametros);
        $this->datos->darDeBaja($plan);
    }

    public function listar(int $clienteId): array
    {
        return $this->datos->vigentes($clienteId, DatosWebUsuarioRepository::PLAN_PAGO)
            ->map(fn (ConfirmacionOperacion $plan) => $this->formatear($plan))
            ->sortByDesc('id')
            ->values()
            ->all();
    }

    public function tieneCuotaVencida(int $clienteId): bool
    {
        $hoy = now()->toDateString();

        return $this->datos->vigentes($clienteId, DatosWebUsuarioRepository::PLAN_PAGO)
            ->contains(function (ConfirmacionOperacion $plan) use ($hoy) {
                $cuotas = collect($plan->parametros['cuotas']);

                return $plan->parametros['estado'] === self::ACTIVO
                    // El vencimiento inicial del pedido ya controla la primera cuota sin pagar.
                    && $cuotas->contains(fn ($c) => $c['estado'] === 'PAGADA')
                    && $cuotas->contains(fn ($c) => $c['estado'] === 'PENDIENTE' && $c['vence'] < $hoy);
            });
    }

    public function formatear(ConfirmacionOperacion $plan): array
    {
        $p = $plan->parametros;
        $hoy = now()->toDateString();
        $cuotas = collect($p['cuotas'])->map(fn ($c) => $c + [
            'vencida' => $c['estado'] === 'PENDIENTE' && $c['vence'] < $hoy,
        ]);

        return [
            'id' => $plan->id,
            'pedido_id' => $p['pedido_id'],
            'pedido_codigo' => $p['pedido_codigo'],
            'total' => $p['total'],
            'num_cuotas' => $p['num_cuotas'],
            'estado' => $p['estado'],
            'pagado' => round($cuotas->where('estado', 'PAGADA')->sum('monto'), 2),
            'saldo' => round($cuotas->where('estado', 'PENDIENTE')->sum('monto'), 2),
            'descontado_por_devoluciones' => round(collect($p['ajustes'] ?? [])->sum('monto'), 2),
            'reduccion_por_devolucion' => collect($p['ajustes'] ?? [])->groupBy('devolucion_id')
                ->map(fn ($ajustes) => round($ajustes->sum('monto'), 2))->all(),
            'cuotas' => $cuotas->values()->all(),
            'creado_en' => $plan->creada_en?->toIso8601String(),
        ];
    }

    /** COMPLETADO si todas las cuotas están pagadas; cerrado si no queda ninguna pendiente; si no, sigue ACTIVO. */
    private function estadoSegunCuotas(array $parametros): string
    {
        $cuotas = collect($parametros['cuotas']);

        return match (true) {
            $cuotas->every(fn ($c) => $c['estado'] === 'PAGADA') => self::COMPLETADO,
            ! $cuotas->contains(fn ($c) => $c['estado'] === 'PENDIENTE') => self::CERRADO_POR_DEVOLUCION,
            default => self::ACTIVO,
        };
    }
}
