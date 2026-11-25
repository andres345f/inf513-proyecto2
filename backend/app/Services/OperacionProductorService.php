<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\ConfirmacionOperacion;
use App\Models\Usuario;
use App\Repositories\OperacionProductorRepository;
use App\Support\Cantidad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Flujo operativo completo entre el productor y FEDER, sin cambios de esquema. */
class OperacionProductorService
{
    /** R040: los lotes de origen se registran en una producción EN_CURSO o TERMINADA. */
    private const ESTADOS_CON_LOTES = ['EN_CURSO', 'TERMINADA'];

    /** R040: datos de origen del lote que su productor ya no cambia cuando FEDER lo compró. */
    private const DATOS_DE_ORIGEN = ['fecha_elaboracion', 'fecha_vencimiento', 'cantidad_producida'];

    public function __construct(
        private readonly OperacionProductorRepository $operaciones,
        private readonly ProductoService $productos,
        private readonly AuditoriaService $auditoria,
    ) {}

    public function opcionesProduccion(): array
    {
        return ['productos' => $this->operaciones->productosDisponibles()->map(fn ($p) => [
            'id' => (int) $p->id, 'codigo' => $p->codigo, 'nombre' => $p->nombre, 'presentacion' => $p->presentacion,
            'unidad_medida' => $p->unidad_medida, 'categoria' => $p->categoria,
        ])->all()];
    }

    public function crearProduccion(Usuario $usuario, array $datos, ?string $ip): array
    {
        return DB::transaction(function () use ($usuario, $datos, $ip) {
            $productor = $this->productor($usuario->id, bloquear: true);
            $producto = $this->operaciones->productoDisponible((int) $datos['producto_id'])
                ?? throw new NegocioException('El producto elegido no está disponible.', 404);
            if (! $producto->activo) {
                throw ValidationException::withMessages(['producto_id' => ['El producto elegido ya no está activo.']]);
            }
            $id = $this->operaciones->crearProduccion($productor, $this->normalizarProduccion($datos, $producto->unidad_medida));
            $this->auditoria->operacion($usuario->id, 'CREAR', 'PRODUCCION', $id, ['producto_id' => (int) $datos['producto_id']], $ip);

            return ['id' => $id, 'message' => 'Producción registrada.'];
        });
    }

    public function detalleProduccion(Usuario $usuario, int $id): array
    {
        $p = $this->operaciones->produccionDelUsuario($id, $usuario->id)
            ?? throw new NegocioException('Esa producción no existe o no te pertenece.', 404);

        return $this->formatearProduccion($p) + [
            'lotes' => $this->operaciones->lotesDeProduccion($id)->map(fn ($l) => $this->formatearLote($l))->all(),
            'puede_editar' => in_array($p->estado, ['PLANIFICADA', 'EN_CURSO'], true),
            // R040: los lotes se registran cuando la producción ya empezó (EN_CURSO) o terminó.
            'puede_registrar_lotes' => in_array($p->estado, self::ESTADOS_CON_LOTES, true),
            'puede_cancelar' => in_array($p->estado, ['PLANIFICADA', 'EN_CURSO'], true) && ! $this->operaciones->produccionTieneCompras($id),
        ];
    }

    public function actualizarProduccion(Usuario $usuario, int $id, array $datos, ?string $ip): array
    {
        DB::transaction(function () use ($usuario, $id, $datos, $ip) {
            $actual = $this->operaciones->produccionDelUsuario($id, $usuario->id, bloquear: true)
                ?? throw new NegocioException('Esa producción no existe o no te pertenece.', 404);
            if (! in_array($actual->estado, ['PLANIFICADA', 'EN_CURSO'], true)) {
                throw new NegocioException('Una producción terminada o cancelada ya no se puede editar.');
            }
            if ((int) $actual->producto_id !== (int) $datos['producto_id'] && $this->operaciones->lotesDeProduccion($id)->isNotEmpty()) {
                throw ValidationException::withMessages(['producto_id' => ['No puedes cambiar el producto después de registrar lotes.']]);
            }
            $producto = $this->operaciones->productoDisponible((int) $datos['producto_id'])
                ?? throw new NegocioException('El producto elegido no está disponible.', 404);
            if ((int) $actual->producto_id !== (int) $producto->id && ! $producto->activo) {
                throw ValidationException::withMessages(['producto_id' => ['El producto elegido ya no está activo.']]);
            }
            // R040: el inicio de la producción no puede quedar después de la elaboración de sus lotes.
            $primeraElaboracion = $this->operaciones->primeraElaboracionDeLotes($id);
            if ($primeraElaboracion !== null && Carbon::parse($datos['fecha_inicio'])->startOfDay()->gt(Carbon::parse($primeraElaboracion)->startOfDay())) {
                throw ValidationException::withMessages(['fecha_inicio' => [
                    'La fecha de inicio no puede ser posterior a la elaboración de los lotes ya registrados (el primero se elaboró el '
                    .Carbon::parse($primeraElaboracion)->format('d/m/Y').').',
                ]]);
            }
            $this->operaciones->actualizarProduccion($id, $this->normalizarProduccion($datos, $producto->unidad_medida));
            $this->auditoria->operacion($usuario->id, 'MODIFICAR', 'PRODUCCION', $id, [], $ip);
        });

        return ['message' => 'Producción actualizada.'];
    }

