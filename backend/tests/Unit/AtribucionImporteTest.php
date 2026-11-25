<?php

namespace Tests\Unit;

use App\Exceptions\NegocioException;
use App\Support\AtribucionImporte;
use PHPUnit\Framework\TestCase;

/**
 * H-43: el valor de varias devoluciones de una línea nunca supera su subtotal y la última se lleva el residuo.
 */
class AtribucionImporteTest extends TestCase
{
    public function test_tres_devoluciones_de_un_tercio_suman_exactamente_el_subtotal(): void
    {
        // Línea de 0,003 kg por Bs 0,05: redondeando cada tercio por separado saldrían 0,02 × 3 = 0,06.
        $primera = AtribucionImporte::calcular(5, 3, 0, 0, 1);
        $segunda = AtribucionImporte::calcular(5, 3, 1, $primera, 1);
        $tercera = AtribucionImporte::calcular(5, 3, 2, $primera + $segunda, 1);

        $this->assertSame([2, 1, 2], [$primera, $segunda, $tercera]);
        $this->assertSame(5, $primera + $segunda + $tercera);
    }

    public function test_la_suma_nunca_supera_el_subtotal_con_cualquier_reparto(): void
    {
        foreach ([[1000, 3000], [9999, 7000], [1, 999000], [3333, 3000], [10001, 13000]] as [$subtotal, $cantidad]) {
            $atribuido = 0;
            $aprobada = 0;
            while ($aprobada < $cantidad) {
                $paso = min(1000, $cantidad - $aprobada);
                $atribuido += AtribucionImporte::calcular($subtotal, $cantidad, $aprobada, $atribuido, $paso);
                $aprobada += $paso;
                $this->assertLessThanOrEqual($subtotal, $atribuido);
            }
            $this->assertSame($subtotal, $atribuido, 'Al devolver toda la línea se atribuye exactamente su subtotal.');
        }
    }

    public function test_redondea_la_mitad_hacia_arriba_como_postgresql(): void
    {
        // 0,05 × 1 / 2 = 0,025 → 0,03 (round() de NUMERIC redondea la mitad hacia arriba).
        $this->assertSame(3, AtribucionImporte::calcular(5, 2000, 0, 0, 1000));
        $this->assertSame(2, AtribucionImporte::calcular(5, 2000, 1000, 3, 1000));
    }

    public function test_no_atribuye_mas_de_la_cantidad_de_la_linea_ni_importes_negativos(): void
    {
        $this->assertSame(7000, AtribucionImporte::calcular(7000, 1000, 0, 0, 5000));
        $this->assertSame(0, AtribucionImporte::calcular(7000, 1000, 1000, 7000, 1000));
        $this->assertSame(0, AtribucionImporte::calcular(7000, 2000, 1000, 7000, 1000));
    }

    public function test_convierte_importes_y_cantidades_sin_errores_de_decimales(): void
    {
        $this->assertSame(1005, AtribucionImporte::centavos('10.05'));
        $this->assertSame(11066, AtribucionImporte::centavos(110.66));
        $this->assertSame(3, AtribucionImporte::milesimas('0.003'));
        $this->assertSame(0, AtribucionImporte::centavos(null));
        $this->assertSame(110.66, AtribucionImporte::bolivianos(11066));
    }

    public function test_rechaza_una_linea_sin_cantidad(): void
    {
        $this->expectException(NegocioException::class);
        AtribucionImporte::calcular(100, 0, 0, 0, 1);
    }
}
