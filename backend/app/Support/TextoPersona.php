<?php

namespace App\Support;

/**
 * Nombres y apellidos de una persona tal como los admite la tabla usuario
 * (ck_usuario_nombres y ck_usuario_apellidos: al menos una letra y ningún carácter de control).
 */
final class TextoPersona
{
    /**
     * Empieza con letra y sigue con letras (con tildes), espacios, apóstrofo, punto o guion.
     * Solo se admite el espacio común: un salto de línea o un tabulador se rechazan aquí, en su campo,
     * y no llegan a la base de datos.
     */
    public const REGLA = "regex:/^[\pL][\pL '.\-]*$/u";

    private function __construct()
    {
    }

    /** Quita espacios de los extremos si es texto; cualquier otro tipo se deja igual para que la regla «string» lo rechace. */
    public static function limpiar(mixed $valor): mixed
    {
        return is_string($valor) ? trim($valor) : $valor;
    }

    /** Igual que limpiar(), pasando además a minúsculas (correos). */
    public static function limpiarCorreo(mixed $valor): mixed
    {
        return is_string($valor) ? mb_strtolower(trim($valor)) : $valor;
    }
}