    public function cambiarEstadoProduccion(Usuario $usuario, int $id, string $nuevo, ?string $ip): array
    {
        DB::transaction(function () use ($usuario, $id, $nuevo, $ip) {
            $p = $this->operaciones->produccionDelUsuario($id, $usuario->id, bloquear: true)
                ?? throw new NegocioException('Esa producción no existe o no te pertenece.', 404);
            $permitidos = [
                'PLANIFICADA' => ['EN_CURSO', 'CANCELADA'],
                'EN_CURSO' => ['TERMINADA', 'CANCELADA'],
            ];
            if (! in_array($nuevo, $permitidos[$p->estado] ?? [], true)) {
                throw new NegocioException("No se puede pasar una producción {$p->estado} a {$nuevo}.");
            }
            if ($nuevo === 'TERMINADA' && $this->operaciones->lotesDeProduccion($id)->isEmpty()) {
                throw new NegocioException('Registra al menos un lote antes de terminar la producción.');
            }
            if ($nuevo === 'CANCELADA' && $this->operaciones->produccionTieneCompras($id)) {
                throw new NegocioException('No puedes cancelar una producción con compras de FEDER asociadas.');
            }
            $this->operaciones->actualizarProduccion($id, ['estado' => $nuevo]);
            $this->auditoria->operacion($usuario->id, 'MODIFICAR', 'PRODUCCION', $id, ['estado_anterior' => $p->estado, 'estado_nuevo' => $nuevo], $ip);
        });

        return ['message' => 'Estado de la producción actualizado.'];
    }

    /**
     * Alta o edición de un lote de origen por su productor (R039 y R040).
     * - Alta: solo en una producción EN_CURSO o TERMINADA.
     * - Edición sin compras: el titular corrige cualquier dato.
     * - Edición con compras de FEDER (existe detalle_compra, aunque esté cancelado): la fecha de elaboración, el vencimiento
     *   y la cantidad producida quedan congelados, porque ya alimentan la trazabilidad y el inventario de FEDER. El titular
     *   solo ajusta su oferta (cantidad, nunca por debajo de lo comprado, y precio pedido para lo que aún no se compró).
     */
    public function guardarLote(Usuario $usuario, int $produccionId, ?int $loteId, array $datos, ?string $ip): array
    {
        return DB::transaction(function () use ($usuario, $produccionId, $loteId, $datos, $ip) {
            $produccion = $this->operaciones->produccionDelUsuario($produccionId, $usuario->id, bloquear: true)
                ?? throw new NegocioException('Esa producción no existe o no te pertenece.', 404);
            $fila = $this->normalizarLote($datos, $produccion->unidad_medida);

            return $loteId === null
                ? $this->registrarLote($usuario, $produccion, $fila, $ip)
                : $this->modificarLote($usuario, $produccion, $loteId, $fila, $ip);
        });
    }

    private function registrarLote(Usuario $usuario, object $produccion, array $fila, ?string $ip): array
    {
        if (! in_array($produccion->estado, self::ESTADOS_CON_LOTES, true)) {
            throw new NegocioException($produccion->estado === 'PLANIFICADA'
                ? 'Inicia la producción (pásala a «en curso») antes de registrar sus lotes.'
                : 'No se registran lotes en una producción cancelada.');
        }
        $this->exigirElaboracionDesdeInicio($fila['fecha_elaboracion'], $produccion->fecha_inicio);

        $id = $this->operaciones->crearLote((int) $produccion->id, $fila);
        $this->auditoria->operacion($usuario->id, 'CREAR', 'LOTE_PRODUCCION', $id, [
            'produccion_id' => (int) $produccion->id,
            'cantidad_producida' => (float) $fila['cantidad_producida'], 'cantidad_ofrecida' => (float) $fila['cantidad_ofrecida'],
        ], $ip);

        return ['id' => $id, 'message' => 'Lote registrado y ofrecido a FEDER.'];
    }

    private function modificarLote(Usuario $usuario, object $produccion, int $loteId, array $fila, ?string $ip): array
    {
        $actual = $this->operaciones->loteDelUsuario($loteId, $usuario->id, bloquear: true)
            ?? throw new NegocioException('Ese lote no existe o no te pertenece.', 404);
        if ((int) $actual->produccion_id !== (int) $produccion->id) {
            throw new NegocioException('El lote no pertenece a esa producción.', 409);
        }
        if ($produccion->estado === 'CANCELADA') {
            throw new NegocioException('Los lotes de una producción cancelada ya no se modifican.');
        }

        $cambios = $this->cambiosDeLote($actual, $fila);
        $tieneCompras = (bool) $actual->tiene_compras;
        if ($tieneCompras && array_intersect(array_keys($cambios), self::DATOS_DE_ORIGEN) !== []) {
            throw new NegocioException("El lote {$actual->codigo_lote} ya figura en una compra de FEDER: su fecha de elaboración, ".
                'su vencimiento y su cantidad producida ya no los cambia el productor. Solo puedes ajustar la cantidad ofrecida y '.
                'el precio pedido. Si hay un error en esos datos, pide la corrección a la administración de FEDER.');
        }
        if (isset($cambios['fecha_elaboracion'])) {
            $this->exigirElaboracionDesdeInicio($fila['fecha_elaboracion'], $produccion->fecha_inicio);
        }
        if ((float) $fila['cantidad_ofrecida'] + 0.0005 < (float) $actual->comprometido) {
            throw ValidationException::withMessages(['cantidad_ofrecida' => ['La oferta no puede ser menor que la cantidad ya comprada por FEDER.']]);
        }
        if ($cambios === []) {
            return ['id' => $loteId, 'message' => 'No hiciste cambios en el lote.'];
        }

        // Con compras solo se escriben las columnas de la oferta: los datos de origen no se tocan ni por redondeo.
        $this->operaciones->actualizarLote($loteId, $tieneCompras
            ? ['cantidad_ofrecida' => $fila['cantidad_ofrecida'], 'precio_ofrecido' => $fila['precio_ofrecido']]
            : $fila);
        $this->operaciones->sincronizarEstadoLote($loteId);
        $this->auditoria->operacion($usuario->id, 'MODIFICAR', 'LOTE_PRODUCCION', $loteId,
            ['produccion_id' => (int) $produccion->id, 'tiene_compras' => $tieneCompras, 'cambios' => $cambios], $ip);

        return ['id' => $loteId, 'message' => 'Lote actualizado.'];
    }

