<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Repositories\BitacoraRepository;
use App\Repositories\InventarioRepository;
use App\Repositories\PedidoRepository;
use App\Support\Cantidad;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reserva de stock por líneas de pedido, repartida entre lotes por FEFO: primero el lote que vence antes,
 * los que no vencen al final y, a igualdad, el que ingresó antes (reglas R026 y R037 del modelo aprobado).
 * La usan la compra inmediata (reservas con vencimiento), el abastecimiento de encargos (sin vencimiento)
 * y el proceso que da de baja los lotes vencidos, que reasigna sus reservas (regla R035).
 */
class ReservaService
{
    public function __construct(
        private readonly InventarioRepository $inventario,
        private readonly PedidoRepository $pedidos,
        private readonly BitacoraRepository $bitacora,
    ) {}

    /**
     * Reserva la cantidad completa de cada línea o no reserva nada (lanza 409 y quien llama revierte).
     *
     * @param  Collection  $lineas  elementos con id (detalle_pedido), producto_id, cantidad y nombre
     * @return list<array{lote_feder_id:int, detalle_pedido_id:int, cantidad:float}> reservas creadas
     */
    public function reservarLineas(Collection $lineas, ?string $expiraEn): array
    {
        $lineas = $lineas->map(fn ($linea) => (array) $linea)->values();
        // Todos los lotes candidatos del pedido se bloquean en una sola pasada, por orden de id.
        $lotes = $this->inventario->lotesVendiblesDeProductos(
            $lineas->pluck('producto_id')->map(fn ($id) => (int) $id)->unique()->values()->all()
        );
        $libres = [];
        $creadas = [];

        foreach ($lineas as $linea) {
            $pedida = round((float) $linea['cantidad'], 3);
            $reservadas = $this->asignar($lotes->get((int) $linea['producto_id'], collect()), $libres, (int) $linea['id'], $pedida, $expiraEn);
            $falta = round($pedida - array_sum(array_column($reservadas, 'cantidad')), 3);
            if ($falta > 0) {
                throw new NegocioException("No hay stock suficiente de «{$linea['nombre']}» para reservar (faltan ".$falta.').', 409);
            }
            array_push($creadas, ...$reservadas);
        }

        return $creadas;
    }

    /**
     * Proceso automático de vencimiento de lotes (regla R035): cada lote DISPONIBLE o AGOTADO cuyo origen ya
     * venció pasa a VENCIDO; sus reservas activas se liberan y se reasignan por FEFO a otros lotes vendibles.
     * Si no alcanza el stock, la línea queda sin cobertura y su pedido no se despacha hasta abastecerlo o
     * cancelarlo. No crea ni modifica movimientos de inventario: vencer un lote no mueve mercancía.
     *
     * @return list<array{lote_id:int, codigo:string, reservas_liberadas:int, cantidad_liberada:float, cantidad_reasignada:float, pedidos_sin_cobertura:list<string>}>
     */
    public function vencerLotes(): array
    {
        $vencidos = [];
        foreach ($this->inventario->lotesPorVencer() as $loteId) {
            $resultado = DB::transaction(fn () => $this->vencerLote($loteId));
            if ($resultado !== null) {
                $vencidos[] = $resultado;
            }
        }

        return $vencidos;
    }

    /** Orden de bloqueo: primero los pedidos afectados (por id), después el lote y sus candidatos (por id). */
    private function vencerLote(int $loteId): ?array
    {
        $afectados = [];
        foreach ($this->inventario->pedidosConReservaActivaEnLote($loteId) as $pedidoId) {
            $pedido = $this->pedidos->buscar($pedidoId, bloquear: true);
            if ($pedido !== null) {
                $afectados[$pedidoId] = $pedido;
            }
        }

        [$lote, $candidatos] = $this->inventario->bloquearLoteConVendibles($loteId);
        if ($lote === null || ! $lote->vencido || ! in_array($lote->estado, ['DISPONIBLE', 'AGOTADO'], true)) {
            return null;
        }

        $activas = $this->inventario->reservasActivasDeLote($loteId);
        if ($activas->pluck('pedido_id')->map(fn ($id) => (int) $id)->diff(array_keys($afectados))->isNotEmpty()) {
            // El lote recibió una reserva de un pedido que no estaba bloqueado: se deja para la siguiente pasada.
            return null;
        }
        if (! $this->inventario->marcarVencido($loteId)) {
            return null;
        }

        $this->inventario->liberarReservasDeLote($activas);
        $libres = [];
        $reasignada = 0.0;
        foreach ($activas as $reserva) {
            $nuevas = $this->asignar($candidatos, $libres, (int) $reserva->detalle_pedido_id, (float) $reserva->cantidad, $reserva->fecha_expiracion);
            $reasignada += array_sum(array_column($nuevas, 'cantidad'));
        }

        $sinCobertura = collect($afectados)
            ->filter(fn ($pedido) => $this->inventario->coberturaDePedido((int) $pedido->id)->contains(fn ($l) => $l->pendiente > 0))
            ->pluck('codigo')->values()->all();
        $resultado = [
            'lote_id' => $loteId,
            'codigo' => $lote->codigo,
            'reservas_liberadas' => $activas->count(),
            'cantidad_liberada' => round((float) $activas->sum(fn ($r) => (float) $r->cantidad), 3),
            'cantidad_reasignada' => round($reasignada, 3),
            'pedidos_sin_cobertura' => $sinCobertura,
        ];

        $this->bitacora->registrar(null, 'MODIFICAR', 'LOTE_FEDER', $loteId, [
            'canal' => 'WEB', 'origen' => 'TAREA_PROGRAMADA', 'operacion' => 'VENCER_LOTE', 'codigo' => $lote->codigo,
            'estado_anterior' => $lote->estado, 'estado_nuevo' => 'VENCIDO', 'fecha_vencimiento' => $lote->fecha_vencimiento,
        ] + array_diff_key($resultado, ['lote_id' => 0, 'codigo' => 0]), null);

        return $resultado;
    }

    /**
     * Reserva para una línea todo lo que permitan los lotes (ya bloqueados y en orden FEFO), hasta la cantidad pedida.
     *
     * @param  array<int, float>  $libres  saldo libre que queda en cada lote dentro de esta operación
     * @return list<array{lote_feder_id:int, detalle_pedido_id:int, cantidad:float}>
     */
    private function asignar(Collection $lotes, array &$libres, int $detallePedidoId, float $cantidad, ?string $expiraEn): array
    {
        $restante = round($cantidad, 3);
        $creadas = [];

        foreach ($lotes as $lote) {
            if ($restante <= 0) {
                break;
            }
            $libre = $libres[$lote->id] ??= round((float) $lote->libre, 3);
            if ($libre <= 0) {
                continue;
            }
            $tomar = round(min($libre, $restante), 3);
            if ($this->inventario->reservarEnLote((int) $lote->id, Cantidad::texto($tomar))) {
                $this->inventario->crearReserva((int) $lote->id, $detallePedidoId, Cantidad::texto($tomar), $expiraEn);
                $creadas[] = ['lote_feder_id' => (int) $lote->id, 'detalle_pedido_id' => $detallePedidoId, 'cantidad' => $tomar];
                $libres[$lote->id] = round($libre - $tomar, 3);
                $restante = round($restante - $tomar, 3);
            }
        }

        return $creadas;
    }
}
