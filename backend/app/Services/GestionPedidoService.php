<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\ConfirmacionOperacion;
use App\Repositories\BitacoraRepository;
use App\Repositories\ConfiguracionWebRepository;
use App\Repositories\InventarioRepository;
use App\Repositories\NotificacionRepository;
use App\Repositories\OperacionProductorRepository;
use App\Repositories\PagoRepository;
use App\Repositories\PedidoRepository;
use App\Repositories\ReintegroCancelacionRepository;
use App\Repositories\UsuarioRepository;
use App\Support\Cantidad;
use App\Support\SeparacionFunciones;
use App\Support\SesionActiva;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Procesamiento de pedidos por el Personal de FEDER (CU6): preparar, despachar, entregar,
 * abastecer encargos y cancelar. Cada cambio queda en historial_pedido y en la bitácora, y se notifica al cliente.
 *
 * Cada operación es una sola transacción que bloquea primero la fila del pedido: si una regla no se cumple
 * se lanza NegocioException y no queda ningún cambio parcial.
 */
class GestionPedidoService
{
    /** Avance normal de un pedido confirmado. */
    private const SIGUIENTE = [
        'CONFIRMADO' => 'EN_PREPARACION',
        'EN_PREPARACION' => 'DESPACHADO',
        'DESPACHADO' => 'ENTREGADO',
    ];

    /**
     * Permiso que exige cada transición, sea cual sea la ruta por la que llegue la petición.
     * Preparar y despachar son procesamiento de pedidos; entregar (y cobrar el efectivo) es gestión de entregas.
     */
    public const PERMISO_POR_TRANSICION = [
        'EN_PREPARACION' => 'pedidos.gestionar',
        'DESPACHADO' => 'pedidos.gestionar',
        'ENTREGADO' => 'entregas.gestionar',
    ];

    private const ACCION = ['EN_PREPARACION' => 'preparar', 'DESPACHADO' => 'despachar', 'ENTREGADO' => 'entregar'];

    private const CANCELABLES = ['PENDIENTE', 'ESPERANDO_ABASTECIMIENTO', 'CONFIRMADO', 'EN_PREPARACION'];

    /** Pedidos ya confirmados a los que se les puede volver a reservar stock si perdieron su cobertura. */
    private const REABASTECIBLES = ['CONFIRMADO', 'EN_PREPARACION'];

    private const MENSAJES = [
        'EN_PREPARACION' => 'Estamos preparando tu pedido.',
        'DESPACHADO' => 'Tu pedido salió del almacén.',
        'ENTREGADO' => 'Tu pedido fue entregado. ¡Gracias por comprar en FEDER!',
    ];

    public function __construct(
        private readonly PedidoRepository $pedidos,
        private readonly PagoRepository $pagos,
        private readonly InventarioRepository $inventario,
        private readonly AbastecimientoService $abastecimiento,
        private readonly NotificacionRepository $notificaciones,
        private readonly PlanPagoService $planes,
        private readonly PedidoService $pedidoService,
        private readonly UsuarioRepository $usuarios,
        private readonly OperacionProductorRepository $operaciones,
        private readonly CobroPedidoService $cobros,
        private readonly ReintegroCancelacionRepository $reintegros,
        private readonly ConfiguracionWebRepository $configuracion,
        private readonly AuditoriaService $auditoria,
        private readonly BitacoraRepository $bitacora,
    ) {}

    public function listar(array $filtros): array
    {
        $pagina = $this->pedidos->listarGestion($filtros);

        return [
            'pedidos' => collect($pagina->items())->map(fn ($p) => [
                'id' => (int) $p->id,
                'codigo' => $p->codigo,
                'cliente' => $p->cliente,
                'tipo' => $p->tipo,
                'estado' => $p->estado,
                'modalidad' => $p->modalidad,
                'estado_entrega' => $p->estado_entrega,
                'fecha' => $p->fecha_creacion,
                'total' => (float) $p->total,
                'pagado' => (float) $p->pagado,
            ])->all(),
            'paginacion' => ['pagina' => $pagina->currentPage(), 'paginas' => $pagina->lastPage(), 'total' => $pagina->total()],
        ];
    }

