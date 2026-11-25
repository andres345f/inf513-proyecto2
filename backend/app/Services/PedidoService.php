<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\ConfirmacionOperacion;
use App\Models\Usuario;
use App\Repositories\CarritoRepository;
use App\Repositories\InventarioRepository;
use App\Repositories\NotificacionRepository;
use App\Repositories\OperacionProductorRepository;
use App\Repositories\PagoRepository;
use App\Repositories\PedidoRepository;
use App\Support\Cantidad;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Confirmación del carrito como pedido (CU5) y consulta de pedidos del cliente.
 * Mismo orden que el Proyecto 1: pedido → líneas → entrega → historial → reservas (solo INMEDIATO).
 */
class PedidoService
{
    /** Plazo para pagar un pedido INMEDIATO antes de que venzan sus reservas. */
    public const HORAS_PARA_PAGAR = 48;

    /** Estados de un pedido ya aceptado que todavía puede pagarse si no se cobró (ver puedePagar). */
    private const ACEPTADO_SIN_COBRO = ['ESPERANDO_ABASTECIMIENTO', 'CONFIRMADO'];

    public function __construct(
        private readonly CarritoRepository $carrito,
        private readonly PedidoRepository $pedidos,
        private readonly InventarioRepository $inventario,
        private readonly PagoRepository $pagos,
        private readonly NotificacionRepository $notificaciones,
        private readonly OperacionProductorRepository $operaciones,
        private readonly EnvioService $envio,
        private readonly PlanPagoService $planes,
        private readonly ReservaService $reservas,
        private readonly AuditoriaService $auditoria,
        private readonly EstadoCuentaPedidoService $estadosCuenta,
    ) {
    }

    public function confirmar(Usuario $cliente, array $datos, ?string $ip = null): array
    {
        $pedidoId = DB::transaction(function () use ($cliente, $datos, $ip) {
            $items = $this->carrito->items($cliente->id, bloquear: true);
            $this->validarItems($items, $datos['tipo']);
            $this->validarImportes($items);

            $costoEnvio = 0.0;
            $cotizacion = null;
            if ($datos['modalidad'] === 'DELIVERY') {
                $cotizacion = $this->envio->cotizar((float) $datos['lat'], (float) $datos['lng'], $items);
                if ($cotizacion['fuera_de_cobertura']) {
                    throw new NegocioException('La dirección está fuera de nuestra zona de entrega ('.$cotizacion['cobertura_km'].' km). Puedes recoger tu pedido en el almacén.');
                }
                $costoEnvio = (float) $cotizacion['costo'];
            }

            $subtotal = round($items->sum(fn ($i) => round((float) $i->cantidad * (float) $i->precio_venta, 2)), 2);
            $total = round($subtotal + $costoEnvio, 2);
            if ($total <= 0) {
                throw new NegocioException('El pedido no tiene importe por cobrar. Revisa las cantidades de tu carrito.');
            }

            if ($datos['forma_pago'] === 'CUOTAS') {
                $this->planes->validarElegible($total, (int) $datos['num_cuotas']);
            }

            $pedido = $this->pedidos->crear([
                'usuario_id' => $cliente->id,
                'tipo' => $datos['tipo'],
                'estado' => 'PENDIENTE',
                'subtotal' => Cantidad::monto($subtotal),
                'costo_envio' => Cantidad::monto($costoEnvio),
                'total' => Cantidad::monto($total),
                'clave_idempotencia' => hash('sha256', 'PEDIDO|'.$cliente->id.'|'.Str::uuid()),
                'observacion' => $datos['observacion'] ?? null,
            ]);

            $lineas = $items->map(fn ($i) => [
                'id' => $this->pedidos->crearLinea([
                    'pedido_id' => $pedido->id,
                    'producto_id' => $i->producto_id,
                    'cantidad' => Cantidad::texto((float) $i->cantidad),
                    'precio_unitario' => Cantidad::monto((float) $i->precio_venta),
                    'subtotal' => Cantidad::monto(round((float) $i->cantidad * (float) $i->precio_venta, 2)),
                    'descripcion_venta' => mb_substr($i->nombre.' · '.$i->presentacion, 0, 200),
                ]),
                'producto_id' => (int) $i->producto_id,
                'cantidad' => (float) $i->cantidad,
                'nombre' => $i->nombre,
            ]);

            $this->pedidos->crearEntrega($this->datosEntrega($pedido->id, $datos, $cotizacion));
            $this->pedidos->registrarHistorial($pedido->id, $cliente->id, null, 'PENDIENTE', 'Pedido creado desde la web');

            if ($datos['tipo'] === 'INMEDIATO') {
                $this->reservas->reservarLineas($lineas, now()->addHours(self::HORAS_PARA_PAGAR)->toDateTimeString());
            }

            if ($datos['forma_pago'] === 'CUOTAS') {
                $this->planes->crear($cliente, $pedido, (int) $datos['num_cuotas']);
            }

            $this->carrito->quitarItems($items->pluck('id')->all());
            $this->auditoria->operacion($cliente->id, 'CREAR', 'PEDIDO', (int) $pedido->id, [
                'operacion' => 'CREAR_PEDIDO', 'codigo' => $pedido->codigo, 'tipo' => $datos['tipo'], 'modalidad' => $datos['modalidad'],
                'forma_pago' => $datos['forma_pago'], 'num_cuotas' => $datos['forma_pago'] === 'CUOTAS' ? (int) $datos['num_cuotas'] : null,
                'lineas' => $lineas->count(), 'total' => $total,
            ], $ip);
            $this->notificaciones->crearParaPedido($cliente->id, $pedido->id, 'CAMBIO_ESTADO_PEDIDO',
                "Pedido {$pedido->codigo} creado",
                $datos['tipo'] === 'INMEDIATO'
                    ? 'Reservamos tus productos por '.self::HORAS_PARA_PAGAR.' horas. Completa el pago para confirmarlo.'
                    : 'Registramos tu encargo. Te avisaremos cuando llegue el producto.');

            return $pedido->id;
        });

        return $this->detalle($cliente->id, $pedidoId);
    }

