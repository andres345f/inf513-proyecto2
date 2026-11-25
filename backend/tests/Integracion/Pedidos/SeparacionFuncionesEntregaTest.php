<?php

namespace Tests\Integracion\Pedidos;

use Illuminate\Support\Facades\DB;

/**
 * H-07 (parte de entregas): quien es personal y también cliente no cobra el efectivo de su propio pedido.
 * La regla mira la cuenta (usuario_id), no el rol activo; preparar o despachar un pedido propio sí está permitido.
 */
class SeparacionFuncionesEntregaTest extends PedidosTestCase
{
    private function pedidoPropioConEfectivoDespachado(): int
    {
        $producto = $this->crearProducto(['precio_venta' => '60.00']);
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoDe(self::PERSONAL_Y_CLIENTE, [$producto => 2]);
        $this->pagarEnEfectivo(self::PERSONAL_Y_CLIENTE, $pedido)->assertOk();

        // La misma cuenta, con su rol de personal, sí puede preparar y despachar su pedido.
        $comoPersonal = $this->tokenDe(self::PERSONAL_Y_CLIENTE, 'PERSONAL_FEDER');
        $this->avanzar($comoPersonal, $pedido, ['hacia' => 'EN_PREPARACION'])->assertOk();
        $this->avanzar($comoPersonal, $pedido, ['hacia' => 'DESPACHADO'])->assertOk();

        return $pedido;
    }

    public function test_el_personal_no_cobra_el_efectivo_de_su_propio_pedido(): void
    {
        $pedido = $this->pedidoPropioConEfectivoDespachado();
        $antes = $this->fotoInventario($pedido);

        $respuesta = $this->avanzar($this->tokenDe(self::PERSONAL_Y_CLIENTE, 'PERSONAL_FEDER'), $pedido,
            ['hacia' => 'ENTREGADO', 'efectivo_confirmado' => true]);

        $respuesta->assertStatus(403);
        $this->assertStringContainsString('cobrar el efectivo de tu propio pedido', $respuesta->json('message'));
        $this->assertSame($antes, $this->fotoInventario($pedido));
        $this->assertSame('PENDIENTE', $this->pagos($pedido)[0]->estado);
        $this->assertSame('DESPACHADO', $this->pedido($pedido)->estado);
        $this->assertSame(0, DB::table('factura')->where('pedido_id', $pedido)->count());
    }

    public function test_tampoco_por_la_ruta_de_entregas(): void
    {
        $pedido = $this->pedidoPropioConEfectivoDespachado();

        $this->avanzar($this->tokenDe(self::PERSONAL_Y_CLIENTE, 'PERSONAL_FEDER'), $pedido,
            ['hacia' => 'ENTREGADO', 'efectivo_confirmado' => true], 'entregas')->assertStatus(403);

        $this->assertSame('PENDIENTE', $this->pagos($pedido)[0]->estado);
    }

    public function test_con_su_rol_de_cliente_la_misma_cuenta_no_entra_a_la_gestion(): void
    {
        $pedido = $this->pedidoPropioConEfectivoDespachado();

        $this->avanzar($this->tokenDe(self::PERSONAL_Y_CLIENTE, 'CLIENTE'), $pedido,
            ['hacia' => 'ENTREGADO', 'efectivo_confirmado' => true])->assertStatus(403);

        $this->assertSame('DESPACHADO', $this->pedido($pedido)->estado);
        $this->assertSame('PENDIENTE', $this->pagos($pedido)[0]->estado);
    }

    public function test_otro_miembro_del_personal_si_cobra_y_entrega(): void
    {
        $pedido = $this->pedidoPropioConEfectivoDespachado();

        $this->avanzar($this->personal(), $pedido, ['hacia' => 'ENTREGADO', 'efectivo_confirmado' => true])->assertOk();

        $this->assertSame('PAGADO', $this->pagos($pedido)[0]->estado);
        $this->assertSame('ENTREGADO', $this->pedido($pedido)->estado);
        $fila = $this->bitacora('PAGO', (int) $this->pagos($pedido)[0]->id)->last();
        $this->assertSame($this->idDe(self::PERSONAL), (int) $fila->usuario_id, 'Queda quién recibió el efectivo.');
    }

    public function test_el_personal_si_entrega_su_propio_pedido_cuando_ya_esta_pagado_por_completo(): void
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 10, null, '2026-10-01 09:00:00');
        $pedido = $this->pedidoPagado(self::PERSONAL_Y_CLIENTE, [$producto => 1]);
        $comoPersonal = $this->tokenDe(self::PERSONAL_Y_CLIENTE, 'PERSONAL_FEDER');

        foreach (['EN_PREPARACION', 'DESPACHADO', 'ENTREGADO'] as $estado) {
            $this->avanzar($comoPersonal, $pedido, ['hacia' => $estado])->assertOk();
        }

        $this->assertSame('ENTREGADO', $this->pedido($pedido)->estado, 'No hay dinero que cobrar: la separación de funciones no aplica.');
    }
}
