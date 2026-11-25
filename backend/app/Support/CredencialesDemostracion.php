<?php

namespace App\Support;

/**
 * Marcador bcrypt de las cuentas pendientes de inicialización.
 * La entrada aleatoria del marcador se descarta al preparar la distribución.
 * Las instalaciones asignan claves individuales mediante el comando de Laravel.
 */
final class CredencialesDemostracion
{
    /** Hash bcrypt exacto que db/03_datos_web.sql asigna a las cuentas de demostración. */
    private const HASH_DATOS_INICIALES = '$2y$10$izxxKCHc1MWVIMI9hxCDtubvAmYMA5lTC8DRsN/215suibCoJ6jii';

    public const MENSAJE_BLOQUEO = 'Esta cuenta todavía usa la contraseña de demostración, que no se admite en producción. '
        .'Quien administra el servidor debe ejecutar «php artisan feder:restablecer-contrasenas» para asignarle una contraseña propia.';

    private function __construct()
    {
    }

    /**
     * Hashes considerados «de demostración»: el de los datos iniciales y, si el despliegue los declara,
     * los de config('feder.hashes_demostracion') (por ejemplo, si el equipo cambia la contraseña de sus datos de prueba).
     *
     * @return list<string>
     */
    public static function hashes(): array
    {
        $adicionales = config('feder.hashes_demostracion', []);
        $adicionales = is_array($adicionales) ? array_filter($adicionales, fn ($h) => is_string($h) && $h !== '') : [];

        return array_values(array_unique([self::HASH_DATOS_INICIALES, ...$adicionales]));
    }

    /** ¿El hash guardado es exactamente uno de los de demostración? */
    public static function esHashDeDemostracion(?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }
        foreach (self::hashes() as $demostracion) {
            if (hash_equals($demostracion, $hash)) {
                return true;
            }
        }

        return false;
    }

    /** ¿La contraseña escrita es la de demostración? (se comprueba contra los hashes, con cualquier sal) */
    public static function esContrasenaDeDemostracion(string $contrasena): bool
    {
        foreach (self::hashes() as $demostracion) {
            if (password_verify($contrasena, $demostracion)) {
                return true;
            }
        }

        return false;
    }
}
