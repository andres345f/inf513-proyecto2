<?php

namespace Tests\Integracion\Datos;

use App\Services\CobroPedidoService;
use Illuminate\Support\Facades\DB;
use Tests\Integracion\Pedidos\PedidosTestCase;

/**
 * Coherencia de los datos iniciales (db/02_datos_dominio.sql) con el flujo de pedidos de la web.
 *
 * En la web un encargo pasa de PENDIENTE a ESPERANDO_ABASTECIMIENTO solo cuando se confirma un pago o cuando el
 * cliente elige efectivo contra entrega (PagoService::confirmarPedido; PROMPT_FEDER_WEB 7.2 y 7.4). Los datos de
 * demostración no deben tener un encargo en espera que la propia aplicación no podría haber producido
 * (revisión intermedia del 24/11/2026, hallazgo 6: el pedido 8 esperaba abastecimiento sin pagos).
 */
class EncargoDatosInicialesTest extends PedidosTestCase
{
    public function test_ningun_encargo_espera_abastecimiento_sin_pago_ni_efectivo_contra_entrega(): void
    {
        $encargos = DB::select("SELECT p.id, p.codigo, p.total,
                   COALESCE((SELECT sum(g.monto) FROM pago g WHERE g.pedido_id = p.id AND g.estado = 'PAGADO'), 0) AS pagado,
                   (SELECT g.monto FROM pago g WHERE g.pedido_id = p.id AND g.estado = 'PENDIENTE' AND g.metodo = 'EFECTIVO') AS efectivo,
                   (SELECT min(g.fecha_registro) FROM pago g
                     WHERE g.pedido_id = p.id AND (g.estado = 'PAGADO' OR (g.estado = 'PENDIENTE' AND g.metodo = 'EFECTIVO'))) AS primer_pago,
                   (SELECT max(h.fecha) FROM historial_pedido h
                     WHERE h.pedido_id = p.id AND h.estado_nuevo = 'ESPERANDO_ABASTECIMIENTO') AS en_espera_desde
              FROM pedido p
             WHERE p.tipo = 'ENCARGO' AND p.estado = 'ESPERANDO_ABASTECIMIENTO'
             ORDER BY p.id");

        $this->assertNotEmpty($encargos, 'Los datos iniciales incluyen un encargo esperando abastecimiento (pedido 8).');
        foreach ($encargos as $e) {
            $saldo = CobroPedidoService::centavos($e->total) - CobroPedidoService::centavos($e->pagado);
            $conEfectivo = $e->efectivo !== null && CobroPedidoService::centavos($e->efectivo) === $saldo;

            $this->assertTrue((float) $e->pagado > 0 || $conEfectivo,
                "El encargo {$e->codigo} espera abastecimiento sin un pago confirmado ni efectivo contra entrega por el saldo.");
            $this->assertNotNull($e->en_espera_desde, "El encargo {$e->codigo} no tiene en su historial el paso a la espera.");
            $this->assertLessThanOrEqual(strtotime($e->en_espera_desde), strtotime($e->primer_pago),
                "El encargo {$e->codigo} pasó a esperar abastecimiento antes de registrarse su pago.");
        }
    }

    public function test_el_encargo_8_se_abastece_prepara_despacha_y_entrega_cobrando_el_efectivo(): void
    {
        // Llegan las 12 botellas del producto 2 que espera el encargo 8 (recojo en FEDER, Bs 1080 en efectivo).
        $this->crearLoteFeder(2, 12, '2027-11-05', '2026-11-20 09:00:00');
        $this->abastecer($this->personal(), 8)->assertOk()->assertJsonPath('estado', 'CONFIRMADO');

        $this->avanzarHasta(8, 'EN_PREPARACION', 'DESPACHADO');
        $this->assertSame('LISTA_PARA_RECOJO', $this->entrega(8)->estado);
        $this->assertEqualsWithDelta(12.0, (float) $this->salidas(8)->sum('cantidad'), 0.0001);

        // Sin confirmar que se recibió el efectivo no se entrega ni se cobra.
        $this->avanzar($this->personal(), 8, ['hacia' => 'ENTREGADO'])->assertStatus(422);
        $this->assertSame('DESPACHADO', $this->pedido(8)->estado);
        $this->assertSame('PENDIENTE', $this->pagos(8)->sole()->estado);

        $entregado = $this->avanzar($this->personal(), 8, ['hacia' => 'ENTREGADO', 'efectivo_confirmado' => true]);
        $entregado->assertOk()->assertJsonPath('estado', 'ENTREGADO');

        $pago = $this->pagos(8)->sole();
        $this->assertSame(['EFECTIVO', 'PAGADO', '1080.00'], [$pago->metodo, $pago->estado, $pago->monto]);
        $this->assertNotNull($pago->fecha_pago);
        $this->assertSame('ENTREGADA', $this->entrega(8)->estado);
        $this->assertSame('1080.00', DB::table('factura')->where('pedido_id', 8)->value('total'));
    }

}
