<?php

namespace Tests\Integracion\Pedidos;

use App\Models\ConfirmacionOperacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Integracion\IntegracionTestCase;

/**
 * Apoyos de las pruebas de pedidos, encargos e inventario sobre PostgreSQL real.
 *
 * Los productos y lotes de cada prueba se crean dentro de su transacción (con los disparadores del esquema activos),
 * así no dependen de las cantidades de la semilla. Los pedidos y los pagos se hacen por la API, como en la web.
 */
abstract class PedidosTestCase extends IntegracionTestCase
{
    protected const PERSONAL = 'almacen.carla.mendez@feder.example';

    protected const ADMIN = 'administracion@feder.example';

    protected const CLIENTE = 'daniela.flores@feder.example';

    protected const OTRO_CLIENTE = 'marco.antelo@feder.example';

    /** Cuenta con los roles PERSONAL_FEDER y CLIENTE a la vez. */
    protected const PERSONAL_Y_CLIENTE = 'ventas.jorge.suarez@feder.example';

    private const PRODUCTOR_ID = 1;

    /** @var array<int, int> producción de apoyo por producto */
    private array $producciones = [];

    // ------------------------------------------------------------------ sesiones

    protected function personal(): string
    {
        return $this->tokenDe(self::PERSONAL, 'PERSONAL_FEDER');
    }

    protected function admin(): string
    {
        return $this->tokenDe(self::ADMIN, 'ADMINISTRADOR');
    }

    protected function cliente(string $correo = self::CLIENTE): string
    {
        return $this->tokenDe($correo, 'CLIENTE');
    }

    protected function idDe(string $correo): int
    {
        return (int) DB::table('usuario')->whereRaw('lower(correo) = ?', [mb_strtolower($correo)])->value('id');
    }

    // ------------------------------------------------------------------ productos y lotes

    protected function crearProducto(array $datos = []): int
    {
        $sufijo = strtoupper(Str::random(8));

        return (int) DB::table('producto')->insertGetId($datos + [
            'categoria_id' => 1,
            'codigo' => 'TST-'.$sufijo,
            'nombre' => 'Producto de prueba '.$sufijo,
            'presentacion' => 'Unidad de prueba',
            'unidad_medida' => 'UNIDAD',
            'precio_venta' => '50.00',
            'permite_encargo' => false,
            'activo' => true,
        ]);
    }

    /** Lote de origen del productor, ofertado y todavía sin comprar (para compras por la API). */
    protected function crearLoteOrigen(int $productoId, float $cantidad, ?string $vence = null): int
    {
        return (int) DB::table('lote_produccion')->insertGetId([
            'produccion_id' => $this->produccionDe($productoId),
            'codigo_lote' => 'LPT-'.strtoupper(Str::random(10)),
            'fecha_elaboracion' => '2026-01-15',
            'fecha_vencimiento' => $vence,
            'cantidad_producida' => $cantidad,
            'cantidad_ofrecida' => $cantidad,
            'precio_ofrecido' => '10.00',
            'estado' => 'DISPONIBLE',
        ]);
    }

