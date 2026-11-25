<?php

namespace App\Repositories;

use App\Models\Rol;

/**
 * Acceso al catálogo cerrado de roles.
 */
class RolRepository
{
    public function buscarPorCodigo(string $codigo): ?Rol
    {
        return Rol::query()->where('codigo', $codigo)->first();
    }
}