    /**
     * Diferencias entre el lote guardado y lo pedido: [campo => ['anterior' => …, 'nuevo' => …]].
     * Es lo que queda en la bitácora, para saber qué valor había antes de cada cambio.
     */
    private function cambiosDeLote(object $actual, array $fila): array
    {
        $cambios = [];
        foreach (['fecha_elaboracion', 'fecha_vencimiento'] as $campo) {
            $anterior = $actual->{$campo} !== null ? Carbon::parse($actual->{$campo})->toDateString() : null;
            $nuevo = $fila[$campo] !== null ? Carbon::parse($fila[$campo])->toDateString() : null;
            if ($anterior !== $nuevo) {
                $cambios[$campo] = ['anterior' => $anterior, 'nuevo' => $nuevo];
            }
        }
        foreach (['cantidad_producida' => 0.0005, 'cantidad_ofrecida' => 0.0005, 'precio_ofrecido' => 0.005] as $campo => $tolerancia) {
            $anterior = $actual->{$campo} !== null ? (float) $actual->{$campo} : null;
            $nuevo = $fila[$campo] !== null ? (float) $fila[$campo] : null;
            if (($anterior === null) !== ($nuevo === null) || ($anterior !== null && abs($anterior - $nuevo) >= $tolerancia)) {
                $cambios[$campo] = ['anterior' => $anterior, 'nuevo' => $nuevo];
            }
        }

        return $cambios;
    }

    public function retirarLote(Usuario $usuario, int $loteId, ?string $ip): array
    {
        DB::transaction(function () use ($usuario, $loteId, $ip) {
            $lote = $this->operaciones->loteDelUsuario($loteId, $usuario->id, bloquear: true)
                ?? throw new NegocioException('Ese lote no existe o no te pertenece.', 404);
            if ((float) $lote->comprometido > 0.0005) {
                throw new NegocioException('No puedes retirar un lote que ya fue incluido en una compra de FEDER.');
            }
            $this->operaciones->actualizarLote($loteId, ['cantidad_ofrecida' => 0, 'estado' => 'RETIRADO']);
            $this->auditoria->operacion($usuario->id, 'MODIFICAR', 'LOTE_PRODUCCION', $loteId, ['operacion' => 'RETIRAR_OFERTA'], $ip);
        });

        return ['message' => 'Oferta retirada. El lote permanece en el historial.'];
    }

    public function proponerProducto(Usuario $usuario, array $datos, ?string $ip): array
    {
        return DB::transaction(function () use ($usuario, $datos, $ip) {
            $this->productor($usuario->id, bloquear: true);
            $pendiente = $this->operaciones->operacionesJson(ConfirmacionOperacion::SOLICITUD_PRODUCTO, $usuario->id)
                ->contains(fn ($s) => ($s->parametros['estado'] ?? null) === 'PENDIENTE'
                    && mb_strtolower($s->parametros['nombre'] ?? '') === mb_strtolower(trim($datos['nombre'])));
            if ($pendiente) {
                throw ValidationException::withMessages(['nombre' => ['Ya tienes una propuesta pendiente con ese nombre.']]);
            }
            $solicitud = $this->operaciones->crearOperacionJson($usuario->id, $usuario->correo, ConfirmacionOperacion::SOLICITUD_PRODUCTO, [
                'estado' => 'PENDIENTE', 'nombre' => trim($datos['nombre']), 'descripcion' => trim($datos['descripcion']),
                'presentacion' => trim($datos['presentacion']), 'unidad_medida' => $datos['unidad_medida'],
                'categoria_sugerida' => trim($datos['categoria_sugerida']), 'enviada_en' => now()->toIso8601String(),
            ]);
            $this->auditoria->operacion($usuario->id, 'CREAR', 'SOLICITUD_PRODUCTO', $solicitud->id, ['nombre' => $datos['nombre']], $ip);

            return ['id' => $solicitud->id, 'message' => 'Propuesta enviada. FEDER revisará el producto antes de habilitarlo.'];
        });
    }

    public function propuestasProducto(?int $usuarioId = null): array
    {
        return $this->operaciones->operacionesJson(ConfirmacionOperacion::SOLICITUD_PRODUCTO, $usuarioId)
            ->map(fn ($s) => ['id' => $s->id, 'productor_usuario_id' => (int) $s->usuario_id, 'correo' => $s->correo,
                'creada_en' => $s->creada_en?->toIso8601String()] + $s->parametros)->all();
    }

