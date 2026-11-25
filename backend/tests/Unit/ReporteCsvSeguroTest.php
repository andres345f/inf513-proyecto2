<?php

namespace Tests\Unit;

use App\Repositories\ReporteRepository;
use App\Services\ReporteService;
use Illuminate\Support\LazyCollection;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * H-45: un texto que empieza por =, +, -, @, tabulación o retorno de carro no debe llegar al CSV tal cual,
 * porque una hoja de cálculo lo interpretaría como fórmula. Los importes y cantidades (también negativos) no se tocan.
 */
class ReporteCsvSeguroTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function filasDeProductos(array $productos): array
    {
        $repositorio = Mockery::mock(ReporteRepository::class);
        $repositorio->shouldReceive('productos')->andReturn(LazyCollection::make($productos));

        return collect((new ReporteService($repositorio))->generar('productos', null, null)['filas'])->values()->all();
    }

    private function producto(array $cambios = []): object
    {
        return (object) array_merge([
            'codigo' => 'FED-001', 'nombre' => 'Miel', 'categoria' => 'Miel del bosque', 'presentacion' => 'Frasco 500 g',
            'unidad_medida' => 'UNIDAD', 'precio_venta' => '45.00', 'stock' => '12.000', 'stock_minimo' => '5.000', 'activo' => true,
        ], $cambios);
    }

    public function test_neutraliza_textos_que_parecen_formulas(): void
    {
        $peligrosos = ['=HYPERLINK("http://x","clic")', '+1+1', '-2+3', '@SUMA(A1)', "\t=1", "\r=1"];
        foreach ($peligrosos as $texto) {
            [$fila] = $this->filasDeProductos([$this->producto(['nombre' => $texto, 'categoria' => $texto, 'presentacion' => $texto])]);

            foreach ([1, 2, 3] as $columna) {
                $this->assertSame("'".$texto, $fila[$columna], 'Debe anteponerse un apóstrofo a: '.json_encode($texto));
            }
        }
    }

    public function test_no_altera_textos_normales_ni_importes(): void
    {
        [$fila] = $this->filasDeProductos([$this->producto(['nombre' => 'Café 250 g', 'precio_venta' => '1234.5'])]);

        $this->assertSame('Café 250 g', $fila[1]);
        $this->assertSame('1.234,50', $fila[5]);
        $this->assertSame('12', $fila[6]);
    }

    public function test_un_importe_negativo_se_conserva_como_numero(): void
    {
        [$fila] = $this->filasDeProductos([$this->producto(['precio_venta' => '-56', 'stock' => '-1.5'])]);

        $this->assertSame('-56,00', $fila[5]);
        $this->assertSame('-1,5', $fila[6]);
    }
}
