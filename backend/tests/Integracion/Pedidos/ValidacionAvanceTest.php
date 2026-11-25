<?php

namespace Tests\Integracion\Pedidos;

use Illuminate\Support\Facades\DB;

/**
 * H-40 (parte de pedidos y entregas): la validación de «avanzar» vive en un FormRequest común a las dos rutas,
 * con mensajes en español. Incluye el despacho a domicilio, que exige indicar quién lleva el pedido.
 */
class ValidacionAvanceTest extends PedidosTestCase
{
    private function pedidoADomicilio(): array
    {
        $producto = $this->crearProducto();
        $lote = $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::CLIENTE, [$producto => 2], [
            'modalidad' => 'DELIVERY', 'direccion' => 'Calle Libertad 123', 'lat' => -17.79, 'lng' => -63.18,
        ]);
        $this->avanzarHasta($pedido, 'EN_PREPARACION');

        return [$pedido, $lote];
    }

    public function test_las_dos_rutas_validan_los_mismos_campos_con_mensajes_en_espanol(): void
    {
        [$pedido] = $this->pedidoADomicilio();

        foreach (['pedidos', 'entregas'] as $ruta) {
            $respuesta = $this->avanzar($this->personal(), $pedido, [
                'hacia' => 'ENVIADO', 'responsable_entrega' => 'Al', 'telefono_responsable' => str_repeat('7', 21), 'efectivo_confirmado' => 'quizá',
            ], $ruta);

            $respuesta->assertStatus(422)->assertJsonValidationErrors(['hacia', 'responsable_entrega', 'telefono_responsable', 'efectivo_confirmado']);
            $this->assertSame('Los datos enviados no son válidos. Revisa los campos marcados.', $respuesta->json('message'));
            $this->assertStringNotContainsString('The ', json_encode($respuesta->json('errors'), JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame('EN_PREPARACION', $this->pedido($pedido)->estado);
    }

    public function test_el_despacho_a_domicilio_exige_indicar_quien_lleva_el_pedido(): void
    {
        [$pedido, $lote] = $this->pedidoADomicilio();

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'DESPACHADO'])->assertStatus(422);
        $this->assertSame('EN_PREPARACION', $this->pedido($pedido)->estado);
        $this->assertSame('10.000', $this->lote($lote)->cantidad_disponible);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'DESPACHADO', 'responsable_entrega' => 'Luis Rojas', 'telefono_responsable' => '70011223'])
            ->assertOk()->assertJsonPath('entrega.responsable_entrega', 'Luis Rojas');
        $this->assertSame('EN_CAMINO', $this->entrega($pedido)->estado);
        $this->assertSame('8.000', $this->lote($lote)->cantidad_disponible);
    }

    public function test_un_despacho_a_domicilio_rechazado_no_deja_la_asignacion_del_responsable(): void
    {
        [$pedido, $lote] = $this->pedidoADomicilio();
        DB::update("UPDATE lote_feder SET estado = 'BLOQUEADO' WHERE id = ?", [$lote]);
        $asignaciones = fn () => DB::table('confirmacion_operacion')->where('comando', 'ASIGNACION_ENTREGA')
            ->whereRaw("(parametros->>'pedido_id')::bigint = ?", [$pedido])->count();

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'DESPACHADO', 'responsable_entrega' => 'Luis Rojas'])->assertStatus(409);

        $this->assertSame(0, $asignaciones(), 'Todo o nada: sin despacho no queda responsable asignado.');
        $this->assertSame('PENDIENTE', $this->entrega($pedido)->estado);
    }

    public function test_cancelar_exige_un_motivo(): void
    {
        [$pedido] = $this->pedidoADomicilio();

        $this->withToken($this->personal())->postJson("/api/gestion/pedidos/{$pedido}/cancelar", ['motivo' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->assertSame('EN_PREPARACION', $this->pedido($pedido)->estado);
    }
}