    /** Entregas para el personal: cada fila indica a qué estado puede avanzar su pedido quien consulta. */
    public function listarEntregas(array $filtros, SesionActiva $sesion): array
    {
        $pagina = $this->pedidos->listarEntregas($filtros);
        $asignaciones = $this->operaciones->operacionesJson(ConfirmacionOperacion::ASIGNACION_ENTREGA)
            ->groupBy(fn ($o) => (int) ($o->parametros['pedido_id'] ?? 0))->map->first();

        $permisos = $this->permisosDe($sesion);

        return [
            'entregas' => collect($pagina->items())->map(function ($e) use ($asignaciones, $permisos) {
                $pendiente = $this->pagos->pendienteDelPedido((int) $e->pedido_id, false);
                $asignacion = $asignaciones->get((int) $e->pedido_id)?->parametros;

                return [
                    'id' => (int) $e->id,
                    'pedido' => ['id' => (int) $e->pedido_id, 'codigo' => $e->codigo, 'estado' => $e->estado_pedido, 'fecha' => $e->fecha_creacion],
                    'cliente' => $e->cliente,
                    'modalidad' => $e->modalidad,
                    'estado' => $e->estado,
                    'direccion' => $e->direccion,
                    'referencia' => $e->referencia,
                    'receptor' => $e->nombre_receptor,
                    'telefono' => $e->telefono_contacto,
                    'costo_envio' => (float) $e->costo_envio,
                    'fecha_entrega' => $e->fecha_entrega,
                    'observacion' => $e->observacion,
                    'avanzar_a' => $this->avancePermitido($permisos, $e->estado_pedido),
                    'responsable_entrega' => $asignacion['responsable'] ?? null,
                    'telefono_responsable' => $asignacion['telefono'] ?? null,
                    'efectivo_a_recibir' => $pendiente?->metodo === 'EFECTIVO' ? (float) $pendiente->monto : null,
                    'requiere_confirmar_efectivo' => $e->estado_pedido === 'DESPACHADO' && $pendiente?->metodo === 'EFECTIVO',
                ];
            })->all(),
            'paginacion' => ['pagina' => $pagina->currentPage(), 'paginas' => $pagina->lastPage(), 'total' => $pagina->total()],
        ];
    }

    public function detalle(SesionActiva $sesion, int $pedidoId): array
    {
        $pedido = $this->pedidos->buscar($pedidoId) ?? throw new NegocioException('Ese pedido no existe.', 404);
        $cliente = $this->usuarios->buscarPorId((int) $pedido->usuario_id);

        return $this->pedidoService->detalle((int) $pedido->usuario_id, $pedidoId) + [
            'cliente' => [
                'nombre' => $cliente->nombreCompleto(),
                'correo' => $cliente->correo,
                'telefono' => $cliente->telefono,
            ],
            'acciones' => $this->accionesPosibles($sesion, $pedido),
        ];
    }

    /**
     * Lleva el pedido al siguiente estado de su ciclo.
     *
     * - «hacia» (obligatorio) es el estado que el personal vio como siguiente paso. Se compara con el pedido ya
     *   bloqueado: si no está donde se esperaba (otra persona lo avanzó, la pestaña es antigua o la petición llegó
     *   repetida) se responde 409 y no se hace nada. Así ninguna petición ejecuta una transición distinta de la vista.
     * - Cada transición exige su permiso (PERMISO_POR_TRANSICION) y el cobro resuelto (CobroPedidoService).
     */
    public function avanzar(SesionActiva $sesion, int $pedidoId, array $datos, ?string $ip = null): array
    {
        $hacia = $datos['hacia'] ?? null;
        if (! is_string($hacia) || ! in_array($hacia, self::SIGUIENTE, true)) {
            throw new NegocioException('Falta indicar a qué estado quieres llevar el pedido. Recarga la página e inténtalo de nuevo.');
        }

        DB::transaction(function () use ($sesion, $pedidoId, $datos, $hacia, $ip) {
            $pedido = $this->pedidos->buscar($pedidoId, bloquear: true) ?? throw new NegocioException('Ese pedido no existe.', 404);
            $nuevo = self::SIGUIENTE[$pedido->estado] ?? null;

            if ($hacia !== $nuevo) {
                throw new NegocioException($hacia === $pedido->estado
                    ? "El pedido {$pedido->codigo} ya está «".self::legible($hacia).'»: alguien lo actualizó antes. Recarga la página.'
                    : "El pedido {$pedido->codigo} está «".self::legible($pedido->estado).'» y desde ahí no puede pasar a «'
                        .self::legible($hacia).'». Recarga la página.', 409);
            }
            if (! $this->puede($this->permisosDe($sesion), $nuevo)) {
                throw new NegocioException('Tu rol no tiene permiso para '.self::ACCION[$nuevo].' pedidos.', 403);
            }

            $plan = $this->planes->delPedido((int) $pedido->id, bloquear: true);
            $entrega = $this->pedidos->entregaDeGestion((int) $pedido->id)
                ?? throw new NegocioException('El pedido no tiene una entrega registrada.', 409);

            match ($nuevo) {
                'EN_PREPARACION' => $this->preparar($sesion, $pedido, $plan, $ip),
                'DESPACHADO' => $this->despachar($sesion, $pedido, $plan, $entrega, $datos, $ip),
                'ENTREGADO' => $this->entregar($sesion, $pedido, $plan, $entrega, $datos, $ip),
            };
        });

        return $this->detalle($sesion, $pedidoId);
    }

