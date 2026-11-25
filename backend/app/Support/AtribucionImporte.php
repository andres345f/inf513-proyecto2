<?php

namespace App\Support;

use App\Exceptions\NegocioException;

/**
 * Valor que corresponde a una devolución dentro de su línea de pedido (reglas R018 «Redondeo monetario» y R020
 * «Atribución fija», como com.feder.negocio.posventa.AtribucionImporte del Proyecto 1).
 *
 * No se redondea cada devolución por separado: se redondea el acumulado de la línea y se resta lo ya atribuido.
 * Así la suma de todas las devoluciones nunca supera el subtotal de la línea y la última se lleva el residuo.
 *
 *   valor = max(0, min(round(subtotal × acumulada / cantidad, 2) − atribuido, subtotal − atribuido))
 *
 * Todo se calcula con enteros (centavos y milésimas de unidad) para no depender de decimales binarios.
 */
final class AtribucionImporte
{
    private function __construct()
    {
    }

    /**
     * @param  int  $subtotalCentavos  detalle_pedido.subtotal
     * @param  int  $cantidadMilesimas  detalle_pedido.cantidad
     * @param  int  $aprobadaAnteriorMilesimas  cantidad ya aprobada con reembolso en la línea
     * @param  int  $atribuidoCentavos  valor ya atribuido a esas devoluciones
     * @param  int  $aprobadaNuevaMilesimas  cantidad que se aprueba ahora
     */
    public static function calcular(int $subtotalCentavos, int $cantidadMilesimas, int $aprobadaAnteriorMilesimas,
        int $atribuidoCentavos, int $aprobadaNuevaMilesimas): int
    {
        if ($cantidadMilesimas <= 0) {
            throw new NegocioException('La línea del pedido no tiene una cantidad válida.');
        }

        $acumulada = min($aprobadaAnteriorMilesimas + $aprobadaNuevaMilesimas, $cantidadMilesimas);
        $producto = $subtotalCentavos * $acumulada;
        if (! is_int($producto) || $producto > intdiv(PHP_INT_MAX, 2) - $cantidadMilesimas) {
            throw new NegocioException('El importe de la línea es demasiado grande para calcular su devolución.');
        }

        // Redondeo «mitad hacia arriba», igual que round() de NUMERIC en PostgreSQL (ck_detalle_pedido_subtotal).
        $proporcional = intdiv(2 * $producto + $cantidadMilesimas, 2 * $cantidadMilesimas);

        return max(0, min($proporcional - $atribuidoCentavos, $subtotalCentavos - $atribuidoCentavos));
    }

    /** Bs con dos decimales → centavos enteros. */
    public static function centavos(float|int|string|null $monto): int
    {
        return (int) round((float) $monto * 100);
    }

    /** Cantidad con tres decimales → milésimas enteras. */
    public static function milesimas(float|int|string|null $cantidad): int
    {
        return (int) round((float) $cantidad * 1000);
    }

    /** Centavos enteros → Bs como número con dos decimales. */
    public static function bolivianos(int $centavos): float
    {
        return round($centavos / 100, 2);
    }
}
