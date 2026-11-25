<?php

namespace App\Support;

/**
 * Cantidades y montos como texto decimal para enviarlos tal cual a columnas NUMERIC.
 */
final class Cantidad
{
    public static function texto(float $valor, int $decimales = 3): string
    {
        return number_format(round($valor, $decimales), $decimales, '.', '');
    }

    public static function monto(float $valor): string
    {
        return self::texto($valor, 2);
    }

    public static function esEntera(float $valor): bool
    {
        return abs($valor - round($valor)) < 0.0000001;
    }
}