    public function abastecer(SesionActiva $sesion, int $pedidoId, ?string $ip = null): array
    {
        $this->abastecimiento->manual($pedidoId, $sesion->usuario->id, $ip);

        return $this->detalle($sesion, $pedidoId);
    }

    /**
     * Cancelación por el personal antes del despacho (reglas R031 y R052): libera las reservas, cancela la
     * entrega, cierra el plan de cuotas y reintegra lo cobrado con un reembolso sin devolución.
     * Quien también es cliente no registra el reintegro de su propio pedido (separación de funciones).
     *
     * R053: si el pedido tiene un pago QR o TARJETA PENDIENTE, su resultado no se conoce (el proveedor pudo
     * haber cobrado). No se rechaza en silencio: se responde 409 y primero hay que conciliarlo en Gestión de pagos
     * (confirmarlo si se cobró o rechazarlo con su motivo). El efectivo pendiente nunca se cobró y termina sin cobro.
     */
    public function cancelar(SesionActiva $sesion, int $pedidoId, string $motivo, ?string $ip = null): array
    {
        DB::transaction(function () use ($sesion, $pedidoId, $motivo, $ip) {
            $personal = $sesion->usuario;
            $pedido = $this->pedidos->buscar($pedidoId, bloquear: true) ?? throw new NegocioException('Ese pedido no existe.', 404);
            if (! in_array($pedido->estado, self::CANCELABLES, true)) {
                throw new NegocioException('Un pedido despachado, entregado o ya cancelado no se puede cancelar.');
            }

            // Mismo orden de bloqueo que el pago del cliente: pedido, plan, pagos y, al final, lotes.
            $plan = $this->planes->delPedido((int) $pedido->id, bloquear: true);
            $pendiente = $this->pagos->pendienteDelPedido((int) $pedido->id);
            if (self::esDePasarela($pendiente)) {
                throw new NegocioException(self::textoPagoPorConciliar($pedido, $pendiente)
                    .' Antes de cancelar el pedido, verifícalo en Gestión de pagos: confírmalo si se cobró (al cancelar se reintegrará) '
                    .'o recházalo con su motivo si no hubo cobro.', 409);
            }
            $porReintegrar = $this->reintegros->pagosCobradosConSaldo((int) $pedido->id)->filter(fn ($pago) => $pago->saldo > 0);
            if ($porReintegrar->isNotEmpty()) {
                SeparacionFunciones::exigirAjeno($personal, $pedido, 'registrar el reintegro');
            }

            $this->rechazarEfectivoPendiente($personal->id, $pedido, $pendiente, 'RECHAZAR_POR_CANCELACION', "Cancelación del pedido {$pedido->codigo}", $ip);
            $liberadas = $this->inventario->liberarReservas((int) $pedido->id);
            $this->cancelarEntrega($personal->id, $pedido, $ip);
            if ($plan !== null) {
                $this->planes->cancelar($plan);
            }
            $this->cambiarEstado($personal->id, $pedido, 'CANCELADO', 'Cancelado por FEDER: '.$motivo);
            $this->avisar($pedido, "Pedido {$pedido->codigo}: cancelado", 'Cancelado por FEDER: '.$motivo);

            // Con el pedido ya CANCELADO el esquema admite el reintegro sin devolución de cada pago cobrado.
            $reintegrado = 0.0;
            foreach ($porReintegrar as $pago) {
                $texto = "Cancelación del pedido {$pedido->codigo}: {$motivo}";
                $reembolsoId = $this->reintegros->registrar((int) $pedido->id, (int) $pago->id, Cantidad::monto($pago->saldo), $texto);
                $this->registrar($personal->id, 'CREAR', 'REEMBOLSO', $reembolsoId, [
                    'operacion' => 'REINTEGRO_POR_CANCELACION', 'pago_id' => (int) $pago->id, 'pedido_id' => (int) $pedido->id,
                    'monto' => (float) $pago->saldo, 'metodo' => $pago->metodo, 'motivo' => $texto,
                ], $ip);
                $reintegrado += (float) $pago->saldo;
            }
            if ($reintegrado > 0) {
                $this->avisar($pedido, "Reintegro del pedido {$pedido->codigo}",
                    'Te reintegramos Bs '.CobroPedidoService::dinero($reintegrado).' por la cancelación de tu pedido.', 'REEMBOLSO_EJECUTADO');
            }

            $this->registrar($personal->id, 'ANULAR', 'PEDIDO', (int) $pedido->id, [
                'operacion' => 'CANCELAR', 'codigo' => $pedido->codigo, 'estado_anterior' => $pedido->estado, 'estado_nuevo' => 'CANCELADO',
                'motivo' => $motivo, 'reservas_liberadas' => $liberadas, 'reintegrado' => round($reintegrado, 2),
            ], $ip);
        });

        return $this->detalle($sesion, $pedidoId);
    }