    public function resolverPropuestaProducto(Usuario $personal, int $id, bool $aprobar, array $datos, ?string $ip): array
    {
        return DB::transaction(function () use ($personal, $id, $aprobar, $datos, $ip) {
            $solicitud = $this->operaciones->operacionJson($id, ConfirmacionOperacion::SOLICITUD_PRODUCTO, bloquear: true)
                ?? throw new NegocioException('Esa propuesta no existe.', 404);
            $p = $solicitud->parametros;
            if (($p['estado'] ?? null) !== 'PENDIENTE') {
                throw new NegocioException('La propuesta ya fue revisada.');
            }
            if ($aprobar) {
                $producto = $this->productos->guardar($personal, null, $datos, $ip);
                $p += ['producto_id' => $producto['id']];
                $p['estado'] = 'APROBADA';
                $mensaje = 'Propuesta aprobada y producto creado.';
            } else {
                $p['estado'] = 'RECHAZADA';
                $p['motivo'] = trim($datos['motivo'] ?? 'La propuesta no cumple los criterios de FEDER.');
                $mensaje = 'Propuesta rechazada.';
            }
            $p['revisada_en'] = now()->toIso8601String();
            $p['revisor_id'] = $personal->id;
            $this->operaciones->actualizarOperacionJson($solicitud, $p);
            $this->auditoria->operacion($personal->id, $aprobar ? 'APROBAR_SOLICITUD' : 'RECHAZAR_SOLICITUD', 'SOLICITUD_PRODUCTO', $id, [], $ip);

            return ['message' => $mensaje];
        });
    }

    public function actualizarFicha(Usuario $usuario, array $datos, ?string $ip): array
    {
        return DB::transaction(function () use ($usuario, $datos, $ip) {
            $productor = $this->productor($usuario->id, bloquear: true);
            $this->operaciones->actualizarProductor($productor->id, ['ubicacion_actividad' => trim($datos['ubicacion_actividad'])]);
            $mensaje = 'Ficha de productor actualizada.';
            if (! empty($datos['comunidad_id']) && (int) $datos['comunidad_id'] !== (int) $productor->comunidad_agricola_id) {
                $existente = $this->operaciones->operacionesJson(ConfirmacionOperacion::CAMBIO_COMUNIDAD_PRODUCTOR, $usuario->id)
                    ->first(fn ($s) => ($s->parametros['estado'] ?? null) === 'PENDIENTE');
                if (! $existente) {
                    $this->operaciones->crearOperacionJson($usuario->id, $usuario->correo, ConfirmacionOperacion::CAMBIO_COMUNIDAD_PRODUCTOR, [
                        'estado' => 'PENDIENTE', 'productor_id' => (int) $productor->id,
                        'comunidad_anterior_id' => (int) $productor->comunidad_agricola_id,
                        'comunidad_nueva_id' => (int) $datos['comunidad_id'], 'enviada_en' => now()->toIso8601String(),
                    ]);
                    $mensaje .= ' El cambio de comunidad quedó pendiente de aprobación por FEDER.';
                } else {
                    $parametros = $existente->parametros;
                    $parametros['comunidad_nueva_id'] = (int) $datos['comunidad_id'];
                    $parametros['actualizada_en'] = now()->toIso8601String();
                    $this->operaciones->actualizarOperacionJson($existente, $parametros);
                    $mensaje .= ' La solicitud pendiente de cambio de comunidad fue actualizada.';
                }
            }
            $this->auditoria->operacion($usuario->id, 'MODIFICAR', 'PRODUCTOR', $productor->id, ['operacion' => 'ACTUALIZAR_FICHA'], $ip);

            return ['message' => $mensaje];
        });
    }

    public function cambiosComunidad(): array
    {
        return $this->operaciones->operacionesJson(ConfirmacionOperacion::CAMBIO_COMUNIDAD_PRODUCTOR)->map(function ($s) {
            $p = $s->parametros;
            $anterior = $this->operaciones->comunidad((int) $p['comunidad_anterior_id']);
            $nueva = $this->operaciones->comunidad((int) $p['comunidad_nueva_id']);

            return ['id' => (int) $s->id, 'correo' => $s->correo, 'estado' => $p['estado'] ?? 'PENDIENTE',
                'comunidad_anterior' => $anterior?->nombre, 'comunidad_nueva' => $nueva?->nombre,
                'municipio_nuevo' => $nueva?->municipio, 'enviada_en' => $p['enviada_en'] ?? null];
        })->all();
    }

