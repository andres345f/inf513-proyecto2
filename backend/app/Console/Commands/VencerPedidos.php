<?php

namespace App\Console\Commands;

use App\Services\GestionPedidoService;
use App\Services\ReservaService;
use Illuminate\Console\Command;

/**
 * Tarea automática:
 * - cancela los pedidos sin pagar cuyo plazo venció (compra inmediata y encargos), liberando su stock;
 * - informa los pedidos vencidos con un pago QR o TARJETA pendiente: no se cancelan hasta conciliar ese pago (R053);
 * - pasa a VENCIDO los lotes cuyo origen ya caducó y reasigna sus reservas a otros lotes vigentes.
 */
class VencerPedidos extends Command
{
    protected $signature = 'feder:vencer-pedidos';

    protected $description = 'Cancela pedidos sin pago vencidos, informa los pagos por conciliar y da de baja los lotes vencidos';

    public function handle(GestionPedidoService $gestion, ReservaService $reservas): int
    {
        $vencidos = $gestion->vencerPedidosSinPago();
        $lotes = $reservas->vencerLotes();

        $this->info("Pedidos cancelados por falta de pago: {$vencidos['cancelados']}. "
            .'Pedidos con un pago por conciliar: '.count($vencidos['por_conciliar']).'. '
            .'Lotes marcados como vencidos: '.count($lotes).'.');
        foreach ($vencidos['por_conciliar'] as $pendiente) {
            $this->warn($pendiente['mensaje']);
        }
        foreach ($lotes as $lote) {
            if ($lote['pedidos_sin_cobertura'] !== []) {
                $this->warn("Lote {$lote['codigo']}: quedaron sin stock reservado los pedidos ".implode(', ', $lote['pedidos_sin_cobertura'])
                    .'. Abastécelos o cancélalos desde Gestión de pedidos.');
            }
        }

        return self::SUCCESS;
    }
}
