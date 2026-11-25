<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Regla de negocio incumplida. Su mensaje ya está redactado en español para el usuario final.
 */
class NegocioException extends RuntimeException
{
    public function __construct(string $mensaje, private readonly int $estadoHttp = 422, public readonly array $errores = [])
    {
        parent::__construct($mensaje);
    }

    public function estadoHttp(): int
    {
        return $this->estadoHttp;
    }
}
