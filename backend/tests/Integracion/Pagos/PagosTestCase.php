<?php

namespace Tests\Integracion\Pagos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Integracion\IntegracionTestCase;

/**
 * Apoyos comunes de las pruebas de pagos, cuotas y devoluciones (PostgreSQL real, transacción revertida).
 *
 * Los pedidos se crean y se pagan por la API. La preparación, el despacho y la entrega se dejan hechos con SQL
 * (método entregar) para que estas pruebas no dependan del contrato de «avanzar pedido», que es de otro módulo.
 */
abstract class PagosTestCase extends IntegracionTestCase
{
    protected const CARLA = 'almacen.carla.mendez@feder.example';     // PERSONAL_FEDER
    protected const JORGE = 'ventas.jorge.suarez@feder.example';      // PERSONAL_FEDER + CLIENTE
    protected const DANIELA = 'daniela.flores@feder.example';         // CLIENTE
    protected const LUCIO = 'lucio.soquere@feder.example';            // CLIENTE
    protected const MARCO = 'marco.antelo@feder.example';             // CLIENTE

    protected const ACEITE_COPAIBO = 13;   // UNIDAD, Bs 70
    protected const ACEITE_MOTACU = 15;    // UNIDAD, Bs 60
    protected const HARINA_PLATANO = 6;    // UNIDAD, Bs 22
    protected const MIEL_GRANEL = 5;       // KILOGRAMO, Bs 90
    protected const ACEITE_TOTAI_100 = 2;  // UNIDAD, Bs 90, sin stock y admite encargo

    protected const TARJETA_APROBADA = '4242 4242 4242 4242';
    protected const TARJETA_SIN_FONDOS = '4000 0000 0000 0002';

    protected function cliente(string $correo): string
    {
        return $this->tokenDe($correo, 'CLIENTE');
    }

    protected function personal(string $correo = self::CARLA): string
    {
        return $this->tokenDe($correo, 'PERSONAL_FEDER');
    }

    protected function idDe(string $correo): int
    {
        return (int) DB::table('usuario')->whereRaw('lower(correo) = ?', [mb_strtolower($correo)])->value('id');
    }

    protected function tarjeta(string $numero = self::TARJETA_APROBADA): array
    {
        return ['numero' => $numero, 'titular' => 'CLIENTE DE PRUEBA', 'expira_mes' => 12, 'expira_anio' => (int) now()->format('Y') + 3, 'cvv' => '123'];
    }

