<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * Comparación de texto que no distingue tildes ni mayúsculas («cafe» encuentra «Café», «JABON» encuentra «Jabón»).
 *
 * Usa translate() de PostgreSQL (función incorporada) sobre la columna y la misma tabla de equivalencias en PHP
 * sobre el texto buscado: no requiere la extensión unaccent ni cambios de esquema.
 */
final class TextoBusqueda
{
    /** Letras con tilde o diéresis y su equivalente sin ella (mismo orden en ambas cadenas). */
    public const CON_TILDE = 'áéíóúüñàèìòùâêîôûäëïöçÁÉÍÓÚÜÑÀÈÌÒÙÂÊÎÔÛÄËÏÖÇ';

    public const SIN_TILDE = 'aeiouunaeiouaeiouaeiocAEIOUUNAEIOUAEIOUAEIOC';

    private function __construct()
    {
    }

    /**
     * Agrega «columna (sin tildes) ILIKE %texto (sin tildes)%» a la consulta.
     * El nombre de columna debe ser fijo en el código, nunca un dato del usuario.
     */
    public static function contiene(Builder $consulta, string $columna, string $texto, string $union = 'and'): Builder
    {
        return $consulta->whereRaw("translate({$columna}, ?, ?) ILIKE ?", [self::CON_TILDE, self::SIN_TILDE, self::patron($texto)], $union);
    }

    /** Quita tildes al texto buscado y escapa los comodines de LIKE para buscarlo tal como lo escribió el usuario. */
    public static function patron(string $texto): string
    {
        $sinTildes = strtr($texto, array_combine(mb_str_split(self::CON_TILDE), mb_str_split(self::SIN_TILDE)));

        return '%'.addcslashes($sinTildes, '%_\\').'%';
    }
}
