<?php

namespace App\Support;

use Closure;

/**
 * Política de contraseñas web: la misma del registro (al menos 8 caracteres, con letras y números) y como máximo
 * 72 bytes, que es lo que bcrypt usa de verdad (una letra con tilde o la ñ ocupan 2 bytes).
 * La usan el cambio de contraseña (PUT /api/auth/contrasena) y el modo laboratorio de feder:restablecer-contrasenas.
 */
final class PoliticaContrasena
{
    public const MINIMO = 8;

    public const MAXIMO_BYTES = 72;

    private function __construct()
    {
    }

    /**
     * Reglas de Laravel para un campo de contraseña nueva (sin «required», «confirmed» ni «different», que dependen
     * del formulario). Los mensajes de «min» y «regex» los pone cada formulario.
     */
    public static function reglas(): array
    {
        return [
            'string',
            'min:'.self::MINIMO,
            function (string $atributo, mixed $valor, Closure $fallar) {
                if (is_string($valor) && strlen($valor) > self::MAXIMO_BYTES) {
                    $fallar('La contraseña es demasiado larga: admite hasta 72 caracteres (las letras con tilde y la ñ cuentan doble).');
                }
            },
            'regex:/\pL/u',
            'regex:/[0-9]/',
        ];
    }

    /** Motivo por el que la contraseña no cumple la política, o null si la cumple. */
    public static function incumplimiento(string $contrasena): ?string
    {
        return match (true) {
            mb_strlen($contrasena) < self::MINIMO => 'debe tener al menos '.self::MINIMO.' caracteres',
            strlen($contrasena) > self::MAXIMO_BYTES => 'admite hasta 72 caracteres (las letras con tilde y la ñ cuentan doble)',
            preg_match('/\pL/u', $contrasena) !== 1 || preg_match('/[0-9]/', $contrasena) !== 1 => 'debe combinar letras y números',
            default => null,
        };
    }
}
