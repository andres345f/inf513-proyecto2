<?php

namespace App\Support;

/**
 * Contraseñas aleatorias fuertes para el restablecimiento de cuentas.
 * Usa el generador criptográfico del sistema (random_int) y evita caracteres que se confunden al copiarlos (0/O, 1/l/I)
 * o que dan problemas al pegarlos en una consola (comillas, espacios, $, barras).
 */
final class GeneradorContrasenas
{
    private const MAYUSCULAS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const MINUSCULAS = 'abcdefghijkmnpqrstuvwxyz';

    private const DIGITOS = '23456789';

    private const SIMBOLOS = '!#%*+-=?@_';

    private function __construct()
    {
    }

    /** Al menos una mayúscula, una minúscula, un dígito y un símbolo; el resto, de cualquiera de los cuatro grupos. */
    public static function generar(int $longitud = 20): string
    {
        $longitud = max(16, min(64, $longitud));
        $grupos = [self::MAYUSCULAS, self::MINUSCULAS, self::DIGITOS, self::SIMBOLOS];
        $todos = implode('', $grupos);

        $caracteres = array_map(fn (string $grupo) => $grupo[random_int(0, strlen($grupo) - 1)], $grupos);
        while (count($caracteres) < $longitud) {
            $caracteres[] = $todos[random_int(0, strlen($todos) - 1)];
        }

        // Mezcla de Fisher-Yates con random_int: los cuatro obligatorios no quedan siempre al principio.
        for ($i = count($caracteres) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$caracteres[$i], $caracteres[$j]] = [$caracteres[$j], $caracteres[$i]];
        }

        return implode('', $caracteres);
    }
}
