<?php

namespace App\Support;

use App\Models\Usuario;
use Carbon\CarbonInterface;

/**
 * Sesión web resuelta a partir del token Bearer: quién es el usuario y con qué rol está operando.
 */
final class SesionActiva
{
    public function __construct(
        public readonly int $id,
        public readonly Usuario $usuario,
        public readonly int $rolId,
        public readonly string $rolCodigo,
        public readonly CarbonInterface $expiraEn,
    ) {
    }
}