    /**
     * Pedidos sin pagar cuyo plazo venció (tarea programada).
     * Aplica a la compra inmediata (sus reservas vencen a las 48 h) y también a los encargos pendientes o ya en
     * ESPERANDO_ABASTECIMIENTO sin ningún pago, que no reservan stock: «Pedido sin pagar en 48 h → libera reserva →
     * CANCELADO» (PROMPT 7.2). Un encargo en espera con efectivo contra entrega aceptado no vence.
     *
     * R053: un pedido con un pago QR o TARJETA PENDIENTE no se cancela ni se le rechaza el pago (el proveedor pudo
     * haber cobrado); se deja como está y se informa en «por_conciliar» para que el personal lo resuelva.
     *
     * @return array{cancelados: int, por_conciliar: list<array{pedido: string, pago_id: int, metodo: string, monto: float, referencia: ?string, mensaje: string}>}
     */
    public function vencerPedidosSinPago(): array
    {
        $limite = now()->subHours(PedidoService::HORAS_PARA_PAGAR);
        $cancelados = 0;
        $porConciliar = [];

        foreach ($this->inventario->pedidosImpagosVencidos($limite) as $pedidoId) {
            $resultado = DB::transaction(fn () => $this->vencerPedido($pedidoId, $limite));
            if ($resultado === true) {
                $cancelados++;
            } elseif (is_array($resultado)) {
                $porConciliar[] = $resultado;
            }
        }

        return ['cancelados' => $cancelados, 'por_conciliar' => $porConciliar];
    }

    // ------------------------------------------------------------------ transiciones

    /** CONFIRMADO → EN_PREPARACION: todas las líneas con stock reservado en lotes vigentes y el cobro resuelto. */
    private function preparar(SesionActiva $sesion, object $pedido, ?ConfirmacionOperacion $plan, ?string $ip): void
    {
        $sinCubrir = $this->inventario->coberturaDePedido((int) $pedido->id)->first(fn ($l) => $l->pendiente > 0);
        if ($sinCubrir !== null) {
            throw new NegocioException("El pedido {$pedido->codigo} no tiene todo su stock reservado en lotes vigentes: falta «{$sinCubrir->descripcion_venta}». "
                .'Usa «Abastecer» para reservar lo que falta o cancela el pedido.', 409);
        }
        $this->cobros->exigirResuelto($pedido, $plan, 'preparar');

        $this->cambiarEstado($sesion->usuario->id, $pedido, 'EN_PREPARACION', null);
        $this->avisarAvance($pedido, 'EN_PREPARACION');
        $this->registrarAvance($sesion, $pedido, 'EN_PREPARACION', [], $ip);
    }