    /** Cotización del envío del carrito actual, para mostrarla antes de confirmar. */
    public function cotizarEnvio(int $clienteId, float $lat, float $lng): array
    {
        $items = $this->carrito->items($clienteId);
        if ($items->isEmpty()) {
            throw new NegocioException('Tu carrito está vacío.');
        }

        return $this->envio->cotizar($lat, $lng, $items);
    }

    public function listar(int $clienteId, array $filtros): array
    {
        $pagina = $this->pedidos->listarDelCliente($clienteId, $filtros);

        return [
            'pedidos' => collect($pagina->items())->map(fn ($p) => [
                'id' => (int) $p->id,
                'codigo' => $p->codigo,
                'tipo' => $p->tipo,
                'estado' => $p->estado,
                'fecha' => $p->fecha_creacion,
                'total' => (float) $p->total,
                'modalidad' => $p->modalidad,
            ])->all(),
            'paginacion' => [
                'pagina' => $pagina->currentPage(),
                'paginas' => $pagina->lastPage(),
                'total' => $pagina->total(),
            ],
        ];
    }

    public function detalle(int $clienteId, int $pedidoId): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->leerDetalle($clienteId, $pedidoId);
        }

        // Las distintas consultas del detalle deben observar el mismo instante, incluso durante un reintegro.
        return DB::transaction(function () use ($clienteId, $pedidoId) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $this->leerDetalle($clienteId, $pedidoId);
        });
    }

    private function leerDetalle(int $clienteId, int $pedidoId): array
    {
        $pedido = $this->pedidos->buscarDelCliente($pedidoId, $clienteId)
            ?? throw new NegocioException('Ese pedido no existe.', 404);
        $plan = $this->planes->delPedido($pedido->id);
        $pagado = $this->pagos->totalPagado($pedido->id);
        $detallePlan = $plan ? $this->planes->formatear($plan) : null;
        $cuenta = $this->estadosCuenta->calcular($pedido, $pagado, $detallePlan);

        return [
            'id' => (int) $pedido->id,
            'codigo' => $pedido->codigo,
            'tipo' => $pedido->tipo,
            'estado' => $pedido->estado,
            'fecha' => $pedido->fecha_creacion,
            'subtotal' => (float) $pedido->subtotal,
            'costo_envio' => (float) $pedido->costo_envio,
            'total' => (float) $pedido->total,
            'pagado' => $pagado,
            'saldo' => $cuenta['pendiente_pago'],
            'estado_cuenta' => $cuenta,
            'observacion' => $pedido->observacion,
            'lineas' => $this->pedidos->lineas($pedido->id)->map(fn ($l) => [
                'producto_id' => (int) $l->producto_id,
                'descripcion' => $l->descripcion_venta,
                'cantidad' => (float) $l->cantidad,
                'precio' => (float) $l->precio_unitario,
                'subtotal' => (float) $l->subtotal,
            ])->all(),
            'entrega' => $this->entregaConResponsable((int) $pedido->id),
            'historial' => $this->pedidos->historial($pedido->id),
            'pagos' => $this->pagos->delPedido($pedido->id)->map(fn ($p) => [
                'id' => (int) $p->id,
                'metodo' => $p->metodo,
                'monto' => (float) $p->monto,
                'estado' => $p->estado,
                'fecha' => $p->fecha_pago ?? $p->fecha_registro,
                'referencia' => $p->referencia_externa,
            ])->all(),
            'plan' => $detallePlan,
            'factura' => $this->pedidos->factura($pedido->id),
            'puede_pagar' => $this->puedePagar($pedido, $plan),
            'plazo_pago_vencido' => $this->plazoDePagoVencido($pedido),
            'puede_cancelar' => $pedido->estado === 'PENDIENTE' && $pagado == 0.0,
        ];
    }

    /**
     * Orden de bloqueo: pedido → plan → pago (el mismo de todos los flujos de pago).
     * Un pago por QR que sigue PENDIENTE no se rechaza aquí: el cliente pudo pagarlo desde su banco, así que queda
     * pendiente hasta que FEDER lo concilie (si se cobró, se reintegra; si no, lo rechaza con su motivo).
     * El efectivo contra entrega nunca se cobró: termina sin cobro junto con el pedido.
     */
    public function cancelar(Usuario $cliente, int $pedidoId, string $motivo, ?string $ip = null): array
    {
        DB::transaction(function () use ($cliente, $pedidoId, $motivo, $ip) {
            $pedido = $this->pedidos->buscarDelCliente($pedidoId, $cliente->id, bloquear: true)
                ?? throw new NegocioException('Ese pedido no existe.', 404);
            $plan = $this->planes->delPedido($pedido->id, bloquear: true);

            if ($pedido->estado !== 'PENDIENTE' || $this->pagos->totalPagado($pedido->id) > 0) {
                throw new NegocioException('Solo puedes cancelar un pedido pendiente que aún no pagaste.');
            }

            $pendiente = $this->pagos->pendienteDelPedido($pedido->id);
            if ($pendiente && $pendiente->metodo === 'EFECTIVO' && $this->pagos->resolver($pendiente->id, 'RECHAZADO')) {
                $this->auditoria->operacion($cliente->id, 'MODIFICAR', 'PAGO', (int) $pendiente->id, [
                    'operacion' => 'CERRAR_SIN_COBRO', 'estado_anterior' => 'PENDIENTE', 'estado_nuevo' => 'RECHAZADO',
                    'motivo' => "Cancelación del pedido {$pedido->codigo}", 'pedido_id' => (int) $pedido->id,
                ], $ip);
            }
            $porConciliar = $pendiente && $pendiente->metodo !== 'EFECTIVO';

            $this->inventario->liberarReservas($pedido->id);
            $this->pedidos->cambiarEstadoEntrega($pedido->id, 'CANCELADA');
            $this->pedidos->cambiarEstado($pedido->id, 'PENDIENTE', 'CANCELADO');
            $this->pedidos->registrarHistorial($pedido->id, $cliente->id, 'PENDIENTE', 'CANCELADO', 'Cancelado por el cliente: '.$motivo);
            if ($plan) {
                $this->planes->cancelar($plan);
            }
            // Misma acción y operación que la cancelación por el personal (ANULAR · CANCELAR), con quién la pidió.
            $this->auditoria->operacion($cliente->id, 'ANULAR', 'PEDIDO', (int) $pedido->id, [
                'operacion' => 'CANCELAR', 'cancelado_por' => 'CLIENTE', 'codigo' => $pedido->codigo,
                'estado_anterior' => 'PENDIENTE', 'estado_nuevo' => 'CANCELADO',
                'motivo' => $motivo, 'pago_qr_por_conciliar' => $pendiente?->metodo === 'QR' ? (int) $pendiente->id : null,
                'pago_por_conciliar' => $porConciliar ? (int) $pendiente->id : null,
            ], $ip);
            $this->notificaciones->crearParaPedido($cliente->id, $pedido->id, 'CAMBIO_ESTADO_PEDIDO',
                "Pedido {$pedido->codigo} cancelado", 'Cancelaste tu pedido y liberamos los productos reservados.'
                    .($porConciliar ? ' Tu pago quedó en revisión: si llegaste a pagarlo, te devolveremos el importe.' : ''));
        });

        return $this->detalle($cliente->id, $pedidoId);
    }

    /**
     * Si el cliente puede registrar un pago ahora:
     * - con plan de cuotas: mientras el plan siga ACTIVO y el pedido no esté cancelado;
     * - sin plan: el pedido PENDIENTE dentro de su plazo; y también el pedido ya aceptado que todavía no se cobró
     *   (ESPERANDO_ABASTECIMIENTO o CONFIRMADO con saldo y sin efectivo contra entrega), como los que el Proyecto 1
     *   acepta antes de cobrar. Un encargo aceptado sin ningún pago ni efectivo contra entrega tiene el mismo plazo de
     *   48 h que la tarea feder:vencer-pedidos (ver plazoDePagoVencido).
     */
    public function puedePagar(object $pedido, $plan): bool
    {
        if ($this->plazoDePagoVencido($pedido)) {
            return false;
        }
        if ($plan !== null) {
            return $plan->parametros['estado'] === 'ACTIVO' && $pedido->estado !== 'CANCELADO';
        }
        if ($pedido->estado === 'PENDIENTE') {
            return true;
        }

        return in_array($pedido->estado, self::ACEPTADO_SIN_COBRO, true)
            && round((float) $pedido->total - $this->pagos->totalPagado((int) $pedido->id), 2) > 0
            && $this->pagos->pendienteDelPedido((int) $pedido->id, false)?->metodo !== 'EFECTIVO';
    }

    /**
     * Plazo de 48 h de un pedido que aún no tiene ningún pago: el mismo criterio que la tarea feder:vencer-pedidos
     * (PENDIENTE con reservas vencidas o creado hace más de 48 h; ENCARGO aceptado sin cobro ni efectivo contra entrega
     * creado hace más de 48 h). Se comprueba al pagar para que el plazo exista aunque el programador no haya corrido.
     */
    public function plazoDePagoVencido(object $pedido): bool
    {
        $conPlazo = $pedido->estado === 'PENDIENTE' || ($pedido->estado === 'ESPERANDO_ABASTECIMIENTO' && $pedido->tipo === 'ENCARGO');

        return $conPlazo && $this->pagos->plazoDePagoVencido((int) $pedido->id, self::HORAS_PARA_PAGAR);
    }

    public function exigirPlazoDePagoVigente(object $pedido): void
    {
        if ($this->plazoDePagoVencido($pedido)) {
            throw new NegocioException('Venció el plazo de '.self::HORAS_PARA_PAGAR.' horas para pagar este pedido y ya no se puede cobrar. '
                .($pedido->estado === 'PENDIENTE'
                    ? 'Cancélalo y vuelve a hacer tu compra.'
                    : 'FEDER lo cancelará; puedes volver a pedirlo cuando quieras.'));
        }
    }

    /** Añade al detalle de entrega la asignación logística registrada al despachar. */
    private function entregaConResponsable(int $pedidoId): ?array
    {
        $entrega = $this->pedidos->entrega($pedidoId);
        if ($entrega === null) {
            return null;
        }

        $asignacion = $this->operaciones->operacionesJson(ConfirmacionOperacion::ASIGNACION_ENTREGA)
            ->first(fn ($operacion) => (int) ($operacion->parametros['pedido_id'] ?? 0) === $pedidoId)?->parametros;

        return (array) $entrega + [
            'responsable_entrega' => $asignacion['responsable'] ?? null,
            'telefono_responsable' => $asignacion['telefono'] ?? null,
        ];
    }

    private function validarItems(Collection $items, string $tipo): void
    {
        if ($items->isEmpty()) {
            throw new NegocioException('Tu carrito está vacío. Agrega productos antes de confirmar.');
        }

        $inactivos = $items->where('activo', false)->pluck('nombre');
        if ($inactivos->isNotEmpty()) {
            throw new NegocioException('Estos productos ya no están a la venta; quítalos del carrito: '.$inactivos->join(', ').'.');
        }

        foreach ($items as $i) {
            $falta = (float) $i->stock < (float) $i->cantidad;
            if ($tipo === 'INMEDIATO' && $falta) {
                throw new NegocioException("No hay stock suficiente de «{$i->nombre}» (disponible: ".(float) $i->stock.').'
                    .($i->permite_encargo ? ' Puedes pedirlo como encargo.' : ' Reduce la cantidad.'));
            }
            if ($tipo === 'ENCARGO' && ! $i->permite_encargo) {
                throw new NegocioException("«{$i->nombre}» no admite encargos. Cómpralo como pedido inmediato.");
            }
            if ($tipo === 'ENCARGO' && ! $falta) {
                throw new NegocioException("Hay stock suficiente de «{$i->nombre}»: confírmalo como pedido inmediato.");
            }
        }
    }

    /**
     * Cada línea debe valer al menos un centavo: una cantidad tan pequeña que redondea a Bs 0,00 crearía un pedido
     * sin importe, que ningún medio de pago puede cobrar (pago.monto > 0).
     */
    private function validarImportes(Collection $items): void
    {
        foreach ($items as $i) {
            if (round((float) $i->cantidad * (float) $i->precio_venta, 2) <= 0) {
                throw new NegocioException("La cantidad de «{$i->nombre}» es tan pequeña que su importe es Bs 0,00. "
                    .'Aumenta la cantidad o quítalo del carrito.');
            }
        }
    }

    private function datosEntrega(int $pedidoId, array $datos, ?array $cotizacion): array
    {
        $delivery = $datos['modalidad'] === 'DELIVERY';
        $referencia = $delivery
            ? trim(sprintf('%.6f,%.6f · %s', $datos['lat'], $datos['lng'], $datos['referencia'] ?? ''), ' ·')
            : null;

        return [
            'pedido_id' => $pedidoId,
            'modalidad' => $datos['modalidad'],
            'estado' => 'PENDIENTE',
            'direccion' => $delivery ? trim($datos['direccion']) : null,
            'referencia' => $referencia ? mb_substr($referencia, 0, 255) : null,
            'nombre_receptor' => trim($datos['nombre_receptor']),
            'telefono_contacto' => trim($datos['telefono_contacto']),
            'observacion' => $cotizacion
                ? sprintf('Envío en %s: %.1f km, %d min, %.1f kg%s', strtolower($cotizacion['vehiculo']), $cotizacion['distancia_km'],
                    $cotizacion['duracion_min'], $cotizacion['peso_kg'], $cotizacion['estimado'] ? ' (estimado)' : '')
                : null,
        ];
    }
}
