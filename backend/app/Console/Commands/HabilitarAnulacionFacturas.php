<?php

namespace App\Console\Commands;

use App\Repositories\BitacoraRepository;
use App\Repositories\ConfiguracionWebRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HabilitarAnulacionFacturas extends Command
{
    protected $signature = 'feder:habilitar-anulacion-facturas';

    protected $description = 'Habilita una vez el registro de anulación para ADMINISTRADOR en instalaciones anteriores';

    public function handle(ConfiguracionWebRepository $configuracion, BitacoraRepository $bitacora): int
    {
        return DB::transaction(function () use ($configuracion, $bitacora) {
            $fila = $configuracion->bloquearPermisosDeRol('ADMINISTRADOR');
            if (! $fila) {
                $this->error('Falta la configuración vigente y válida de ADMINISTRADOR.');

                return self::FAILURE;
            }

            if ($bitacora->anulacionFacturasYaConfigurada((int) $fila->id)) {
                $this->info('La instalación ya configuró este permiso. Se conserva la decisión vigente.');

                return self::SUCCESS;
            }

            $parametros = $fila->parametros;
            $agregados = [];
            if (! in_array('facturas.anular', $parametros['permisos'], true)) {
                $agregados = ['facturas.anular'];
                $parametros['permisos'][] = 'facturas.anular';
                $fila->parametros = $parametros;
                $fila->save();
            }

            $bitacora->registrar(null, 'MODIFICAR', 'PERMISO_ROL', (int) $fila->id, [
                'canal' => 'CONSOLA', 'operacion' => 'HABILITAR_ANULACION_FACTURAS',
                'rol' => 'ADMINISTRADOR', 'agregados' => $agregados,
            ], null);
            $this->info('Permiso de anulación inicializado para ADMINISTRADOR.');

            return self::SUCCESS;
        });
    }
}