    /**
     * EN_PREPARACION → DESPACHADO (despacho atómico): la entrega sale de PENDIENTE, cada reserva se consume con su
     * SALIDA_VENTA y el pedido cambia de estado, todo en la misma transacción.
     */
    private function despachar(SesionActiva $sesion, object $pedido, ?ConfirmacionOperacion $plan, object $entrega, array $datos, ?string $ip): void
    {
        $personal = $sesion->usuario;
        $this->cobros->exigirResuelto($pedido, $plan, 'despachar');

        $aDomicilio = $entrega->modalidad === 'DELIVERY';
        $responsable = trim($datos['responsable_entrega'] ?? '');
        if ($aDomicilio && mb_strlen($responsable) < 3) {
            throw new NegocioException('Indica quién llevará el pedido antes de despacharlo.');
        }

        $salidas = $this->inventario->consumirReservas((int) $pedido->id, $personal->id);
        if ($aDomicilio) {
            $this->operaciones->crearOperacionJson($personal->id, $personal->correo, ConfirmacionOperacion::ASIGNACION_ENTREGA, [
                'pedido_id' => (int) $pedido->id, 'responsable' => $responsable,
                'telefono' => trim($datos['telefono_responsable'] ?? ''), 'asignada_en' => now()->toIso8601String(),
            ]);
        }
        $this->cambiarEntrega($personal->id, $pedido, $entrega, $aDomicilio ? 'EN_CAMINO' : 'LISTA_PARA_RECOJO', $ip);
        $this->cambiarEstado($personal->id, $pedido, 'DESPACHADO', null);
        $this->avisarAvance($pedido, 'DESPACHADO');
        $this->registrarAvance($sesion, $pedido, 'DESPACHADO', [
            'salidas' => count($salidas),
            'unidades' => round(array_sum(array_map(fn ($s) => (float) $s['cantidad'], $salidas)), 3),
            'modalidad' => $entrega->modalidad,
        ] + ($aDomicilio ? ['responsable_entrega' => $responsable] : []), $ip);
    }

    /**
     * DESPACHADO → ENTREGADO: exige el cobro resuelto. El efectivo contra entrega se cobra aquí, con la
     * confirmación de quien lo recibe, que no puede ser el propio cliente del pedido (separación de funciones).
     */
    private function entregar(SesionActiva $sesion, object $pedido, ?ConfirmacionOperacion $plan, object $entrega, array $datos, ?string $ip): void
    {
        $personal = $sesion->usuario;
        $this->cobros->exigirResuelto($pedido, $plan, 'entregar');

        $efectivo = $this->cobros->pagadoPorCompleto($pedido) ? null : $this->cobros->efectivoContraEntrega($pedido);
        if ($efectivo !== null) {
            SeparacionFunciones::exigirAjeno($personal, $pedido, 'cobrar el efectivo');
            if (! ($datos['efectivo_confirmado'] ?? false)) {
                throw new NegocioException('Confirma que se recibió el efectivo antes de marcar la entrega.');
            }
            if (! $this->pagos->resolver((int) $efectivo->id, 'PAGADO')) {
                throw new NegocioException('El pago en efectivo cambió mientras registrabas la entrega. Recarga la página.', 409);
            }
            $this->registrar($personal->id, 'MODIFICAR', 'PAGO', (int) $efectivo->id, [
                'operacion' => 'COBRAR_EFECTIVO', 'estado_anterior' => 'PENDIENTE', 'estado_nuevo' => 'PAGADO',
                'monto' => (float) $efectivo->monto, 'pedido_id' => (int) $pedido->id, 'verificacion' => 'EFECTIVO_RECIBIDO_EN_LA_ENTREGA',
            ], $ip);
        }

        $this->cambiarEntrega($personal->id, $pedido, $entrega, 'ENTREGADA', $ip);
        $this->cambiarEstado($personal->id, $pedido, 'ENTREGADO', $efectivo !== null ? 'Entregado y cobrado en efectivo' : null);

        // Con el efectivo cobrado el pedido queda pagado por completo y se factura en la misma transacción.
        $factura = null;
        $actual = $this->pedidos->buscar((int) $pedido->id);
        if ($this->cobros->puedeFacturar($actual, $plan) && $this->pedidos->factura((int) $pedido->id) === null) {
            $cliente = $this->usuarios->buscarPorId((int) $pedido->usuario_id);
            $factura = $this->pedidos->emitirFactura($actual, $cliente, $personal->id, $ip)->numero;
        }

        $this->avisarAvance($pedido, 'ENTREGADO');
        $this->registrarAvance($sesion, $pedido, 'ENTREGADO', ['cobro_efectivo' => $efectivo !== null, 'factura_emitida' => $factura], $ip);
    }

