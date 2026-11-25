<?php

namespace App\Console\Commands;

use App\Services\AbastecimientoService;
use Illuminate\Console\Command;

class AbastecerEncargos extends Command
{
    protected $signature = 'feder:abastecer-encargos';
    protected $description = 'Reserva los encargos atendibles por orden de creación y publica los avisos pendientes';

    public function handle(AbastecimientoService $abastecimiento): int
    {
        $total = $abastecimiento->revisar();
        $this->info("Encargos abastecidos: {$total}.");

        return self::SUCCESS;
    }
}