    public function resolverCambioComunidad(Usuario $personal, int $id, bool $aprobar, ?string $motivo, ?string $ip): array
    {
        return DB::transaction(function () use ($personal, $id, $aprobar, $motivo, $ip) {
            // Tanto la edición propia como la revisión bloquean primero la ficha y después su solicitud.
            $pendiente = $this->operaciones->operacionJson($id, ConfirmacionOperacion::CAMBIO_COMUNIDAD_PRODUCTOR)
                ?? throw new NegocioException('Esa solicitud no existe.', 404);
            $this->operaciones->fichaDeUsuario((int) $pendiente->usuario_id, bloquear: true);
            $solicitud = $this->operaciones->operacionJson($id, ConfirmacionOperacion::CAMBIO_COMUNIDAD_PRODUCTOR, bloquear: true)
                ?? throw new NegocioException('Esa solicitud no existe.', 404);
            $p = $solicitud->parametros;
            if (($p['estado'] ?? null) !== 'PENDIENTE') {
                throw new NegocioException('La solicitud ya fue revisada.');
            }
            if ($aprobar) {
                $comunidad = $this->operaciones->comunidad((int) $p['comunidad_nueva_id']);
                if (! $comunidad?->activa) {
                    throw new NegocioException('La comunidad elegida debe estar activa antes de aprobar el cambio.');
                }
                $this->operaciones->actualizarProductor((int) $p['productor_id'], ['comunidad_agricola_id' => (int) $p['comunidad_nueva_id']]);
                $p['estado'] = 'APROBADA';
            } else {
                $p['estado'] = 'RECHAZADA';
                $p['motivo'] = trim($motivo ?? '');
            }
            $p['revisada_en'] = now()->toIso8601String();
            $p['revisor_id'] = $personal->id;
            $this->operaciones->actualizarOperacionJson($solicitud, $p);
            $this->auditoria->operacion($personal->id, $aprobar ? 'APROBAR_SOLICITUD' : 'RECHAZAR_SOLICITUD', 'CAMBIO_COMUNIDAD_PRODUCTOR', $id, [], $ip);

            return ['message' => $aprobar ? 'Cambio de comunidad aprobado.' : 'Cambio de comunidad rechazado.'];
        });
    }

    public function lotesDisponiblesParaCompra(): array
    {
        return $this->operaciones->lotesOfertados()->map(function ($l) {
            $disponible = max(0, (float) $l->cantidad_ofrecida - (float) $l->comprometido);

            return [
                'id' => (int) $l->id, 'codigo' => $l->codigo_lote, 'produccion_id' => (int) $l->produccion_id,
                'produccion' => $l->produccion, 'producto' => $l->producto, 'unidad_medida' => $l->unidad_medida,
                'productor_id' => (int) $l->productor_id, 'productor' => $l->productor, 'codigo_productor' => $l->codigo_productor,
                'producido' => (float) $l->cantidad_producida, 'ofrecido' => (float) $l->cantidad_ofrecida,
                'comprometido' => (float) $l->comprometido, 'disponible' => $disponible,
                'precio_ofrecido' => $l->precio_ofrecido !== null ? (float) $l->precio_ofrecido : null,
                'elaborado' => $l->fecha_elaboracion, 'vence' => $l->fecha_vencimiento,
            ];
        })->filter(fn ($l) => $l['disponible'] > 0.0005)->values()->all();
    }

    public function crearCompra(Usuario $personal, array $datos, ?string $ip): array
    {
        return DB::transaction(function () use ($personal, $datos, $ip) {
            $productorId = null;
            $lineas = [];
            $lotes = $this->operaciones->bloquearLotesParaCompra(array_column($datos['lineas'], 'lote_id'));
            foreach ($datos['lineas'] as $entrada) {
                $lote = $lotes->get((int) $entrada['lote_id'])
                    ?? throw new NegocioException('Uno de los lotes ya no existe.', 404);
                if ($lote->estado !== 'DISPONIBLE') {
                    throw new NegocioException("El lote {$lote->codigo_lote} ya no está disponible.");
                }
                if ($productorId === null) {
                    // R004: con el lote ya bloqueado, el productor debe seguir habilitado en este momento.
                    $productor = $this->exigirProductorHabilitado((int) $lote->productor_id);
                    $this->exigirOtroOperador($personal, (int) $productor->usuario_id);
                    $productorId = (int) $lote->productor_id;
                }
                if ($productorId !== (int) $lote->productor_id) {
                    throw new NegocioException('Cada compra debe corresponder a un solo productor.');
                }
                if (! $this->operaciones->productoParaCompra((int) $lote->producto_id)?->activo) {
                    throw new NegocioException("El producto «{$lote->producto}» del lote {$lote->codigo_lote} está inactivo: no se registran compras de productos inactivos.");
                }
                $cantidad = (float) $entrada['cantidad'];
                $disponible = (float) $lote->cantidad_ofrecida - (float) $lote->comprometido;
                if ($cantidad > $disponible + 0.0005) {
                    throw ValidationException::withMessages(['lineas' => ["El lote {$lote->codigo_lote} solo tiene {$disponible} disponibles."]]);
                }
                if ($lote->unidad_medida === 'UNIDAD' && ! Cantidad::esEntera($cantidad)) {
                    throw ValidationException::withMessages(['lineas' => ["{$lote->producto} se compra en unidades enteras."]]);
                }
                $lineas[] = ['lote_id' => (int) $lote->id, 'cantidad' => Cantidad::texto($cantidad), 'precio' => Cantidad::monto((float) $entrada['precio'])];
            }
            $compra = $this->operaciones->crearCompra($productorId, $lineas, $datos['observacion'] ?? null);
            foreach ($lineas as $linea) {
                $this->operaciones->sincronizarEstadoLote($linea['lote_id']);
            }
            $this->operaciones->crearOperacionJson($personal->id, $personal->correo, ConfirmacionOperacion::LOGISTICA_COMPRA, [
                'compra_id' => $compra['compra_id'], 'modalidad' => $datos['modalidad_traslado'],
                'fecha_acordada' => $datos['fecha_acordada'] ?? null, 'responsable' => $datos['responsable_traslado'] ?? null,
                'telefono' => $datos['telefono_traslado'] ?? null, 'estado' => 'PENDIENTE',
            ]);
            $this->auditoria->operacion($personal->id, 'CREAR', 'COMPRA_FEDER', $compra['compra_id'], ['codigo' => $compra['codigo']], $ip);

            return ['id' => $compra['compra_id'], 'message' => "Compra {$compra['codigo']} registrada por Bs ".number_format($compra['total'], 2, ',', '.').'.'];
        });
    }