    /**
     * Cancela un pedido impago cuyo plazo venció; se vuelve a comprobar todo con el pedido bloqueado.
     * Son un pedido PENDIENTE sin pagos o un ENCARGO en ESPERANDO_ABASTECIMIENTO sin ningún pago PAGADO ni pendiente.
     *
     * @return bool|array true si lo canceló, false si no había nada que hacer, o el pago QR/TARJETA PENDIENTE que hay
     *                    que conciliar antes (R053: el pedido, sus reservas y el pago quedan como estaban)
     */
    private function vencerPedido(int $pedidoId, CarbonInterface $limite): bool|array
    {
        $pedido = $this->pedidos->buscar($pedidoId, bloquear: true);
        $enEspera = $pedido?->estado === 'ESPERANDO_ABASTECIMIENTO' && $pedido->tipo === 'ENCARGO';
        if ($pedido === null || ($pedido->estado !== 'PENDIENTE' && ! $enEspera) || $this->cobros->pagado((int) $pedido->id) > 0) {
            return false;
        }
        $antesDelLimite = Carbon::parse($pedido->fecha_creacion)->lt($limite);
        if (! $antesDelLimite && ($enEspera || ! $this->inventario->tieneReservasVencidas((int) $pedido->id))) {
            return false;
        }

        $horas = PedidoService::HORAS_PARA_PAGAR;
        $plan = $this->planes->delPedido((int) $pedido->id, bloquear: true);
        $pendiente = $this->pagos->pendienteDelPedido((int) $pedido->id);
        if (self::esDePasarela($pendiente)) {
            return [
                'pedido' => $pedido->codigo, 'pago_id' => (int) $pendiente->id, 'metodo' => $pendiente->metodo,
                'monto' => (float) $pendiente->monto, 'referencia' => $pendiente->referencia_externa,
                'mensaje' => self::textoPagoPorConciliar($pedido, $pendiente).' No se cancela ni se rechaza el pago: verifícalo en Gestión '
                    .'de pagos y confírmalo si se cobró o recházalo con su motivo si no hubo cobro.',
            ];
        }
        if ($enEspera && $pendiente !== null) {
            return false; // Encargo aceptado con efectivo contra entrega: se cobra al entregar, no vence.
        }
        $this->rechazarEfectivoPendiente(null, $pedido, $pendiente, 'RECHAZAR_POR_VENCIMIENTO', "Venció el plazo de pago del pedido {$pedido->codigo}", null);
        $liberadas = $this->inventario->liberarReservas((int) $pedido->id, 'EXPIRADA');
        $this->cancelarEntrega(null, $pedido, null);
        if ($plan !== null) {
            $this->planes->cancelar($plan);
        }
        $this->cambiarEstado(null, $pedido, 'CANCELADO', "Venció el plazo de pago de {$horas} horas");
        $this->avisar($pedido, "Pedido {$pedido->codigo} cancelado", $pedido->tipo === 'ENCARGO'
            ? 'Venció el plazo para pagar tu encargo y lo cancelamos. Puedes volver a pedirlo cuando quieras.'
            : 'Venció el plazo para pagar y liberamos los productos reservados.');
        $this->registrar(null, 'ANULAR', 'PEDIDO', (int) $pedido->id, [
            'operacion' => 'VENCER_PLAZO_DE_PAGO', 'codigo' => $pedido->codigo, 'tipo' => $pedido->tipo,
            'estado_anterior' => $pedido->estado, 'estado_nuevo' => 'CANCELADO', 'horas' => $horas, 'reservas_liberadas' => $liberadas,
        ], null);

        return true;
    }

    // ------------------------------------------------------------------ apoyo interno

    /** Cambia el estado solo si el pedido sigue en el que se leyó con la fila bloqueada, y deja su historial. */
    private function cambiarEstado(?int $actorId, object $pedido, string $nuevo, ?string $comentario): void
    {
        if (! $this->pedidos->cambiarEstado((int) $pedido->id, $pedido->estado, $nuevo)) {
            throw new NegocioException('El pedido cambió mientras lo procesabas. Recarga la página.', 409);
        }

        $this->pedidos->registrarHistorial((int) $pedido->id, $actorId, $pedido->estado, $nuevo, $comentario);
    }