    /**
     * Lote de FEDER ya recibido: lote de origen, compra RECIBIDA, detalle, lote y su movimiento ENTRADA_COMPRA.
     *
     * @param  string|null  $vence  fecha de vencimiento del lote de origen (AAAA-MM-DD) o null si no vence
     * @param  string  $ingreso  fecha y hora de ingreso al almacén
     */
    protected function crearLoteFeder(int $productoId, float $cantidad, ?string $vence, string $ingreso): int
    {
        $sufijo = strtoupper(Str::random(10));
        $loteOrigenId = $this->crearLoteOrigen($productoId, $cantidad, $vence);
        DB::table('lote_produccion')->where('id', $loteOrigenId)->update(['estado' => 'AGOTADO']);

        $compraId = (int) DB::table('compra_feder')->insertGetId([
            'productor_id' => self::PRODUCTOR_ID,
            'codigo' => 'CMT-'.$sufijo,
            'fecha_compra' => $ingreso,
            'estado' => 'RECIBIDA',
            'total' => round($cantidad * 10, 2),
        ]);
        $detalleId = (int) DB::table('detalle_compra')->insertGetId([
            'compra_id' => $compraId,
            'lote_origen_id' => $loteOrigenId,
            'cantidad_comprada' => $cantidad,
            'precio_unitario' => '10.00',
            'cantidad_recibida' => $cantidad,
        ]);
        $loteId = (int) DB::table('lote_feder')->insertGetId([
            'detalle_compra_id' => $detalleId,
            'codigo' => 'LFT-'.$sufijo,
            'fecha_ingreso' => $ingreso,
            'cantidad_disponible' => $cantidad,
            'cantidad_reservada' => 0,
            'ubicacion_almacen' => 'Estante de prueba',
            'estado' => 'DISPONIBLE',
        ]);
        DB::table('movimiento_inventario')->insert([
            'lote_feder_id' => $loteId,
            'usuario_id' => $this->idDe(self::PERSONAL),
            'tipo' => 'ENTRADA_COMPRA',
            'cantidad' => $cantidad,
            'fecha' => $ingreso,
            'clave_operacion' => hash('sha256', 'PRUEBA|ENTRADA_COMPRA|'.$sufijo),
        ]);

        return $loteId;
    }

