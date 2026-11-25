<?php

namespace App\Support;

/**
 * Pasarela de tarjetas simulada (requisito del docente: "tarjetas o pasarelas simuladas").
 * Nunca se guarda el número completo ni el CVV: solo marca, últimos 4 dígitos y el resultado simulado.
 *
 * Tarjetas de prueba:
 *   4242 4242 4242 4242 → aprobada
 *   4000 0000 0000 0002 → rechazada por fondos insuficientes
 *   cualquier otro número válido (Luhn) → aprobada
 */
final class TarjetaSimulada
{
    public const APROBADA = 'APROBADA';
    public const FONDOS_INSUFICIENTES = 'FONDOS_INSUFICIENTES';

    public static function normalizar(string $numero): string
    {
        return preg_replace('/\D/', '', $numero);
    }

    public static function luhnValido(string $numero): bool
    {
        $digitos = self::normalizar($numero);
        if (strlen($digitos) < 13 || strlen($digitos) > 19) {
            return false;
        }

        $suma = 0;
        foreach (array_reverse(str_split($digitos)) as $i => $d) {
            $d = (int) $d;
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $suma += $d;
        }

        return $suma % 10 === 0;
    }

    public static function marca(string $numero): string
    {
        $n = self::normalizar($numero);

        return match (true) {
            str_starts_with($n, '4') => 'VISA',
            (bool) preg_match('/^(5[1-5]|2(2[2-9]|[3-6]\d|7[01]|720))/', $n) => 'MASTERCARD',
            (bool) preg_match('/^3[47]/', $n) => 'AMEX',
            default => 'OTRA',
        };
    }

    public static function comportamiento(string $numero): string
    {
        return self::normalizar($numero) === '4000000000000002' ? self::FONDOS_INSUFICIENTES : self::APROBADA;
    }

    public static function vencida(int $mes, int $anio): bool
    {
        $finDeMes = now()->setDate($anio, $mes, 1)->endOfMonth();

        return $finDeMes->isPast();
    }
}