    public function detalleCompra(int $id): array
    {
        $c = $this->operaciones->compra($id) ?? throw new NegocioException('Esa compra no existe.', 404);
        $logistica = $this->operaciones->operacionesJson(ConfirmacionOperacion::LOGISTICA_COMPRA)
            ->first(fn ($o) => (int) ($o->parametros['compra_id'] ?? 0) === $id)?->parametros;

        return [
            'id' => (int) $c->id, 'codigo' => $c->codigo, 'estado' => $c->estado, 'fecha' => $c->fecha_compra,
            'total' => (float) $c->total, 'pagada_el' => $c->fecha_pago_productor, 'observacion' => $c->observacion,
            'productor' => ['id' => (int) $c->productor_id, 'codigo' => $c->codigo_productor, 'nombre' => $c->productor],
            'logistica' => $logistica,
            'lineas' => $this->operaciones->detallesCompra($id)->map(fn ($d) => [
                'id' => (int) $d->id, 'lote' => $d->codigo_lote, 'producto' => $d->producto, 'unidad_medida' => $d->unidad_medida,
                'comprada' => (float) $d->cantidad_comprada, 'precio' => (float) $d->precio_unitario,
                'recibida' => (float) $d->cantidad_recibida, 'rechazada' => (float) $d->cantidad_rechazada,
                'cancelada' => (float) $d->cantidad_cancelada,
            ])->all(),
        ];
    }

    public function recibirCompra(Usuario $personal, int $id, array $datos, ?string $ip): array
    {
        DB::transaction(function () use ($personal, $id, $datos, $ip) {
            $compra = $this->operaciones->compra($id, bloquear: true) ?? throw new NegocioException('Esa compra no existe.', 404);
            $this->exigirOtroOperador($personal, (int) $compra->usuario_id);
            if (! in_array($compra->estado, ['REGISTRADA', 'RECIBIDA_PARCIAL'], true)) {
                throw new NegocioException('Esa compra ya no admite recepción.');
            }
            $huboEntrada = false;
            $detalles = $this->operaciones->detallesCompra($id, bloquear: true)->keyBy('id');
            foreach ($datos['lineas'] as $entrada) {
                $d = $detalles->get((int) $entrada['detalle_id']);
                if (! $d) {
                    throw new NegocioException('Una línea no pertenece a esta compra.', 409);
                }
                $recibida = (float) $entrada['recibida'];
                $rechazada = (float) $entrada['rechazada'];
                $cancelada = (float) $entrada['cancelada'];
                if ($recibida + $rechazada + $cancelada > (float) $d->cantidad_comprada + 0.0005) {
                    throw ValidationException::withMessages(['lineas' => ["Las cantidades de {$d->producto} superan lo comprado."]]);
                }
                if ($recibida + 0.0005 < (float) $d->cantidad_recibida) {
                    throw ValidationException::withMessages(['lineas' => ["No se puede reducir lo ya recibido de {$d->producto}."]]);
                }
                if ($rechazada + 0.0005 < (float) $d->cantidad_rechazada || $cancelada + 0.0005 < (float) $d->cantidad_cancelada) {
                    throw ValidationException::withMessages(['lineas' => ["No se puede reducir una cantidad ya rechazada o cancelada de {$d->producto}."]]);
                }
                foreach ([$recibida, $rechazada, $cancelada] as $cantidad) {
                    if ($d->unidad_medida === 'UNIDAD' && ! Cantidad::esEntera($cantidad)) {
                        throw ValidationException::withMessages(['lineas' => ["{$d->producto} requiere cantidades enteras."]]);
                    }
                }
                // Lo que esta recepción agrega a cada cantidad de la línea (la petición trae los acumulados).
                $aceptado = $recibida - (float) $d->cantidad_recibida;
                $huboEntrada = $huboEntrada || $aceptado > 0.0005;
                $rechazado = $rechazada - (float) $d->cantidad_rechazada;
                $cancelado = $cancelada - (float) $d->cantidad_cancelada;
                // R035: no entra como vendible mercadería de un lote de origen ya vencido; se registra como rechazada.
                if ($aceptado > 0.0005 && $d->fecha_vencimiento !== null && Carbon::parse($d->fecha_vencimiento)->startOfDay()->lt(today())) {
                    throw ValidationException::withMessages(['lineas' => ["El lote de origen {$d->codigo_lote} venció el "
                        .Carbon::parse($d->fecha_vencimiento)->format('d/m/Y').': esa mercadería no se acepta; regístrala como rechazada.']]);
                }
                $this->operaciones->actualizarDetalleCompra($d->id, [
                    'cantidad_recibida' => Cantidad::texto($recibida), 'cantidad_rechazada' => Cantidad::texto($rechazada),
                    'cantidad_cancelada' => Cantidad::texto($cancelada),
                ]);
                $this->registrarRecepcionDeLinea($personal, $id, $d, $aceptado, $rechazado, $cancelado, $datos, $ip);
                $this->operaciones->sincronizarEstadoLote((int) $d->lote_origen_id);
            }
            $actualizados = $this->operaciones->detallesCompra($id);
            $comprado = $actualizados->sum(fn ($d) => (float) $d->cantidad_comprada);
            $recibido = $actualizados->sum(fn ($d) => (float) $d->cantidad_recibida);
            $procesado = $actualizados->sum(fn ($d) => (float) $d->cantidad_recibida + (float) $d->cantidad_rechazada + (float) $d->cantidad_cancelada);
            $estado = $procesado + 0.0005 < $comprado ? 'RECIBIDA_PARCIAL' : ($recibido > 0.0005 ? 'RECIBIDA' : 'CANCELADA');
            $actualizacion = ['estado' => $estado, 'observacion' => $datos['observacion'] ?? $compra->observacion];
            if (in_array($estado, ['RECIBIDA', 'CANCELADA'], true)) {
                $actualizacion['total'] = Cantidad::monto($actualizados->sum(fn ($d) => (float) $d->cantidad_recibida * (float) $d->precio_unitario));
            }
            $this->operaciones->actualizarCompra($id, $actualizacion);
            $this->auditoria->operacion($personal->id, 'MODIFICAR', 'COMPRA_FEDER', $id, ['operacion' => 'RECEPCION', 'estado' => $estado], $ip);
            if ($huboEntrada) {
                AbastecimientoService::revisarTrasCommit();
            }
        });

        return ['message' => 'Recepción registrada.'];
    }

