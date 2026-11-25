<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Usuario;
use App\Repositories\ConfiguracionWebRepository;
use App\Support\PoliticaPermisos;
use Illuminate\Support\Facades\DB;

/**
 * Matriz de permisos por rol (RBAC). Los permisos habilitan acciones y entradas del menú,
 * así que al cambiar los permisos de un rol también cambia su menú.
 */
class AdminPermisoService
{
    /** Sin estos permisos el administrador quedaría fuera de la administración del sistema. */
    private const IMPRESCINDIBLES_ADMIN = ['usuarios.gestionar', 'permisos.gestionar'];

    public function __construct(
        private readonly ConfiguracionWebRepository $configuracion,
        private readonly AuditoriaService $auditoria,
    ) {
    }

    public function matriz(): array
    {
        return [
            'roles' => collect($this->configuracion->matrizPermisos())
                ->map(fn ($permisos, $rol) => ['codigo' => $rol, 'permisos' => $permisos])
                ->sortBy(fn ($r) => array_search($r['codigo'], PoliticaPermisos::ROLES, true) === false ? 99 : array_search($r['codigo'], PoliticaPermisos::ROLES, true))
                ->values()->all(),
            'permisos' => $this->catalogo(),
            'imprescindibles' => ['ADMINISTRADOR' => self::IMPRESCINDIBLES_ADMIN],
            // Permiso => roles que pueden tenerlo (la pantalla puede deshabilitar esas casillas en los demás roles).
            'reservados' => PoliticaPermisos::rolesPorPermiso(),
        ];
    }

    public function actualizar(Usuario $admin, string $rol, array $permisos, ?string $ip): array
    {
        $validos = array_column($this->catalogo(), 'clave');
        $desconocidos = array_diff($permisos, $validos);
        if ($desconocidos !== []) {
            throw new NegocioException('Hay permisos que no existen: '.implode(', ', $desconocidos).'.');
        }
        if ($rol === 'ADMINISTRADOR' && array_diff(self::IMPRESCINDIBLES_ADMIN, $permisos) !== []) {
            throw new NegocioException('El administrador debe conservar la gestión de usuarios y de permisos.');
        }
        $noPermitidos = array_diff($permisos, PoliticaPermisos::admisibles($rol));
        if ($noPermitidos !== []) {
            $rolesPorPermiso = PoliticaPermisos::rolesPorPermiso();
            $motivos = array_map(fn ($p) => $p.' ('.implode(', ', $rolesPorPermiso[$p]).')', $noPermitidos);
            throw new NegocioException('El rol '.$rol.' no admite estos permisos. Roles autorizados: '.implode('; ', $motivos).'.');
        }

        // Se guardan en el orden del catálogo para que la matriz se lea siempre igual.
        $ordenados = array_values(array_intersect($validos, $permisos));

        DB::transaction(function () use ($admin, $rol, $ordenados, $ip) {
            $fila = $this->configuracion->bloquearPermisosDeRol($rol) ?? throw new NegocioException('Ese rol no existe.', 404);
            $antes = PoliticaPermisos::efectivos($rol, $fila->parametros['permisos']);
            $filaId = $this->configuracion->guardarPermisosDeRol($rol, $ordenados);
            $this->auditoria->operacion($admin->id, 'MODIFICAR', 'PERMISO_ROL', $filaId, [
                'rol' => $rol,
                'agregados' => array_values(array_diff($ordenados, $antes)),
                'quitados' => array_values(array_diff($antes, $ordenados)),
            ], $ip);
        });

        return ['mensaje' => 'Permisos actualizados. Cada usuario de ese rol verá su nuevo menú al recargar la página.'] + $this->matriz();
    }

    /**
     * Permisos conocidos, tomados del menú: clave, grupo y las entradas que habilita.
     *
     * @return list<array{clave:string, grupo:string, entradas:list<string>}>
     */
    private function catalogo(): array
    {
        $conocidos = PoliticaPermisos::rolesPorPermiso();

        return $this->configuracion->entradasMenu()
            ->filter(fn ($entrada) => isset($conocidos[$entrada['permiso']]))
            ->groupBy('permiso')
            ->map(fn ($entradas, $permiso) => [
                'clave' => $permiso,
                'grupo' => $entradas->first()['grupo'],
                'entradas' => $entradas->pluck('etiqueta')->all(),
            ])
            ->push(['clave' => 'facturas.anular', 'grupo' => 'Administración', 'entradas' => ['Registrar anulación de factura']])
            ->values()->all();
    }
}
