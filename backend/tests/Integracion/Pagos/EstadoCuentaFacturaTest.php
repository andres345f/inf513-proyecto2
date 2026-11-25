<?php

namespace Tests\Integracion\Pagos;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

class EstadoCuentaFacturaTest extends PagosTestCase
{
    private function detalle(int $pedidoId): TestResponse
    {
        return $this->withToken($this->cliente(self::LUCIO))->getJson("/api/mis-pedidos/{$pedidoId}")->assertOk();
    }

    /** Caso A con un intento pendiente de conciliación, uno fallido y ejecución parcial anterior al reintento. */
    public function test_reintegro_conciliado_sin_duplicar_y_factura_historica(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 3]);
        $cobro = $this->pagarConTarjeta(self::LUCIO, $pedido['id'])->assertOk()->json();
        $factura = $cobro['pedido']['factura'];
        $this->entregar($pedido['id']);
        $despacho = $this->despachoDe($pedido['id'], self::ACEITE_COPAIBO);
        $this->solicitarDevolucion(self::LUCIO, $despacho, 2)->assertCreated();
        $devolucion = $this->ultimaDevolucion($despacho);
        $this->aprobarDevolucion($devolucion, 'REEMBOLSO', 2, 2, 2)->assertOk();

        $pago = $cobro['pago']['id'];
        $intento = [
            'pago_id' => $pago, 'devolucion_id' => $devolucion, 'motivo' => 'Caso de conciliación del laboratorio',
            'clave_idempotencia' => hash('sha256', "REEMBOLSO|{$devolucion}|{$pago}"),
        ];
        $id = DB::table('reembolso')->insertGetId($intento + ['monto' => 140, 'estado' => 'PENDIENTE']);
        $this->detalle($pedido['id'])->assertJsonPath('estado_cuenta.reembolso_pendiente', 140)
            ->assertJsonPath('estado_cuenta.reembolsado', 0)->assertJsonPath('estado_cuenta.neto', 210);
        $this->completarDevolucion($devolucion)->assertStatus(409);

        // El resultado de la conciliación proviene del proceso que inició el reintegro.
        DB::table('reembolso')->where('id', $id)->update(['estado' => 'FALLIDO']);
        $this->detalle($pedido['id'])->assertJsonPath('estado_cuenta.reembolsos.0.estado', 'FALLIDO')
            ->assertJsonPath('estado_cuenta.reembolso_pendiente', 140)->assertJsonPath('estado_cuenta.reembolsado', 0);
        $intento['clave_idempotencia'] = hash('sha256', "PARCIAL|{$devolucion}");
        DB::table('reembolso')->insert($intento + ['monto' => 30, 'estado' => 'EJECUTADO', 'fecha_ejecucion' => now()]);
        $this->detalle($pedido['id'])->assertJsonPath('estado_cuenta.reembolso_pendiente', 110);
        $this->completarDevolucion($devolucion)->assertOk();
        $this->completarDevolucion($devolucion)->assertStatus(422);
        $detalle = $this->detalle($pedido['id'])->assertJsonPath('estado_cuenta.original', 210)
            ->assertJsonPath('estado_cuenta.devoluciones_aprobadas', 140)->assertJsonPath('estado_cuenta.cobrado', 210)
            ->assertJsonPath('estado_cuenta.reembolsado', 140)->assertJsonPath('estado_cuenta.reembolso_pendiente', 0)
            ->assertJsonPath('estado_cuenta.reduccion_cuotas', 0)->assertJsonPath('estado_cuenta.pendiente_pago', 0)
            ->assertJsonPath('estado_cuenta.neto', 70)->assertJsonCount(3, 'estado_cuenta.reembolsos')->json();
        $this->assertSame($factura, $detalle['factura']);
    }

    public function test_anulacion_documentada_exige_administracion_sustento_y_fecha_y_se_registra_una_vez(): void
    {
        $pedido = $this->crearPedido(self::LUCIO, [self::ACEITE_COPAIBO => 3]);
        $factura = $this->pagarConTarjeta(self::LUCIO, $pedido['id'])->assertOk()->json('pedido.factura');
        $this->withToken($this->personal())->postJson("/api/gestion/pedidos/{$pedido['id']}/cancelar", ['motivo' => 'Cancelación de laboratorio'])->assertOk();
        $this->detalle($pedido['id'])->assertJsonPath('factura.estado', 'VIGENTE')->assertJsonPath('estado_cuenta.neto', 0);
        $reembolsos = DB::table('reembolso')->count();

        $ruta = "/api/gestion/facturas/{$factura['id']}/anulacion";
        $admin = 'administracion@feder.example';
        $datos = ['motivo' => 'Anulación con expediente recibido', 'referencia' => 'LAB-F-19', 'fecha' => now()->toIso8601String()];
        $this->withToken($this->personal())->postJson($ruta, $datos)->assertForbidden();
        $this->withToken($this->cliente(self::LUCIO))->postJson($ruta, $datos)->assertForbidden();
        $token = $this->tokenDe($admin, 'ADMINISTRADOR');
        $this->withToken($token)->postJson($ruta, [])->assertUnprocessable();
        $this->withToken($token)->postJson($ruta, ['fecha' => now()->subDay()->toIso8601String()] + $datos)->assertUnprocessable();
        $this->withToken($token)->postJson($ruta, ['fecha' => now()->addDay()->toIso8601String()] + $datos)->assertUnprocessable();
        $this->withToken($token)->postJson($ruta, $datos)->assertOk()->assertJsonPath('estado', 'ANULADA');
        $this->withToken($token)->postJson($ruta, $datos)->assertConflict();
        $this->assertSame($reembolsos, DB::table('reembolso')->count());
        $this->assertSame(1, DB::table('factura')->where('pedido_id', $pedido['id'])->count());
        $this->detalle($pedido['id'])->assertJsonPath('factura.numero', $factura['numero'])->assertJsonPath('factura.total', $factura['total']);
        $asientos = $this->bitacora('FACTURA', $factura['id'])->where('accion', 'ANULAR')->values();
        $this->assertCount(1, $asientos);
        $this->assertEquals($this->idDe($admin), $asientos[0]->usuario_id);
        $this->assertSame('LAB-F-19', $asientos[0]->detalle['referencia']);
        $this->assertSame('VIGENTE', $asientos[0]->detalle['antes']['estado']);
        $this->assertSame('ANULADA', $asientos[0]->detalle['despues']['estado']);
    }
}
