<?php

namespace App\Services;

use App\Repositories\DevolucionRepository;
use App\Repositories\PagoRepository;
use App\Support\AtribucionImporte;

/** Lectura de los efectos ya registrados. Todos los acumulados se calculan en centavos. */
class EstadoCuentaPedidoService
{
    public function __construct(
        private readonly DevolucionRepository $devoluciones,
        private readonly PagoRepository $pagos,
    ) {
    }

    public function calcular(object $pedido, float $cobrado, ?array $plan): array
    {
        $devoluciones = $this->devoluciones->conEfectoEconomico($pedido->id)
            ->orderBy('d.id')->get(['d.id', 'd.codigo', 'd.estado', 'd.importe_reembolsable']);
        $reembolsos = $this->pagos->reembolsosDelPedido($pedido->id);
        $ejecutado = AtribucionImporte::centavos($reembolsos->where('estado', 'EJECUTADO')->sum('monto'));
        $pendiente = AtribucionImporte::centavos($reembolsos->where('estado', 'PENDIENTE')->sum('monto'));

        // Un intento pendiente ya compromete parte del importe aprobado. El resto puede
        // seguir pendiente aunque todavía no se haya creado una fila de reembolso.
        $valorAprobado = 0;
        foreach ($devoluciones as $devolucion) {
            $devolucion->reduccion_cuotas = $plan['reduccion_por_devolucion'][$devolucion->id] ?? 0;
            $valorAprobado += AtribucionImporte::centavos($devolucion->importe_reembolsable) + AtribucionImporte::centavos($devolucion->reduccion_cuotas);
            $comprometido = $reembolsos->where('devolucion_id', $devolucion->id)
                ->whereIn('estado', ['EJECUTADO', 'PENDIENTE'])->sum('monto');
            $pendiente += max(0, AtribucionImporte::centavos($devolucion->importe_reembolsable) - AtribucionImporte::centavos($comprometido));
        }

        $total = AtribucionImporte::centavos($pedido->total);
        $ingreso = AtribucionImporte::centavos($cobrado);
        $reduccion = AtribucionImporte::centavos($plan['descontado_por_devoluciones'] ?? 0);
        $deuda = $plan ? AtribucionImporte::centavos($plan['saldo']) : max(0, $total - $ingreso);
        if ($pedido->estado === 'CANCELADO') {
            $pendiente = max($pendiente, $ingreso - $ejecutado);
            $deuda = 0;
        }

        return [
            'original' => (float) $pedido->total,
            'devoluciones_aprobadas' => AtribucionImporte::bolivianos($valorAprobado),
            'cobrado' => $cobrado,
            'reembolsado' => AtribucionImporte::bolivianos($ejecutado),
            'reembolso_pendiente' => AtribucionImporte::bolivianos($pendiente),
            'reduccion_cuotas' => AtribucionImporte::bolivianos($reduccion),
            'pendiente_pago' => AtribucionImporte::bolivianos($deuda),
            'neto' => AtribucionImporte::bolivianos($ingreso - $ejecutado),
            'liquidacion' => $reduccion > 0 && $deuda === 0 && $pedido->estado !== 'CANCELADO',
            'devoluciones' => $devoluciones,
            'reembolsos' => $reembolsos,
        ];
    }
}
