<?php

namespace Tests\Integracion\Indicadores;

use Illuminate\Support\Facades\DB;
use Tests\Integracion\IntegracionTestCase;

/**
 * H-21: definiciones de los indicadores del panel de negocio, contrastadas con SQL propio.
 *
 * - Ingresos cobrados (netos), criterio de caja: pagos PAGADO por su fecha de pago, menos reintegros EJECUTADO
 *   por su fecha de ejecución (un pago luego reembolsado no cuenta como ingreso).
 * - Pedido vendido: pedido creado en el período que llegó a confirmarse (CONFIRMADO, EN_PREPARACION,
 *   DESPACHADO o ENTREGADO). No cuentan los pendientes de pago, los encargos sin abastecer ni los cancelados.
 * - Ticket promedio: total medio de los pedidos vendidos.
 * - Más vendidos: líneas de los pedidos vendidos (bruto de devoluciones).
 * - Cobros por medio de pago: netos de los reintegros del pago original.
 */
class IndicadoresPanelTest extends IntegracionTestCase
{
    private const DESDE = '2025-11-25';

    private const HASTA = '2026-11-24';

    private const VENDIDOS = "('CONFIRMADO','EN_PREPARACION','DESPACHADO','ENTREGADO')";

    private function panel(): array
    {
        $token = $this->tokenDe('administracion@feder.example', 'ADMINISTRADOR');

        return $this->withToken($token)->getJson('/api/panel/negocio?desde='.self::DESDE.'&hasta='.self::HASTA)->assertOk()->json();
    }

    private function sqlValor(string $sql): float
    {
        return (float) DB::selectOne($sql, [self::DESDE, self::HASTA, self::DESDE, self::HASTA])->v;
    }

    public function test_ingresos_netos_descuentan_los_reintegros(): void
    {
        $esperado = $this->sqlValor("SELECT
              (SELECT COALESCE(SUM(monto),0) FROM pago WHERE estado='PAGADO' AND fecha_pago::date BETWEEN ? AND ?)
            - (SELECT COALESCE(SUM(monto),0) FROM reembolso WHERE estado='EJECUTADO' AND fecha_ejecucion::date BETWEEN ? AND ?) AS v");
        $panel = $this->panel();

        $this->assertEqualsWithDelta($esperado, $panel['indicadores']['ventas'], 0.001);
        // Datos de demostración: 1.920,50 cobrados − 76,00 (pedido cancelado) − 56,00 (devolución) = 1.788,50.
        $this->assertEqualsWithDelta(1788.50, $panel['indicadores']['ventas'], 0.001);
        $this->assertEqualsWithDelta(1920.50, $panel['indicadores']['ventas_brutas'], 0.001);
        $this->assertEqualsWithDelta(132.00, $panel['indicadores']['reintegros'], 0.001);
    }

    public function test_pedidos_y_ticket_cuentan_solo_pedidos_vendidos(): void
    {
        $fila = DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS t FROM pedido
                                WHERE estado IN '.self::VENDIDOS.' AND fecha_creacion::date BETWEEN ? AND ?', [self::DESDE, self::HASTA]);
        $panel = $this->panel();

        $this->assertSame((int) $fila->n, $panel['indicadores']['pedidos']);
        $this->assertEqualsWithDelta(round((float) $fila->t / (int) $fila->n, 2), $panel['indicadores']['ticket_promedio'], 0.001);
        // Sin el pedido pendiente de pago (PED-2026-0011) ni el encargo sin abastecer (PED-2026-0008): 7 pedidos, Bs 263,50.
        $this->assertSame(7, $panel['indicadores']['pedidos']);
        $this->assertEqualsWithDelta(263.50, $panel['indicadores']['ticket_promedio'], 0.001);
    }

    public function test_un_encargo_sin_pagar_no_entra_en_mas_vendidos(): void
    {
        $esperados = collect(DB::select('SELECT pr.nombre, SUM(d.subtotal) AS monto FROM detalle_pedido d
                JOIN pedido p ON p.id = d.pedido_id JOIN producto pr ON pr.id = d.producto_id
               WHERE p.estado IN '.self::VENDIDOS.' AND p.fecha_creacion::date BETWEEN ? AND ?
               GROUP BY pr.id, pr.nombre ORDER BY SUM(d.subtotal) DESC, pr.nombre LIMIT 5', [self::DESDE, self::HASTA]))
            ->map(fn ($f) => [$f->nombre, round((float) $f->monto, 2)])->all();
        $obtenidos = collect($this->panel()['productos_mas_vendidos'])->map(fn ($f) => [$f['nombre'], round($f['monto'], 2)])->all();

        $this->assertSame($esperados, $obtenidos);
        // El encargo impago de 12 «Aceite de totaí 100 ml» (Bs 1.080) ya no encabeza la lista.
        $this->assertNotContains('Aceite de totaí 100 ml', array_column($obtenidos, 0));
    }

    public function test_un_pedido_nuevo_sin_pagar_no_mueve_los_indicadores(): void
    {
        $antes = $this->panel()['indicadores'];
        $pedidoId = DB::table('pedido')->insertGetId([
            'usuario_id' => DB::table('usuario_rol as ur')->join('rol as r', 'r.id', '=', 'ur.rol_id')->where('r.codigo', 'CLIENTE')->orderBy('ur.usuario_id')->value('ur.usuario_id'),
            'codigo' => 'PED-PRUEBA-H21', 'tipo' => 'INMEDIATO', 'estado' => 'PENDIENTE',
            'fecha_creacion' => self::HASTA.' 12:00:00', 'subtotal' => 500, 'costo_envio' => 0, 'total' => 500, 'clave_idempotencia' => 'prueba-h21-'.uniqid(),
        ]);
        $despues = $this->panel()['indicadores'];

        $this->assertGreaterThan(0, $pedidoId);
        foreach (['ventas', 'pedidos', 'ticket_promedio'] as $clave) {
            $this->assertEquals($antes[$clave], $despues[$clave], "«{$clave}» no debe cambiar con un pedido sin pagar.");
        }
    }

    public function test_cobros_por_medio_de_pago_netos_y_por_mes(): void
    {
        $panel = $this->panel();
        $porMetodo = collect($panel['pagos_por_metodo'])->keyBy('metodo');
        $esperadoQr = $this->sqlValor("SELECT
              (SELECT COALESCE(SUM(monto),0) FROM pago WHERE metodo='QR' AND estado='PAGADO' AND fecha_pago::date BETWEEN ? AND ?)
            - (SELECT COALESCE(SUM(r.monto),0) FROM reembolso r JOIN pago g ON g.id = r.pago_id
                WHERE g.metodo='QR' AND r.estado='EJECUTADO' AND r.fecha_ejecucion::date BETWEEN ? AND ?) AS v");

        $this->assertEqualsWithDelta($esperadoQr, $porMetodo['QR']['monto'], 0.001);
        $this->assertEqualsWithDelta(951.50, $porMetodo['QR']['monto'], 0.001);
        $this->assertEqualsWithDelta(
            $panel['indicadores']['ventas'],
            array_sum(array_column($panel['ventas_por_mes'], 'total')),
            0.001,
            'La suma de los meses debe coincidir con los ingresos netos.'
        );
    }
}
