<?php

namespace Tests\Integracion\Pedidos;

use Illuminate\Support\Facades\DB;

/**
 * H-01: recorrido completo de un ENCARGO con PostgreSQL real.
 * Cliente sin stock → encargo → pago → ESPERANDO_ABASTECIMIENTO → llega stock (compra y recepción reales) →
 * abastecer → CONFIRMADO con reservas sin vencimiento por la cantidad exacta → preparar → despachar → entregar.
 */
class EncargoAbastecimientoTest extends PedidosTestCase
{
    public function test_recorrido_completo_del_encargo_pagado_con_tarjeta(): void
    {
        $producto = $this->crearProducto(['permite_encargo' => true, 'precio_venta' => '40.00']);
        $loteOrigen = $this->crearLoteOrigen($producto, 20, '2028-06-30');

        // El cliente no encuentra stock y lo pide como encargo.
        $this->crearPedido(self::CLIENTE, [$producto => 5])->assertStatus(422);
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 5], ['tipo' => 'ENCARGO']);
        $this->assertSame('PENDIENTE', $this->pedido($pedido)->estado);
        $this->assertCount(0, $this->reservas($pedido), 'Un encargo no reserva stock al crearse.');

        $this->pagarConTarjeta(self::CLIENTE, $pedido)->assertOk()->assertJsonPath('resultado', 'PAGADO');
        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido($pedido)->estado);

        // Sin stock todavía: abastecer responde 409 y no cambia nada.
        $this->abastecer($this->personal(), $pedido)->assertStatus(409);
        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido($pedido)->estado);

        // Llega el producto: compra al productor y recepción en almacén, por la API del personal.
        $lote = $this->comprarYRecibir($loteOrigen, 8);
        $this->assertSame('8.000', $this->lote($lote)->cantidad_disponible);
        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado,
            'La recepción confirmada abastece el encargo automáticamente.');
        $this->assertNull($this->historial($pedido)->firstWhere('estado_nuevo', 'CONFIRMADO')->usuario_id);

        // Abastecer: reservas sin vencimiento por la cantidad exacta y pedido CONFIRMADO.
        $this->abastecer($this->personal(), $pedido)->assertOk()->assertJsonPath('estado', 'CONFIRMADO');
        $reservas = $this->reservas($pedido);
        $this->assertCount(1, $reservas);
        $this->assertSame('ACTIVA', $reservas[0]->estado);
        $this->assertSame('5.000', $reservas[0]->cantidad);
        $this->assertSame($lote, (int) $reservas[0]->lote_feder_id);
        $this->assertNull($reservas[0]->fecha_expiracion, 'La reserva de un encargo abastecido no vence.');
        $this->assertSame('5.000', $this->lote($lote)->cantidad_reservada);
        $this->assertSame(1, DB::table('notificacion')->where('pedido_id', $pedido)->where('tipo', 'ENCARGO_ABASTECIDO')->count());

        // Preparar, despachar (con su SALIDA_VENTA) y entregar.
        $this->avanzarHasta($pedido, 'EN_PREPARACION', 'DESPACHADO');
        $salidas = $this->salidas($pedido);
        $this->assertCount(1, $salidas);
        $this->assertSame('5.000', $salidas[0]->cantidad);
        $this->assertSame((int) $reservas[0]->id, (int) $salidas[0]->reserva_id);
        $this->assertSame('CONSUMIDA', $this->reservas($pedido)[0]->estado);
        $this->assertSame('3.000', $this->lote($lote)->cantidad_disponible);
        $this->assertSame('0.000', $this->lote($lote)->cantidad_reservada);
        $this->assertSame('LISTA_PARA_RECOJO', $this->entrega($pedido)->estado);

        $this->avanzarHasta($pedido, 'ENTREGADO');
        $this->assertSame('ENTREGADA', $this->entrega($pedido)->estado);
        $this->assertSame(
            ['PENDIENTE', 'ESPERANDO_ABASTECIMIENTO', 'CONFIRMADO', 'EN_PREPARACION', 'DESPACHADO', 'ENTREGADO'],
            $this->historial($pedido)->pluck('estado_nuevo')->all()
        );
        $this->assertSame(1, DB::table('factura')->where('pedido_id', $pedido)->count());
    }

    public function test_recorrido_del_encargo_con_efectivo_contra_entrega(): void
    {
        $producto = $this->crearProducto(['permite_encargo' => true, 'precio_venta' => '30.00']);
        $loteOrigen = $this->crearLoteOrigen($producto, 10);

        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 4], ['tipo' => 'ENCARGO']);
        $this->pagarEnEfectivo(self::CLIENTE, $pedido)->assertOk()->assertJsonPath('resultado', 'PENDIENTE');
        $this->assertSame('ESPERANDO_ABASTECIMIENTO', $this->pedido($pedido)->estado);

        $lote = $this->comprarYRecibir($loteOrigen, 4);
        $this->abastecer($this->personal(), $pedido)->assertOk()->assertJsonPath('estado', 'CONFIRMADO');
        $this->assertNull($this->reservas($pedido)[0]->fecha_expiracion);

        $this->avanzarHasta($pedido, 'EN_PREPARACION', 'DESPACHADO');
        $this->assertSame('AGOTADO', $this->lote($lote)->estado, 'El lote queda agotado al salir sus 4 unidades.');

        // El efectivo se cobra al entregar: sin confirmación no se entrega.
        $this->avanzar($this->personal(), $pedido, ['hacia' => 'ENTREGADO'])->assertStatus(422);
        $this->assertSame('DESPACHADO', $this->pedido($pedido)->estado);

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'ENTREGADO', 'efectivo_confirmado' => true])
            ->assertOk()->assertJsonPath('estado', 'ENTREGADO');
        $pago = $this->pagos($pedido)[0];
        $this->assertSame('PAGADO', $pago->estado);
        $this->assertSame('120.00', $pago->monto);
        $this->assertSame(1, DB::table('factura')->where('pedido_id', $pedido)->count());
    }

    public function test_abastecer_dos_veces_no_duplica_las_reservas(): void
    {
        $producto = $this->crearProducto(['permite_encargo' => true]);
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 3], ['tipo' => 'ENCARGO']);
        $this->pagarConTarjeta(self::CLIENTE, $pedido)->assertOk();
        $lote = $this->crearLoteFeder($producto, 10, null, '2026-11-01 09:00:00');

        $this->abastecer($this->personal(), $pedido)->assertOk();
        $this->abastecer($this->personal(), $pedido)->assertOk();

        $this->assertCount(1, $this->reservas($pedido));
        $this->assertSame('3.000', $this->lote($lote)->cantidad_reservada);
    }

    public function test_abastecer_reparte_un_encargo_entre_lotes_y_no_reserva_de_mas(): void
    {
        $producto = $this->crearProducto(['permite_encargo' => true]);
        $pedido = $this->pedidoDe(self::CLIENTE, [$producto => 7], ['tipo' => 'ENCARGO']);
        $this->pagarConTarjeta(self::CLIENTE, $pedido)->assertOk();
        $tarde = $this->crearLoteFeder($producto, 5, '2028-01-01', '2026-10-01 09:00:00');
        $pronto = $this->crearLoteFeder($producto, 4, $this->enDias(30), '2026-11-01 09:00:00');

        $this->abastecer($this->personal(), $pedido)->assertOk()->assertJsonPath('estado', 'CONFIRMADO');

        $reservas = $this->reservas($pedido)->keyBy('lote_feder_id');
        $this->assertSame('4.000', $reservas[$pronto]->cantidad, 'Primero el lote que vence antes (FEFO).');
        $this->assertSame('3.000', $reservas[$tarde]->cantidad);
        $this->assertSame(7.0, (float) $this->reservas($pedido)->sum('cantidad'));
    }
}