    protected function uuid(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Crea un pedido con recojo por la API (INMEDIATO salvo que se pida ENCARGO). $items: [producto_id => cantidad].
     * Con $cuotas (2 o 3) el pedido se paga con plan de cuotas.
     */
    protected function crearPedido(string $correo, array $items, ?int $cuotas = null, string $tipo = 'INMEDIATO'): array
    {
        $token = $this->cliente($correo);
        DB::table('item_carrito')->where('cliente_id', $this->idDe($correo))->delete();
        foreach ($items as $productoId => $cantidad) {
            $this->withToken($token)->postJson('/api/carrito', ['producto_id' => $productoId, 'cantidad' => $cantidad])->assertOk();
        }

        return $this->withToken($token)->postJson('/api/pedidos', [
            'tipo' => $tipo, 'modalidad' => 'RECOJO', 'nombre_receptor' => 'Cliente de Prueba', 'telefono_contacto' => '70012345',
            'forma_pago' => $cuotas ? 'CUOTAS' : 'UNICO', 'num_cuotas' => $cuotas,
        ])->assertCreated()->json();
    }

    protected function pagar(string $correo, int $pedidoId, array $cuerpo): TestResponse
    {
        return $this->withToken($this->cliente($correo))->postJson("/api/mis-pedidos/{$pedidoId}/pagar", $cuerpo);
    }

    /** Paga con tarjeta nueva aprobada. Con plan hay que indicar la cuota. */
    protected function pagarConTarjeta(string $correo, int $pedidoId, ?int $cuota = null): TestResponse
    {
        return $this->pagar($correo, $pedidoId, ['metodo' => 'TARJETA', 'tarjeta' => $this->tarjeta(), 'cuota_numero' => $cuota, 'clave_operacion' => $this->uuid()]);
    }

    /**
     * Deja el pedido ENTREGADO como lo haría el personal: consume sus reservas (SALIDA_VENTA por cada una), recorre
     * los estados con su historial y marca la entrega. No cobra nada: los pagos quedan como estén.
     */
    protected function entregar(int $pedidoId): void
    {
        $personalId = $this->idDe(self::CARLA);
        $reservas = DB::table('reserva_stock as rs')->join('detalle_pedido as dp', 'dp.id', '=', 'rs.detalle_pedido_id')
            ->where('dp.pedido_id', $pedidoId)->where('rs.estado', 'ACTIVA')->get(['rs.id', 'rs.lote_feder_id', 'rs.cantidad']);
        $this->assertNotEmpty($reservas, 'El pedido no tiene reservas activas que despachar.');

        foreach ($reservas as $r) {
            DB::update("UPDATE reserva_stock SET estado = 'CONSUMIDA', fecha_cierre = now() WHERE id = ?", [$r->id]);
            DB::update('UPDATE lote_feder SET cantidad_disponible = cantidad_disponible - ?, cantidad_reservada = cantidad_reservada - ? WHERE id = ?',
                [$r->cantidad, $r->cantidad, $r->lote_feder_id]);
            DB::table('movimiento_inventario')->insert([
                'lote_feder_id' => $r->lote_feder_id, 'usuario_id' => $personalId, 'reserva_id' => $r->id, 'tipo' => 'SALIDA_VENTA',
                'cantidad' => $r->cantidad, 'motivo' => 'Despacho del pedido (prueba)', 'clave_operacion' => hash('sha256', 'PRUEBA|SALIDA_VENTA|'.$r->id),
            ]);
        }

        $estado = DB::table('pedido')->where('id', $pedidoId)->value('estado');
        $this->assertSame('CONFIRMADO', $estado, 'Solo se entrega un pedido CONFIRMADO.');
        foreach (['EN_PREPARACION', 'DESPACHADO', 'ENTREGADO'] as $nuevo) {
            DB::table('pedido')->where('id', $pedidoId)->update(['estado' => $nuevo]);
            DB::table('historial_pedido')->insert(['pedido_id' => $pedidoId, 'usuario_id' => $personalId, 'estado_anterior' => $estado, 'estado_nuevo' => $nuevo]);
            $estado = $nuevo;
        }
        DB::table('entrega')->where('pedido_id', $pedidoId)->update(['estado' => 'ENTREGADA', 'fecha_entrega' => now()]);
    }

    /** Id del despacho (movimiento SALIDA_VENTA) de un producto del pedido. */
    protected function despachoDe(int $pedidoId, int $productoId): int
    {
        return (int) DB::table('movimiento_inventario as m')
            ->join('reserva_stock as rs', 'rs.id', '=', 'm.reserva_id')
            ->join('detalle_pedido as dp', 'dp.id', '=', 'rs.detalle_pedido_id')
            ->where('dp.pedido_id', $pedidoId)->where('dp.producto_id', $productoId)->where('m.tipo', 'SALIDA_VENTA')
            ->value('m.id');
    }

    protected function solicitarDevolucion(string $correo, int $despachoId, float $cantidad): TestResponse
    {
        return $this->withToken($this->cliente($correo))->postJson('/api/mis-devoluciones',
            ['despacho_id' => $despachoId, 'cantidad' => $cantidad, 'motivo' => 'Producto en mal estado']);
    }

    protected function ultimaDevolucion(int $despachoId): int
    {
        return (int) DB::table('devolucion')->where('movimiento_inventario_id', $despachoId)->max('id');
    }

    protected function aprobarDevolucion(int $devolucionId, string $resolucion, float $recibida, float $apta, float $aprobada, string $personal = self::CARLA): TestResponse
    {
        return $this->withToken($this->personal($personal))->postJson("/api/gestion/devoluciones/{$devolucionId}/aprobar", [
            'resolucion' => $resolucion, 'cantidad_recibida' => $recibida, 'cantidad_apta' => $apta, 'cantidad_aprobada' => $aprobada,
        ]);
    }

    protected function completarDevolucion(int $devolucionId, string $personal = self::CARLA): TestResponse
    {
        return $this->withToken($this->personal($personal))->postJson("/api/gestion/devoluciones/{$devolucionId}/completar");
    }

    /** Fila JSON del plan de cuotas del pedido (aunque esté dado de baja). */
    protected function filaPlan(int $pedidoId): ?object
    {
        return DB::table('confirmacion_operacion')->where('comando', 'PLAN_PAGO')
            ->whereRaw("(parametros->>'pedido_id')::bigint = ?", [$pedidoId])->orderByDesc('id')->first();
    }

    protected function plan(int $pedidoId): array
    {
        return json_decode($this->filaPlan($pedidoId)->parametros, true);
    }

    protected function pagos(int $pedidoId, ?string $estado = null): \Illuminate\Support\Collection
    {
        return DB::table('pago')->where('pedido_id', $pedidoId)->when($estado, fn ($q) => $q->where('estado', $estado))->orderBy('id')->get();
    }

    protected function estadoPedido(int $pedidoId): string
    {
        return DB::table('pedido')->where('id', $pedidoId)->value('estado');
    }

    /** Filas de bitácora escritas por la web para una entidad y un registro. */
    protected function bitacora(string $entidad, int $entidadId): \Illuminate\Support\Collection
    {
        return DB::table('bitacora')->where('entidad', $entidad)->where('entidad_id', $entidadId)->orderBy('id')->get()
            ->map(function ($fila) {
                $fila->detalle = json_decode($fila->detalle, true);

                return $fila;
            });
    }
}