    /**
     * Movimiento de inventario y bitácora de una línea recibida, como exige la trazabilidad aprobada
     * (ck_bitacora_operacion_detalle_compra, R013/R014):
     * - RECEPCION (acción MODIFICAR, entidad DETALLE_COMPRA): lo aceptado y lo rechazado en esta recepción, con la misma
     *   clave de operación que el movimiento ENTRADA_COMPRA. Es la fila que consulta rg_detalle_compra_precio_fijo.
     * - CANCELACION (acción ANULAR): lo que se cancela de lo pendiente, con su propia clave.
     */
    private function registrarRecepcionDeLinea(Usuario $personal, int $compraId, object $d, float $aceptado, float $rechazado,
        float $cancelado, array $datos, ?string $ip): void
    {
        $observacion = isset($datos['observacion']) && trim((string) $datos['observacion']) !== '' ? trim((string) $datos['observacion']) : null;

        if ($aceptado > 0.0005 || $rechazado > 0.0005) {
            $clave = $this->operaciones->nuevaClaveOperacion('ENTRADA_COMPRA', (int) $d->id);
            $loteFederId = $aceptado > 0.0005
                ? $this->operaciones->ingresarInventario((int) $d->id, Cantidad::texto($aceptado), $personal->id, $datos['ubicacion_almacen'] ?? 'Almacén FEDER', $clave)
                : null;
            $this->auditoria->operacion($personal->id, 'MODIFICAR', 'DETALLE_COMPRA', (int) $d->id, [
                'clave_operacion' => $clave, 'operacion' => 'RECEPCION', 'compra_id' => $compraId,
                'cantidad_aceptada' => round(max($aceptado, 0.0), 3), 'cantidad_rechazada' => round(max($rechazado, 0.0), 3),
                'lote_feder_id' => $loteFederId,
                'motivo' => $observacion ?? ($rechazado > 0.0005 ? 'Recepción con unidades rechazadas' : 'Recepción conforme'),
            ], $ip);
        }

        if ($cancelado > 0.0005) {
            $this->auditoria->operacion($personal->id, 'ANULAR', 'DETALLE_COMPRA', (int) $d->id, [
                'clave_operacion' => $this->operaciones->nuevaClaveOperacion('CANCELACION', (int) $d->id),
                'operacion' => 'CANCELACION', 'compra_id' => $compraId, 'cantidad_cancelada' => round($cancelado, 3),
                'motivo' => $observacion ?? 'Cantidad pendiente cancelada en la recepción',
            ], $ip);
        }
    }

    public function pagarCompra(Usuario $personal, int $id, ?string $ip): array
    {
        DB::transaction(function () use ($personal, $id, $ip) {
            $compra = $this->operaciones->compra($id, bloquear: true) ?? throw new NegocioException('Esa compra no existe.', 404);
            $this->exigirOtroOperador($personal, (int) $compra->usuario_id);
            if ($compra->estado !== 'RECIBIDA') {
                throw new NegocioException('Solo se paga una compra completamente recibida.');
            }
            if ($compra->fecha_pago_productor !== null) {
                throw new NegocioException('Esta compra ya fue pagada.');
            }
            $this->operaciones->actualizarCompra($id, ['fecha_pago_productor' => now()]);
            $this->auditoria->operacion($personal->id, 'MODIFICAR', 'COMPRA_FEDER', $id, ['operacion' => 'PAGAR_PRODUCTOR'], $ip);
        });

        return ['message' => 'Pago al productor registrado.'];
    }

    public function ventaDelProductor(Usuario $usuario, int $id): array
    {
        $productor = $this->productor($usuario->id);
        $compra = $this->operaciones->compra($id);
        // Mismo mensaje si la compra no existe o es de otro productor: no se revela que existe.
        if ($compra === null || (int) $compra->productor_id !== (int) $productor->id) {
            throw new NegocioException('Esa venta no existe.', 404);
        }

        return $this->detalleCompra($id);
    }

    private function productor(int $usuarioId, bool $bloquear = false): object
    {
        return $this->operaciones->productorActivoDeUsuario($usuarioId, $bloquear)
            ?? throw new NegocioException('Tu ficha de productor no está aprobada o se encuentra inactiva.', 403);
    }

