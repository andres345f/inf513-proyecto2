<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reintegro de lo cobrado cuando el personal cancela un pedido antes del despacho (reglas R031 y R052).
 *
 * El reembolso se registra sin devolución (reembolso.devolucion_id = NULL). El esquema solo lo admite con el
 * pedido ya CANCELADO (rg_reembolso_sin_devolucion_pedido_cancelado), así que se inserta después de cancelar,
 * dentro de la misma transacción.
 */
class ReintegroCancelacionRepository
{
    /**
     * Pagos PAGADO del pedido, bloqueados, con lo que queda por reintegrar de cada uno.
     * La suma de reembolsos se lee después de bloquear los pagos, para no usar un total desactualizado.
     *
     * @return Collection<int, object> id, metodo, monto y saldo
     */
    public function pagosCobradosConSaldo(int $pedidoId): Collection
    {
        $pagos = DB::table('pago')->where('pedido_id', $pedidoId)->where('estado', 'PAGADO')
            ->orderBy('id')->lockForUpdate()->get(['id', 'metodo', 'monto']);
        if ($pagos->isEmpty()) {
            return $pagos;
        }

        $reintegrado = DB::table('reembolso')->whereIn('pago_id', $pagos->pluck('id')->all())->where('estado', '<>', 'FALLIDO')
            ->groupBy('pago_id')->selectRaw('pago_id, SUM(monto) AS total')->pluck('total', 'pago_id');

        return $pagos->map(function ($pago) use ($reintegrado) {
            $pago->saldo = round((float) $pago->monto - (float) ($reintegrado[$pago->id] ?? 0), 2);

            return $pago;
        });
    }

    /**
     * Registra el reintegro de un pago por la cancelación de su pedido. Como en el resto de la web, el reintegro
     * es simulado y queda EJECUTADO. La clave es estable: repetir la operación no crea un segundo reembolso.
     */
    public function registrar(int $pedidoId, int $pagoId, string $monto, string $motivo): int
    {
        return (int) DB::table('reembolso')->insertGetId([
            'pago_id' => $pagoId,
            'devolucion_id' => null,
            'monto' => $monto,
            'estado' => 'EJECUTADO',
            'motivo' => mb_substr($motivo, 0, 255),
            'fecha_ejecucion' => DB::raw('now()'),
            'referencia_externa' => 'RCN-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
            'clave_idempotencia' => hash('sha256', 'CANC-PED'.$pedidoId.'-PAGO'.$pagoId),
        ]);
    }
}