    /** Cambia la fecha de vencimiento del lote de origen de un lote de FEDER (simula el paso del tiempo). */
    protected function fijarVencimiento(int $loteFederId, ?string $fecha): void
    {
        DB::update('UPDATE lote_produccion SET fecha_vencimiento = ? WHERE id = (
                      SELECT dc.lote_origen_id FROM lote_feder lf JOIN detalle_compra dc ON dc.id = lf.detalle_compra_id WHERE lf.id = ?)',
            [$fecha, $loteFederId]);
    }

    protected function ayer(): string
    {
        return now()->subDay()->toDateString();
    }

    protected function enDias(int $dias): string
    {
        return now()->addDays($dias)->toDateString();
    }

    private function produccionDe(int $productoId): int
    {
        if (isset($this->producciones[$productoId])) {
            return $this->producciones[$productoId];
        }
        $comunidad = DB::table('productor as p')->join('comunidad_agricola as c', 'c.id', '=', 'p.comunidad_agricola_id')
            ->where('p.id', self::PRODUCTOR_ID)->first(['c.id', 'c.nombre', 'c.municipio', 'c.departamento']);

        return $this->producciones[$productoId] = (int) DB::table('produccion')->insertGetId([
            'productor_id' => self::PRODUCTOR_ID,
            'producto_id' => $productoId,
            'comunidad_origen_id' => $comunidad->id,
            'codigo' => 'PRT-'.strtoupper(Str::random(10)),
            'fecha_inicio' => '2026-01-10',
            'estado' => 'TERMINADA',
            'nombre_comunidad_origen' => $comunidad->nombre,
            'municipio_origen' => $comunidad->municipio,
            'departamento_origen' => $comunidad->departamento,
        ]);
    }

    // ------------------------------------------------------------------ pedidos y pagos por la API

    /**
     * El cliente arma su carrito y confirma el pedido por la API.
     *
     * @param  array<int, float|int>  $items  producto_id => cantidad
     */
    protected function crearPedido(string $correo, array $items, array $extra = []): TestResponse
    {
        DB::table('item_carrito')->where('cliente_id', $this->idDe($correo))->delete();
        $token = $this->cliente($correo);
        foreach ($items as $productoId => $cantidad) {
            $this->withToken($token)->postJson('/api/carrito', ['producto_id' => $productoId, 'cantidad' => $cantidad])->assertOk();
        }

        return $this->withToken($token)->postJson('/api/pedidos', $extra + [
            'tipo' => 'INMEDIATO',
            'modalidad' => 'RECOJO',
            'nombre_receptor' => 'Cliente de Prueba',
            'telefono_contacto' => '70012345',
            'forma_pago' => 'UNICO',
        ]);
    }

    /** Crea el pedido y devuelve su id (falla si la API no lo acepta). */
    protected function pedidoDe(string $correo, array $items, array $extra = []): int
    {
        return (int) $this->crearPedido($correo, $items, $extra)->assertCreated()->json('id');
    }

    protected function pagarConTarjeta(string $correo, int $pedidoId, string $numero = '4242424242424242'): TestResponse
    {
        return $this->withToken($this->cliente($correo))->postJson("/api/mis-pedidos/{$pedidoId}/pagar", [
            'metodo' => 'TARJETA',
            'cuota_numero' => collect($this->plan($pedidoId)['cuotas'] ?? [])->firstWhere('estado', 'PENDIENTE')['numero'] ?? null,
            'tarjeta' => [
                'numero' => $numero, 'titular' => 'Cliente de Prueba',
                'expira_mes' => 12, 'expira_anio' => (int) now()->format('Y') + 2, 'cvv' => '123',
            ],
        ]);
    }

    protected function pagarEnEfectivo(string $correo, int $pedidoId): TestResponse
    {
        return $this->withToken($this->cliente($correo))->postJson("/api/mis-pedidos/{$pedidoId}/pagar", ['metodo' => 'EFECTIVO']);
    }

    /** Pedido INMEDIATO pagado con tarjeta: queda CONFIRMADO con sus reservas fijas. */
    protected function pedidoPagado(string $correo, array $items, array $extra = []): int
    {
        $pedidoId = $this->pedidoDe($correo, $items, $extra);
        $this->pagarConTarjeta($correo, $pedidoId)->assertOk()->assertJsonPath('resultado', 'PAGADO');

        return $pedidoId;
    }

    // ------------------------------------------------------------------ gestión por la API

    /** @param  string  $ruta  «pedidos» o «entregas» (las dos rutas de avance) */
    protected function avanzar(string $token, int $pedidoId, array $cuerpo = [], string $ruta = 'pedidos'): TestResponse
    {
        return $this->withToken($token)->postJson("/api/gestion/{$ruta}/{$pedidoId}/avanzar", $cuerpo);
    }

    /** Lleva el pedido por los estados indicados con la cuenta del personal. */
    protected function avanzarHasta(int $pedidoId, string ...$estados): void
    {
        foreach ($estados as $estado) {
            $this->avanzar($this->personal(), $pedidoId, ['hacia' => $estado])->assertOk()->assertJsonPath('estado', $estado);
        }
    }

    protected function abastecer(string $token, int $pedidoId): TestResponse
    {
        return $this->withToken($token)->postJson("/api/gestion/pedidos/{$pedidoId}/abastecer");
    }

    protected function cancelarComoPersonal(string $token, int $pedidoId, string $motivo = 'Cancelación de prueba'): TestResponse
    {
        return $this->withToken($token)->postJson("/api/gestion/pedidos/{$pedidoId}/cancelar", ['motivo' => $motivo]);
    }

    /**
     * Llegada real de stock: el personal registra la compra de un lote de origen y su recepción por la API.
     * Devuelve el id del lote de FEDER que creó la recepción.
     */
    protected function comprarYRecibir(int $loteOrigenId, float $cantidad): int
    {
        $token = $this->personal();
        $compraId = (int) $this->withToken($token)->postJson('/api/gestion/compras', [
            'lineas' => [['lote_id' => $loteOrigenId, 'cantidad' => $cantidad, 'precio' => 10]],
            'modalidad_traslado' => 'ENTREGA_PRODUCTOR',
        ])->assertSuccessful()->json('id');

        $detalleId = (int) $this->withToken($token)->getJson("/api/gestion/compras/{$compraId}")->assertOk()->json('lineas.0.id');
        $this->withToken($token)->postJson("/api/gestion/compras/{$compraId}/recibir", [
            'lineas' => [['detalle_id' => $detalleId, 'recibida' => $cantidad, 'rechazada' => 0, 'cancelada' => 0]],
        ])->assertOk();

        return (int) DB::table('lote_feder')->where('detalle_compra_id', $detalleId)->value('id');
    }

    // ------------------------------------------------------------------ lecturas de comprobación

    protected function pedido(int $id): object
    {
        return DB::table('pedido')->where('id', $id)->first();
    }

    protected function entrega(int $pedidoId): object
    {
        return DB::table('entrega')->where('pedido_id', $pedidoId)->first();
    }

    protected function lote(int $id): object
    {
        return DB::table('lote_feder')->where('id', $id)->first();
    }

    /** Reservas del pedido (todas, en orden de creación). */
    protected function reservas(int $pedidoId): Collection
    {
        return DB::table('reserva_stock as rs')->join('detalle_pedido as dp', 'dp.id', '=', 'rs.detalle_pedido_id')
            ->where('dp.pedido_id', $pedidoId)->orderBy('rs.id')
            ->get(['rs.id', 'rs.lote_feder_id', 'rs.cantidad', 'rs.estado', 'rs.fecha_expiracion', 'rs.fecha_cierre', 'dp.producto_id']);
    }

    /** Movimientos SALIDA_VENTA del pedido. */
    protected function salidas(int $pedidoId): Collection
    {
        return DB::table('movimiento_inventario as m')->join('reserva_stock as rs', 'rs.id', '=', 'm.reserva_id')
            ->join('detalle_pedido as dp', 'dp.id', '=', 'rs.detalle_pedido_id')
            ->where('dp.pedido_id', $pedidoId)->where('m.tipo', 'SALIDA_VENTA')->orderBy('m.id')
            ->get(['m.id', 'm.lote_feder_id', 'm.reserva_id', 'm.cantidad', 'm.usuario_id']);
    }

    protected function pagos(int $pedidoId): Collection
    {
        return DB::table('pago')->where('pedido_id', $pedidoId)->orderBy('id')->get();
    }

    protected function reembolsos(int $pedidoId): Collection
    {
        return DB::table('reembolso as r')->join('pago as pg', 'pg.id', '=', 'r.pago_id')
            ->where('pg.pedido_id', $pedidoId)->orderBy('r.id')->get(['r.*']);
    }

    protected function historial(int $pedidoId): Collection
    {
        return DB::table('historial_pedido')->where('pedido_id', $pedidoId)->orderBy('id')->get();
    }

    /** Filas de bitácora de una entidad, con el detalle ya como arreglo. */
    protected function bitacora(string $entidad, int $entidadId): Collection
    {
        return DB::table('bitacora')->where('entidad', $entidad)->where('entidad_id', $entidadId)->orderBy('id')->get()
            ->map(function ($fila) {
                $fila->detalle = json_decode((string) $fila->detalle, true);

                return $fila;
            });
    }

    protected function plan(int $pedidoId): ?array
    {
        return ConfirmacionOperacion::query()->where('comando', 'PLAN_PAGO')
            ->whereRaw("(parametros->>'pedido_id')::bigint = ?", [$pedidoId])->first()?->parametros;
    }

    /** Fotografía del inventario para comprobar que una operación rechazada no dejó cambios parciales. */
    protected function fotoInventario(int $pedidoId): array
    {
        $reservas = $this->reservas($pedidoId);

        return [
            'pedido' => $this->pedido($pedidoId)->estado,
            'entrega' => $this->entrega($pedidoId)->estado,
            'reservas' => $reservas->map(fn ($r) => [$r->id, $r->estado, $r->cantidad])->all(),
            'lotes' => DB::table('lote_feder')->whereIn('id', $reservas->pluck('lote_feder_id')->unique()->all())->orderBy('id')
                ->get(['id', 'cantidad_disponible', 'cantidad_reservada', 'estado'])->map(fn ($l) => (array) $l)->all(),
            'movimientos' => DB::table('movimiento_inventario')->count(),
            'historial' => $this->historial($pedidoId)->count(),
            'pagos' => $this->pagos($pedidoId)->map(fn ($p) => [$p->id, $p->estado])->all(),
            'reembolsos' => $this->reembolsos($pedidoId)->count(),
        ];
    }

    /** Quita o repone permisos de un rol en la matriz PERMISO_ROL (dentro de la transacción de la prueba). */
    protected function quitarPermiso(string $rol, string $permiso): void
    {
        $fila = ConfirmacionOperacion::query()->where('comando', 'PERMISO_ROL')->whereRaw("parametros->>'rol' = ?", [$rol])->firstOrFail();
        $parametros = $fila->parametros;
        $parametros['permisos'] = array_values(array_diff($parametros['permisos'], [$permiso]));
        $fila->parametros = $parametros;
        $fila->save();
    }
}
