<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
| Tareas programadas de FEDER Web.
| En el servidor se activan con un cron que ejecute cada minuto:
|   * * * * * cd /ruta/al/backend && php artisan schedule:run >> /dev/null 2>&1
*/

// Varias bases del laboratorio pueden compartir la caché de archivos de este mismo backend.
$mutexPorBase = static fn ($evento) => 'framework'.DIRECTORY_SEPARATOR.'schedule-'.sha1(
    DB::connection()->getDatabaseName().'|'.$evento->expression.'|'.$evento::normalizeCommand($evento->command)
);

Schedule::command('feder:vencer-pedidos')->everyFiveMinutes()->withoutOverlapping()->createMutexNameUsing($mutexPorBase);
Schedule::command('feder:abastecer-encargos')->everyMinute()->withoutOverlapping(5)->createMutexNameUsing($mutexPorBase);
