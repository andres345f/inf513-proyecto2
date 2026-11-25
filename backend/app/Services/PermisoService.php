<?php

namespace App\Services;

use App\Repositories\ConfiguracionWebRepository;
use App\Support\SesionActiva;

/**
 * Matriz de acceso (RBAC) y menú dinámico, ambos leídos desde la base de datos.
 */
class PermisoService
{
    /** @var array<string, list<string>> */
    private array $cachePermisos = [];

    public function __construct(private readonly ConfiguracionWebRepository $configuracion)
    {
    }

    /** Código de rol con el que se evalúan los permisos: el rol activo o el visitante. */
    public function rolEfectivo(?SesionActiva $sesion): string
    {
        return $sesion?->rolCodigo ?? config('feder.rol_visitante');
    }

    /** @return list<string> */
    public function permisosDe(string $rolCodigo): array
    {
        return $this->cachePermisos[$rolCodigo] ??= $this->configuracion->permisosDeRol($rolCodigo);
    }

    public function tienePermiso(?SesionActiva $sesion, string $permiso): bool
    {
        return in_array($permiso, $this->permisosDe($this->rolEfectivo($sesion)), true);
    }

    /**
     * Menú del rol efectivo: solo las entradas cuyo permiso está concedido, agrupadas y en orden.
     *
     * @return list<array{grupo:string, entradas:list<array>}>
     */
    public function menuPara(?SesionActiva $sesion): array
    {
        $permisos = $this->permisosDe($this->rolEfectivo($sesion));

        return $this->configuracion->entradasMenu()
            ->filter(fn (array $entrada) => in_array($entrada['permiso'], $permisos, true))
            ->groupBy('grupo')
            ->map(fn ($entradas, $grupo) => [
                'grupo' => $grupo,
                'entradas' => $entradas->map(fn ($e) => [
                    'etiqueta' => $e['etiqueta'],
                    'ruta' => $e['ruta'],
                    'icono' => $e['icono'],
                    'permiso' => $e['permiso'],
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