    /** Transición condicional de la entrega, con su fila de bitácora (estado anterior y nuevo). */
    private function cambiarEntrega(?int $actorId, object $pedido, object $entrega, string $nuevo, ?string $ip): void
    {
        $permitidos = [
            'EN_CAMINO' => ['PENDIENTE'], 'LISTA_PARA_RECOJO' => ['PENDIENTE'],
            'ENTREGADA' => ['EN_CAMINO', 'LISTA_PARA_RECOJO'], 'CANCELADA' => ['PENDIENTE'],
        ];
        if (! in_array($entrega->estado, $permitidos[$nuevo] ?? [], true)
            || ! $this->pedidos->transicionEntrega((int) $entrega->id, $entrega->estado, $nuevo)) {
            throw new NegocioException("La entrega del pedido {$pedido->codigo} está «".self::legible($entrega->estado)
                .'» y no puede pasar a «'.self::legible($nuevo).'». Recarga la página.', 409);
        }

        $this->registrar($actorId, 'MODIFICAR', 'ENTREGA', (int) $entrega->id, [
            'estado_anterior' => $entrega->estado, 'estado_nuevo' => $nuevo, 'pedido_id' => (int) $pedido->id,
        ], $ip);
    }

    private function cancelarEntrega(?int $actorId, object $pedido, ?string $ip): void
    {
        $entrega = $this->pedidos->entregaDeGestion((int) $pedido->id);
        if ($entrega !== null && $entrega->estado !== 'CANCELADA') {
            $this->cambiarEntrega($actorId, $pedido, $entrega, 'CANCELADA', $ip);
        }
    }

    /**
     * R049: el efectivo PENDIENTE de un pedido que se cancela nunca se cobró y termina sin cobro; queda en la
     * bitácora con su motivo. Un pago QR o TARJETA nunca se rechaza aquí: quien llama ya lo excluyó (R053).
     */
    private function rechazarEfectivoPendiente(?int $actorId, object $pedido, ?object $pendiente, string $operacion, string $motivo, ?string $ip): void
    {
        if ($pendiente === null) {
            return;
        }
        if (self::esDePasarela($pendiente)) {
            throw new NegocioException(self::textoPagoPorConciliar($pedido, $pendiente).' Concílialo antes de continuar.', 409);
        }
        if (! $this->pagos->resolver((int) $pendiente->id, 'RECHAZADO')) {
            throw new NegocioException('Un pago del pedido cambió mientras lo procesabas. Recarga la página.', 409);
        }

        $this->registrar($actorId, 'MODIFICAR', 'PAGO', (int) $pendiente->id, [
            'operacion' => $operacion, 'estado_anterior' => 'PENDIENTE', 'estado_nuevo' => 'RECHAZADO',
            'metodo' => $pendiente->metodo, 'monto' => (float) $pendiente->monto, 'pedido_id' => (int) $pedido->id, 'motivo' => $motivo,
        ], $ip);
    }

    /** QR y TARJETA dependen de un proveedor externo: un intento PENDIENTE puede haberse cobrado (R053). */
    private static function esDePasarela(?object $pago): bool
    {
        return $pago !== null && $pago->metodo !== 'EFECTIVO';
    }

    private static function textoPagoPorConciliar(object $pedido, object $pago): string
    {
        return "El pedido {$pedido->codigo} tiene un pago por {$pago->metodo} pendiente de Bs ".CobroPedidoService::dinero((float) $pago->monto)
            .($pago->referencia_externa ? " (referencia {$pago->referencia_externa})" : '').' cuyo resultado todavía no se conoce.';
    }

    private function avisar(object $pedido, string $titulo, string $mensaje, string $tipo = 'CAMBIO_ESTADO_PEDIDO'): void
    {
        $this->notificaciones->crearParaPedido((int) $pedido->usuario_id, (int) $pedido->id, $tipo, $titulo, $mensaje);
    }

    private function avisarAvance(object $pedido, string $nuevo): void
    {
        $this->avisar($pedido, "Pedido {$pedido->codigo}: ".mb_strtolower(self::legible($nuevo)), self::MENSAJES[$nuevo]);
    }

