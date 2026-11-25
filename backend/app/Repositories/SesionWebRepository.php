<?php

namespace App\Repositories;

use App\Models\ConfirmacionOperacion;
use App\Support\EstructuraFilaWeb;
use Carbon\CarbonInterface;

/**
 * Sesiones web guardadas en confirmacion_operacion (comando SESION_WEB).
 * Solo se guarda el hash SHA-256 del token; el token en claro nunca se almacena.
 */
class SesionWebRepository
{
    public function crear(string $hashToken, string $correo, int $usuarioId, int $rolId, string $rolCodigo, CarbonInterface $expiraEn): ConfirmacionOperacion
    {
        return ConfirmacionOperacion::query()->create([
            'codigo_hash' => $hashToken,
            'correo' => mb_strtolower($correo),
            'usuario_id' => $usuarioId,
            'comando' => ConfirmacionOperacion::SESION_WEB,
            'parametros' => ['rol_id' => $rolId, 'rol_codigo' => $rolCodigo],
            'expira_en' => $expiraEn,
        ]);
    }

    /** Sesión vigente de ese token. Una fila cuyo JSON no tiene la forma de una sesión se trata como sesión inexistente. */
    public function buscarVigentePorHash(string $hashToken): ?ConfirmacionOperacion
    {
        $fila = ConfirmacionOperacion::query()
            ->where('codigo_hash', $hashToken)
            ->where('comando', ConfirmacionOperacion::SESION_WEB)
            ->whereNull('usada_en')
            ->where('expira_en', '>', now())
            ->first();

        return $fila !== null && EstructuraFilaWeb::esValida(ConfirmacionOperacion::SESION_WEB, $fila->parametros) ? $fila : null;
    }

    /** Devuelve true si la sesión seguía abierta y quedó cerrada con esta llamada. */
    public function cerrar(int $sesionId): bool
    {
        return ConfirmacionOperacion::query()->whereKey($sesionId)->whereNull('usada_en')->update(['usada_en' => now()]) === 1;
    }

    public function cambiarRol(int $sesionId, int $rolId, string $rolCodigo): void
    {
        ConfirmacionOperacion::query()->whereKey($sesionId)->update([
            'parametros' => json_encode(['rol_id' => $rolId, 'rol_codigo' => $rolCodigo]),
        ]);
    }
}
