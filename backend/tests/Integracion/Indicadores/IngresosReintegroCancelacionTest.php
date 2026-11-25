<?php

namespace Tests\Integracion\Indicadores;

use Illuminate\Support\Facades\DB;
use Tests\Integracion\Pedidos\PedidosTestCase;

/**
 * «Ingresos cobrados (netos)» del panel también resta los reintegros SIN devolución: los que registra el personal
 * al cancelar un pedido pagado antes del despacho (reembolso.devolucion_id NULL, EJECUTADO).
 * Se contrasta con SQL propio que separa los reintegros con y sin devolución.
 */
class IngresosReintegroCancelacionTest extends PedidosTestCase
{
    private function panel(string $desde, string $hasta): array
    {
        return $this->withToken($this->admin())->getJson("/api/panel/negocio?desde={$desde}&hasta={$hasta}")->assertOk()->json();
    }

    /** Pagos PAGADO, reintegros EJECUTADO sin devolución y con devolución del período, por SQL independiente. */
    private function cajaSql(string $desde, string $hasta, ?string $metodo = null): object
    {
        $filtro = $metodo === null ? '' : ' AND g.metodo = ?';
        $parametros = $metodo === null ? [$desde, $hasta] : [$desde, $hasta, $metodo];

        return DB::selectOne("SELECT
              (SELECT COALESCE(SUM(g.monto), 0) FROM pago g
                WHERE g.estado = 'PAGADO' AND g.fecha_pago::date BETWEEN ? AND ?{$filtro}) AS cobrado,
              (SELECT COALESCE(SUM(r.monto), 0) FROM reembolso r JOIN pago g ON g.id = r.pago_id
                WHERE r.estado = 'EJECUTADO' AND r.devolucion_id IS NULL AND r.fecha_ejecucion::date BETWEEN ? AND ?{$filtro}) AS sin_devolucion,
              (SELECT COALESCE(SUM(r.monto), 0) FROM reembolso r JOIN pago g ON g.id = r.pago_id
                WHERE r.estado = 'EJECUTADO' AND r.devolucion_id IS NOT NULL AND r.fecha_ejecucion::date BETWEEN ? AND ?{$filtro}) AS con_devolucion",
            [...$parametros, ...$parametros, ...$parametros]);
    }

    public function test_cancelar_un_pedido_pagado_resta_su_reintegro_de_los_ingresos_cobrados(): void
    {
        $hoy = (string) DB::selectOne('SELECT current_date::text AS d')->d;
        $antes = $this->panel($hoy, $hoy);

        $producto = $this->crearProducto(['precio_venta' => '45.00']);
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$producto => 4]);
        $pagado = $this->panel($hoy, $hoy)['indicadores'];
        $this->assertEqualsWithDelta($antes['indicadores']['ventas'] + 180, $pagado['ventas'], 0.001, 'El pago entra en los ingresos.');

        $this->cancelarComoPersonal($this->personal(), $pedido, 'Prueba de ingresos netos')->assertOk();
        $reintegro = $this->reembolsos($pedido)->sole();
        $this->assertNull($reintegro->devolucion_id, 'El reintegro por cancelación no tiene devolución.');
        $this->assertSame('EJECUTADO', $reintegro->estado);

        $despues = $this->panel($hoy, $hoy);
        $sql = $this->cajaSql($hoy, $hoy);
        $esperado = (float) $sql->cobrado - (float) $sql->sin_devolucion - (float) $sql->con_devolucion;

        // El SQL demuestra que hay un reintegro sin devolución en el período y que el panel lo descuenta.
        $this->assertGreaterThanOrEqual(180.0, (float) $sql->sin_devolucion);
        $this->assertEqualsWithDelta($esperado, $despues['indicadores']['ventas'], 0.001);
        $this->assertEqualsWithDelta((float) $sql->cobrado, $despues['indicadores']['ventas_brutas'], 0.001);
        $this->assertEqualsWithDelta((float) $sql->sin_devolucion + (float) $sql->con_devolucion, $despues['indicadores']['reintegros'], 0.001);
        // Cobrar y reintegrar lo mismo deja el neto como estaba antes del pedido.
        $this->assertEqualsWithDelta($antes['indicadores']['ventas'], $despues['indicadores']['ventas'], 0.001);
        $this->assertEqualsWithDelta($antes['indicadores']['reintegros'] + 180, $despues['indicadores']['reintegros'], 0.001);

        // Por medio de pago (TARJETA) y por mes, el reintegro sin devolución también resta.
        $tarjeta = collect($despues['pagos_por_metodo'])->firstWhere('metodo', 'TARJETA');
        $sqlTarjeta = $this->cajaSql($hoy, $hoy, 'TARJETA');
        $this->assertEqualsWithDelta((float) $sqlTarjeta->cobrado, $tarjeta['cobrado'], 0.001);
        $this->assertEqualsWithDelta((float) $sqlTarjeta->sin_devolucion + (float) $sqlTarjeta->con_devolucion, $tarjeta['reintegrado'], 0.001);
        $this->assertEqualsWithDelta(
            (float) $sqlTarjeta->cobrado - (float) $sqlTarjeta->sin_devolucion - (float) $sqlTarjeta->con_devolucion, $tarjeta['monto'], 0.001);
        $this->assertEqualsWithDelta($despues['indicadores']['ventas'], array_sum(array_column($despues['ventas_por_mes'], 'total')), 0.001);
    }

    public function test_un_periodo_con_solo_el_reintegro_de_una_cancelacion_tiene_ingresos_negativos(): void
    {
        // Semilla: PED-2026-0003 se cobró por QR el 03/07/2026 y se canceló con reintegro sin devolución el 06/07/2026.
        $sql = $this->cajaSql('2026-07-04', '2026-07-31');
        $this->assertEqualsWithDelta(0.0, (float) $sql->cobrado, 0.001);
        $this->assertEqualsWithDelta(76.0, (float) $sql->sin_devolucion, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $sql->con_devolucion, 0.001);

        $panel = $this->panel('2026-07-04', '2026-07-31');

        $this->assertEqualsWithDelta(-76.0, $panel['indicadores']['ventas'], 0.001);
        $this->assertEqualsWithDelta(0.0, $panel['indicadores']['ventas_brutas'], 0.001);
        $this->assertEqualsWithDelta(76.0, $panel['indicadores']['reintegros'], 0.001);
        // El medio QR aparece con su neto negativo (la interfaz lo muestra; ya no dice «Sin registros»).
        $this->assertSame([['QR', -76.0, 0.0, 76.0]], collect($panel['pagos_por_metodo'])
            ->map(fn ($f) => [$f['metodo'], (float) $f['monto'], (float) $f['cobrado'], (float) $f['reintegrado']])->all());
        $this->assertSame([['2026-07', -76.0]], collect($panel['ventas_por_mes'])->map(fn ($f) => [$f['mes'], (float) $f['total']])->all());
    }
}