    private function registrarAvance(SesionActiva $sesion, object $pedido, string $nuevo, array $detalle, ?string $ip): void
    {
        $this->registrar($sesion->usuario->id, 'MODIFICAR', 'PEDIDO', (int) $pedido->id, [
            'operacion' => mb_strtoupper(self::ACCION[$nuevo]), 'codigo' => $pedido->codigo,
            'estado_anterior' => $pedido->estado, 'estado_nuevo' => $nuevo, 'rol' => $sesion->rolCodigo,
        ] + $detalle, $ip);
    }

    /** Bitácora con la acción nativa del esquema. Sin actor (tarea programada) la fila queda sin usuario. */
    private function registrar(?int $actorId, string $accion, string $entidad, int $entidadId, array $detalle, ?string $ip): void
    {
        if ($actorId !== null) {
            $this->auditoria->operacion($actorId, $accion, $entidad, $entidadId, $detalle, $ip);

            return;
        }

        $this->bitacora->registrar(null, $accion, $entidad, $entidadId, ['canal' => 'WEB', 'origen' => 'TAREA_PROGRAMADA'] + $detalle, null);
    }

    /**
     * Permisos del rol activo, leídos de la matriz PERMISO_ROL en cada operación (la misma fuente que usa el
     * middleware de rutas), para que un cambio de permisos se aplique desde la siguiente petición.
     *
     * @return list<string>
     */
    private function permisosDe(SesionActiva $sesion): array
    {
        return $this->configuracion->permisosDeRol($sesion->rolCodigo);
    }

    /** @param  list<string>  $permisos */
    private function puede(array $permisos, string $estadoDestino): bool
    {
        return in_array(self::PERMISO_POR_TRANSICION[$estadoDestino], $permisos, true);
    }

    /**
     * Siguiente estado del pedido si el rol activo tiene el permiso de esa transición; si no, null.
     *
     * @param  list<string>  $permisos
     */
    private function avancePermitido(array $permisos, string $estadoPedido): ?string
    {
        $siguiente = self::SIGUIENTE[$estadoPedido] ?? null;

        return $siguiente !== null && $this->puede($permisos, $siguiente) ? $siguiente : null;
    }

    private function accionesPosibles(SesionActiva $sesion, object $pedido): array
    {
        $cancelable = in_array($pedido->estado, self::CANCELABLES, true);
        $pagado = $cancelable ? $this->cobros->pagado((int) $pedido->id) : 0.0;
        $faltaStock = in_array($pedido->estado, self::REABASTECIBLES, true)
            && $this->inventario->coberturaDePedido((int) $pedido->id)->contains(fn ($l) => $l->pendiente > 0);
        $pendiente = $cancelable ? $this->pagos->pendienteDelPedido((int) $pedido->id, false) : null;

        return [
            'avanzar' => $this->avancePermitido($this->permisosDe($sesion), $pedido->estado),
            'abastecer' => ($pedido->tipo === 'ENCARGO' && $pedido->estado === 'ESPERANDO_ABASTECIMIENTO'
                && $this->cobros->resuelto($pedido, $this->planes->delPedido((int) $pedido->id))) || $faltaStock,
            // Quien también es cliente no cancela su propio pedido cuando hay dinero que reintegrar.
            'cancelar' => $cancelable && ! ($pagado > 0 && (int) $pedido->usuario_id === (int) $sesion->usuario->id),
            'reintegro_al_cancelar' => round($pagado, 2),
            'falta_stock' => $faltaStock,
            // R053: con un QR o TARJETA pendiente, cancelar responde 409 hasta que el pago se confirme o se rechace.
            'pago_por_conciliar' => self::esDePasarela($pendiente) ? [
                'id' => (int) $pendiente->id, 'metodo' => $pendiente->metodo,
                'monto' => (float) $pendiente->monto, 'referencia' => $pendiente->referencia_externa,
            ] : null,
        ];
    }

    /** Nombre de un estado de pedido o de entrega para los mensajes («EN_PREPARACION» → «En preparación»). */
    private static function legible(string $estado): string
    {
        return $estado === 'EN_PREPARACION' ? 'En preparación' : ucfirst(mb_strtolower(str_replace('_', ' ', $estado)));
    }
}
