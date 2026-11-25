<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Repositories\ReporteRepository;
use Carbon\CarbonImmutable;

/**
 * Reportes descargables en CSV (CU8). Separador ";" y BOM UTF-8 para que Excel en español los abra bien.
 */
class ReporteService
{
    public const TIPOS = ['ventas', 'pedidos', 'productos'];

    private const ESTADOS = [
        'PENDIENTE' => 'Pendiente de pago', 'ESPERANDO_ABASTECIMIENTO' => 'Esperando abastecimiento', 'CONFIRMADO' => 'Confirmado',
        'EN_PREPARACION' => 'En preparación', 'DESPACHADO' => 'Despachado', 'ENTREGADO' => 'Entregado', 'CANCELADO' => 'Cancelado',
    ];

    private const METODOS = ['QR' => 'QR', 'TARJETA' => 'Tarjeta', 'EFECTIVO' => 'Efectivo'];

    public function __construct(private readonly ReporteRepository $reportes)
    {
    }

    /**
     * Cada celda de texto pasa por celdaSegura() (H-45): un valor que una hoja de cálculo tomaría
     * como fórmula se exporta como texto.
     *
     * @return array{nombre:string, encabezados:list<string>, filas:iterable}
     */
    public function generar(string $tipo, ?string $desde, ?string $hasta): array
    {
        $reporte = $this->construir($tipo, $desde, $hasta);
        $reporte['filas'] = $reporte['filas']->map(fn (array $fila) => array_map(fn ($celda) => $this->celdaSegura($celda), $fila));

        return $reporte;
    }

    /**
     * Antepone un apóstrofo a los textos que empiezan por =, +, -, @, tabulación o retorno de carro.
     * Los importes y cantidades que formatea este servicio (1.234,50 · -56,00 · 3,5) se dejan como números.
     */
    public function celdaSegura(mixed $valor): mixed
    {
        if (! is_string($valor) || $valor === '' || preg_match(self::NUMERO_FORMATEADO, $valor) === 1) {
            return $valor;
        }

        return preg_match('/^[=+\-@\t\r]/', $valor) === 1 ? "'".$valor : $valor;
    }

    /** Número con separador de miles «.» y decimales «,», opcionalmente negativo. */
    private const NUMERO_FORMATEADO = '/^-?\d{1,3}(?:\.\d{3})*(?:,\d+)?$/';

    /** @return array{nombre:string, encabezados:list<string>, filas:\Illuminate\Support\Enumerable} */
    private function construir(string $tipo, ?string $desde, ?string $hasta): array
    {
        $fin = $hasta ? CarbonImmutable::parse($hasta) : CarbonImmutable::today();
        $inicio = $desde ? CarbonImmutable::parse($desde) : $fin->startOfMonth();
        [$d, $h] = [$inicio->toDateString(), $fin->toDateString()];

        return match ($tipo) {
            'ventas' => [
                'nombre' => "ventas_{$d}_{$h}.csv",
                'encabezados' => ['Fecha de pago', 'Pedido', 'Cliente', 'Medio de pago', 'Monto (Bs)', 'Referencia'],
                'filas' => $this->reportes->ventas($d, $h)->map(fn ($r) => [
                    $this->fecha($r->fecha_pago), $r->codigo, $r->cliente, self::METODOS[$r->metodo] ?? $r->metodo, $this->monto($r->monto), $r->referencia_externa,
                ]),
            ],
            'pedidos' => [
                'nombre' => "pedidos_{$d}_{$h}.csv",
                'encabezados' => ['Pedido', 'Fecha', 'Cliente', 'Tipo', 'Estado', 'Entrega', 'Subtotal (Bs)', 'Envío (Bs)', 'Total (Bs)', 'Pagado (Bs)'],
                'filas' => $this->reportes->pedidos($d, $h)->map(fn ($r) => [
                    $r->codigo, $this->fecha($r->fecha_creacion), $r->cliente, $r->tipo === 'INMEDIATO' ? 'Inmediato' : 'Encargo',
                    self::ESTADOS[$r->estado] ?? $r->estado, $r->modalidad === 'DELIVERY' ? 'Envío a domicilio' : 'Recojo',
                    $this->monto($r->subtotal), $this->monto($r->costo_envio), $this->monto($r->total), $this->monto($r->pagado),
                ]),
            ],
            'productos' => [
                'nombre' => 'productos_'.CarbonImmutable::today()->toDateString().'.csv',
                'encabezados' => ['Código', 'Producto', 'Categoría', 'Presentación', 'Unidad', 'Precio (Bs)', 'Stock disponible', 'Stock mínimo', 'Estado'],
                'filas' => $this->reportes->productos()->map(fn ($r) => [
                    $r->codigo, $r->nombre, $r->categoria, $r->presentacion, ucfirst(strtolower($r->unidad_medida)), $this->monto($r->precio_venta),
                    $this->cantidad($r->stock), $this->cantidad($r->stock_minimo), $r->activo ? 'A la venta' : 'Retirado',
                ]),
            ],
            default => throw new NegocioException('Ese reporte no existe.', 404),
        };
    }

    private function fecha(?string $valor): string
    {
        return $valor ? CarbonImmutable::parse($valor)->format('d/m/Y H:i') : '';
    }

    private function monto($valor): string
    {
        return number_format((float) $valor, 2, ',', '.');
    }

    private function cantidad($valor): string
    {
        return rtrim(rtrim(number_format((float) $valor, 3, ',', '.'), '0'), ',');
    }
}