    /**
     * R004 «Compra autorizada»: solo se compra a un productor con la solicitud aprobada, la ficha y la cuenta activas
     * y el rol PRODUCTOR vigente. La ficha queda bloqueada hasta que termina la transacción de la compra.
     */
    private function exigirProductorHabilitado(int $productorId): object
    {
        $p = $this->operaciones->productorParaCompra($productorId);
        $habilitado = $p !== null && $p->estado_solicitud === 'APROBADA' && $p->activo && $p->cuenta_activa && $p->rol_vigente;
        if (! $habilitado) {
            $codigo = $p?->codigo_productor ?? 'elegido';
            throw new NegocioException("El productor {$codigo} no está habilitado para vender a FEDER ".
                '(necesita la solicitud aprobada, la ficha y la cuenta activas y el rol de productor vigente).');
        }

        return $p;
    }

    private function exigirOtroOperador(Usuario $personal, int $titularId): void
    {
        if ((int) $personal->id === $titularId) {
            throw new NegocioException('Otro operador de FEDER debe gestionar la compra, la recepción y el pago de tus lotes.', 403);
        }
    }

    private function normalizarProduccion(array $datos, string $unidad): array
    {
        if ($unidad === 'UNIDAD' && ! Cantidad::esEntera((float) $datos['cantidad_estimada'])) {
            throw ValidationException::withMessages(['cantidad_estimada' => ['Para productos por unidad, la cantidad estimada debe ser entera.']]);
        }

        return [
            'producto_id' => (int) $datos['producto_id'], 'fecha_inicio' => $datos['fecha_inicio'],
            'fecha_disponibilidad_estimada' => $datos['fecha_disponibilidad_estimada'],
            'cantidad_estimada' => Cantidad::texto((float) $datos['cantidad_estimada']),
            'ubicacion_produccion' => trim($datos['ubicacion_produccion']), 'observacion' => $datos['observacion'] ?? null,
        ];
    }

    /**
     * R040: la elaboración de un lote no es anterior al inicio de su producción. La base también lo impide
     * (rg_lote_produccion_elaboracion_desde_inicio); aquí se avisa antes y en el campo que corresponde.
     */
    private function exigirElaboracionDesdeInicio(?string $elaboracion, ?string $inicio): void
    {
        if ($elaboracion === null || $inicio === null) {
            return;
        }
        if (Carbon::parse($elaboracion)->startOfDay()->lt(Carbon::parse($inicio)->startOfDay())) {
            throw ValidationException::withMessages(['fecha_elaboracion' => [
                'La fecha de elaboración no puede ser anterior al inicio de la producción ('.Carbon::parse($inicio)->format('d/m/Y').').',
            ]]);
        }
    }

    private function normalizarLote(array $datos, string $unidad): array
    {
        foreach (['cantidad_producida', 'cantidad_ofrecida'] as $campo) {
            if ($unidad === 'UNIDAD' && ! Cantidad::esEntera((float) $datos[$campo])) {
                throw ValidationException::withMessages([$campo => ['Para productos por unidad, la cantidad debe ser entera.']]);
            }
        }

        return [
            'fecha_elaboracion' => $datos['fecha_elaboracion'] ?? null, 'fecha_vencimiento' => $datos['fecha_vencimiento'] ?? null,
            'cantidad_producida' => Cantidad::texto((float) $datos['cantidad_producida']),
            'cantidad_ofrecida' => Cantidad::texto((float) $datos['cantidad_ofrecida']),
            'precio_ofrecido' => isset($datos['precio_ofrecido']) ? Cantidad::monto((float) $datos['precio_ofrecido']) : null,
        ];
    }

    private function formatearProduccion(object $p): array
    {
        return [
            'id' => (int) $p->id, 'codigo' => $p->codigo, 'producto_id' => (int) $p->producto_id, 'producto' => $p->producto,
            'presentacion' => $p->presentacion, 'unidad_medida' => $p->unidad_medida, 'estado' => $p->estado,
            'fecha_inicio' => $p->fecha_inicio, 'fecha_disponibilidad_estimada' => $p->fecha_disponibilidad_estimada,
            'cantidad_estimada' => $p->cantidad_estimada !== null ? (float) $p->cantidad_estimada : null,
            'ubicacion_produccion' => $p->ubicacion_produccion, 'observacion' => $p->observacion,
            'comunidad' => $p->nombre_comunidad_origen,
        ];
    }

    private function formatearLote(object $l): array
    {
        return [
            'id' => (int) $l->id, 'codigo' => $l->codigo_lote, 'fecha_elaboracion' => $l->fecha_elaboracion,
            'fecha_vencimiento' => $l->fecha_vencimiento, 'cantidad_producida' => (float) $l->cantidad_producida,
            'cantidad_ofrecida' => (float) $l->cantidad_ofrecida, 'precio_ofrecido' => $l->precio_ofrecido !== null ? (float) $l->precio_ofrecido : null,
            'estado' => $l->estado, 'comprometido' => (float) $l->comprometido,
            // Sin cantidad comprometida: se puede retirar la oferta (la pantalla usa este dato para ese botón).
            'puede_editar' => (float) $l->comprometido <= 0.0005,
            // Con alguna compra de FEDER (aunque esté cancelada) los datos de origen quedan congelados (R040);
            // el productor solo ajusta la cantidad ofrecida y el precio pedido.
            'tiene_compras' => (bool) $l->tiene_compras,
            'puede_editar_datos_de_origen' => ! $l->tiene_compras,
        ];
    }
}
