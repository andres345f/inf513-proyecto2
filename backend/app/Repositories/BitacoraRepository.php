<?php

namespace App\Repositories;

use App\Models\Bitacora;

/**
 * Inserciones y conteos sobre la bitácora (la tabla solo admite inserciones).
 */
class BitacoraRepository
{
    public function registrar(?int $usuarioId, string $accion, string $entidad, ?int $entidadId, array $detalle, ?string $ip): void
    {
        Bitacora::query()->create([
            'usuario_id' => $usuarioId,
            'accion' => $accion,
            'entidad' => $entidad,
            'entidad_id' => $entidadId,
            'detalle' => $detalle,
            'direccion_ip' => $ip,
        ]);
    }

    public function anulacionFacturasYaConfigurada(int $filaPermisos): bool
    {
        return Bitacora::query()->where('entidad', 'PERMISO_ROL')->where('entidad_id', $filaPermisos)
            ->where(function ($consulta) {
                $consulta->where('detalle->operacion', 'HABILITAR_ANULACION_FACTURAS')
                    ->orWhereJsonContains('detalle->quitados', 'facturas.anular');
            })->exists();
    }

    public function contarVisitasPagina(string $pagina): int
    {
        return Bitacora::query()
            ->where('entidad', Bitacora::ENTIDAD_ACCESO_WEB)
            ->whereRaw("detalle->>'pagina' = ?", [$pagina])
            ->count();
    }
}
