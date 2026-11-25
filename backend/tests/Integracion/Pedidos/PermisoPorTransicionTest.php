<?php

namespace Tests\Integracion\Pedidos;

use Illuminate\Support\Facades\DB;

/**
 * H-16: cada transición exige su permiso, sea cual sea la ruta usada.
 * Preparar y despachar → pedidos.gestionar. Entregar y cobrar el efectivo → entregas.gestionar.
 * PERSONAL_FEDER y ADMINISTRADOR tienen los dos permisos, así que sus pantallas no cambian.
 */
class PermisoPorTransicionTest extends PedidosTestCase
{
    private function pedidoConfirmado(): int
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 20, null, '2026-10-01 09:00:00');

        return $this->pedidoPagado(self::CLIENTE, [$producto => 2]);
    }

    public function test_con_los_dos_permisos_ambas_rutas_hacen_todas_las_transiciones(): void
    {
        $porPedidos = $this->pedidoConfirmado();
        $porEntregas = $this->pedidoConfirmado();

        foreach (['EN_PREPARACION', 'DESPACHADO', 'ENTREGADO'] as $estado) {
            $this->avanzar($this->personal(), $porPedidos, ['hacia' => $estado], 'pedidos')->assertOk();
            $this->avanzar($this->admin(), $porEntregas, ['hacia' => $estado], 'entregas')->assertOk();
        }

        $this->assertSame('ENTREGADO', $this->pedido($porPedidos)->estado);
        $this->assertSame('ENTREGADO', $this->pedido($porEntregas)->estado);
    }

    public function test_sin_pedidos_gestionar_la_ruta_de_entregas_no_prepara_ni_despacha(): void
    {
        $confirmado = $this->pedidoConfirmado();
        $enPreparacion = $this->pedidoConfirmado();
        $this->avanzarHasta($enPreparacion, 'EN_PREPARACION');
        $despachado = $this->pedidoConfirmado();
        $this->avanzarHasta($despachado, 'EN_PREPARACION', 'DESPACHADO');
        $this->quitarPermiso('PERSONAL_FEDER', 'pedidos.gestionar');

        $preparar = $this->avanzar($this->personal(), $confirmado, ['hacia' => 'EN_PREPARACION'], 'entregas');
        $despachar = $this->avanzar($this->personal(), $enPreparacion, ['hacia' => 'DESPACHADO'], 'entregas');

        $preparar->assertStatus(403);
        $despachar->assertStatus(403);
        $this->assertSame('CONFIRMADO', $this->pedido($confirmado)->estado);
        $this->assertSame('EN_PREPARACION', $this->pedido($enPreparacion)->estado);
        $this->assertCount(0, $this->salidas($enPreparacion));

        // Lo que sí corresponde a entregas.gestionar sigue funcionando.
        $this->avanzar($this->personal(), $despachado, ['hacia' => 'ENTREGADO'], 'entregas')->assertOk();
        $this->assertSame('ENTREGADO', $this->pedido($despachado)->estado);
        // Y la ruta de pedidos queda cerrada por el permiso de la ruta.
        $this->avanzar($this->personal(), $confirmado, ['hacia' => 'EN_PREPARACION'], 'pedidos')->assertStatus(403);
    }

    public function test_sin_entregas_gestionar_la_ruta_de_pedidos_no_entrega_ni_cobra_el_efectivo(): void
    {
        $producto = $this->crearProducto();
        $this->crearLoteFeder($producto, 20, null, '2026-10-01 09:00:00');
        $conEfectivo = $this->pedidoDe(self::CLIENTE, [$producto => 2]);
        $this->pagarEnEfectivo(self::CLIENTE, $conEfectivo)->assertOk();
        $this->avanzarHasta($conEfectivo, 'EN_PREPARACION', 'DESPACHADO');
        $confirmado = $this->pedidoConfirmado();
        $this->quitarPermiso('PERSONAL_FEDER', 'entregas.gestionar');

        $entregar = $this->avanzar($this->personal(), $conEfectivo, ['hacia' => 'ENTREGADO', 'efectivo_confirmado' => true], 'pedidos');

        $entregar->assertStatus(403);
        $this->assertSame('DESPACHADO', $this->pedido($conEfectivo)->estado);
        $this->assertSame('PENDIENTE', $this->pagos($conEfectivo)[0]->estado, 'El efectivo no se da por cobrado.');
        $this->assertSame(0, DB::table('factura')->where('pedido_id', $conEfectivo)->count());

        // Lo que sí corresponde a pedidos.gestionar sigue funcionando.
        $this->avanzar($this->personal(), $confirmado, ['hacia' => 'EN_PREPARACION'], 'pedidos')->assertOk();
        $this->avanzar($this->personal(), $confirmado, ['hacia' => 'DESPACHADO'], 'pedidos')->assertOk();
        // Y la ruta de entregas queda cerrada por el permiso de la ruta.
        $this->avanzar($this->personal(), $conEfectivo, ['hacia' => 'ENTREGADO', 'efectivo_confirmado' => true], 'entregas')->assertStatus(403);
    }

    public function test_las_pantallas_solo_ofrecen_el_avance_que_el_rol_puede_hacer(): void
    {
        $confirmado = $this->pedidoConfirmado();
        $despachado = $this->pedidoConfirmado();
        $this->avanzarHasta($despachado, 'EN_PREPARACION', 'DESPACHADO');

        // Con los dos permisos: la bandeja de entregas y el detalle ofrecen el siguiente paso.
        $this->assertSame('EN_PREPARACION', $this->avanceOfrecidoEnEntregas($confirmado));
        $this->assertSame('ENTREGADO', $this->avanceOfrecidoEnEntregas($despachado));
        $this->withToken($this->personal())->getJson("/api/gestion/pedidos/{$despachado}")->assertJsonPath('acciones.avanzar', 'ENTREGADO');

        $this->quitarPermiso('PERSONAL_FEDER', 'pedidos.gestionar');
        $this->assertNull($this->avanceOfrecidoEnEntregas($confirmado), 'Sin pedidos.gestionar no se ofrece «Preparar».');
        $this->assertSame('ENTREGADO', $this->avanceOfrecidoEnEntregas($despachado));

        // El administrador conserva los dos permisos.
        $this->withToken($this->admin())->getJson("/api/gestion/pedidos/{$confirmado}")->assertJsonPath('acciones.avanzar', 'EN_PREPARACION');
    }

    public function test_sin_entregas_gestionar_el_detalle_no_ofrece_marcar_entregado(): void
    {
        $despachado = $this->pedidoConfirmado();
        $this->avanzarHasta($despachado, 'EN_PREPARACION', 'DESPACHADO');
        $this->quitarPermiso('PERSONAL_FEDER', 'entregas.gestionar');

        $this->withToken($this->personal())->getJson("/api/gestion/pedidos/{$despachado}")
            ->assertOk()->assertJsonPath('acciones.avanzar', null);
    }

    public function test_un_cliente_no_usa_ninguna_ruta_de_avance(): void
    {
        $pedido = $this->pedidoConfirmado();

        $this->avanzar($this->cliente(), $pedido, ['hacia' => 'EN_PREPARACION'], 'pedidos')->assertStatus(403);
        $this->avanzar($this->cliente(), $pedido, ['hacia' => 'EN_PREPARACION'], 'entregas')->assertStatus(403);
        $this->assertSame('CONFIRMADO', $this->pedido($pedido)->estado);
    }

    private function avanceOfrecidoEnEntregas(int $pedidoId): ?string
    {
        $codigo = $this->pedido($pedidoId)->codigo;
        $entregas = $this->withToken($this->personal())->getJson('/api/gestion/entregas?q='.$codigo)->assertOk()->json('entregas');

        return collect($entregas)->firstWhere('pedido.id', $pedidoId)['avanzar_a'] ?? null;
    }
}
