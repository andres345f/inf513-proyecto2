<?php

namespace App\Repositories;

use App\Models\ConfirmacionOperacion;
use App\Support\EstructuraFilaWeb;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Escritura y detalle del flujo Productor → oferta → compra FEDER → inventario. */
class OperacionProductorRepository
{
    private const VENCE_NUNCA = '9999-12-31 23:59:59-04';

    /**
     * Condición SQL: el lote de origen «l» figura en alguna compra de FEDER (aunque la línea esté cancelada).
     * Es el criterio del Proyecto 1 (LoteProduccionRepositorio.tieneCompras) para congelar sus datos de origen (R040).
     */
    private const TIENE_COMPRAS = 'EXISTS (SELECT 1 FROM detalle_compra dc WHERE dc.lote_origen_id = l.id)';

    /** Condición SQL: la cuenta del productor «po» tiene una concesión PRODUCTOR vigente. */
    private const ROL_PRODUCTOR_VIGENTE = "EXISTS (SELECT 1 FROM usuario_rol ur JOIN rol r ON r.id = ur.rol_id
        WHERE ur.usuario_id = po.usuario_id AND r.codigo = 'PRODUCTOR' AND ur.estado = 'VIGENTE')";

    public function productorActivoDeUsuario(int $usuarioId, bool $bloquear = false): ?object
    {
        $consulta = DB::table('productor as p')->join('comunidad_agricola as c', 'c.id', '=', 'p.comunidad_agricola_id')
            ->where('p.usuario_id', $usuarioId)->where('p.estado_solicitud', 'APROBADA')->where('p.activo', true);

        return ($bloquear ? $consulta->lock('FOR UPDATE OF p') : $consulta)->first(['p.*', 'c.nombre as comunidad', 'c.municipio', 'c.departamento']);
    }

    public function productosDisponibles(): Collection
    {
        return DB::table('producto as p')->join('categoria as c', 'c.id', '=', 'p.categoria_id')
            ->where('p.activo', true)->orderBy('p.nombre')
            ->get(['p.id', 'p.codigo', 'p.nombre', 'p.presentacion', 'p.unidad_medida', 'c.nombre as categoria']);
    }

    public function productoDisponible(int $id): ?object
    {
        return DB::table('producto')->where('id', $id)
            ->first(['id', 'nombre', 'unidad_medida', 'activo']);
    }

    public function actualizarProductor(int $id, array $datos): void
    {
        DB::table('productor')->where('id', $id)->update($datos);
    }

    public function comunidad(int $id): ?object
    {
        return DB::table('comunidad_agricola')->where('id', $id)->first(['id', 'nombre', 'municipio', 'activa']);
    }

    public function crearProduccion(object $productor, array $datos): int
    {
        $id = $this->siguienteId('produccion');
        DB::table('produccion')->insert([
            'id' => $id,
            'productor_id' => $productor->id,
            'producto_id' => $datos['producto_id'],
            'comunidad_origen_id' => $productor->comunidad_agricola_id,
            'codigo' => sprintf('PRD-%s-%03d', now()->format('Y'), $id),
            'fecha_inicio' => $datos['fecha_inicio'],
            'fecha_disponibilidad_estimada' => $datos['fecha_disponibilidad_estimada'],
            'cantidad_estimada' => $datos['cantidad_estimada'],
            'ubicacion_produccion' => $datos['ubicacion_produccion'],
            'estado' => 'PLANIFICADA',
            'observacion' => $datos['observacion'] ?? null,
            'nombre_comunidad_origen' => $productor->comunidad,
            'municipio_origen' => $productor->municipio,
            'departamento_origen' => $productor->departamento,
        ]);

        return $id;
    }

    public function produccionDelUsuario(int $id, int $usuarioId, bool $bloquear = false): ?object
    {
        $consulta = DB::table('produccion as pr')->join('productor as po', 'po.id', '=', 'pr.productor_id')
            ->join('producto as p', 'p.id', '=', 'pr.producto_id')
            ->where('pr.id', $id)->where('po.usuario_id', $usuarioId)
            ->select(['pr.*', 'p.nombre as producto', 'p.unidad_medida', 'p.presentacion']);

        return ($bloquear ? $consulta->lock('FOR UPDATE OF pr') : $consulta)->first();
    }

    public function actualizarProduccion(int $id, array $datos): void
    {
        DB::table('produccion')->where('id', $id)->update($datos);
    }

    public function produccionTieneCompras(int $id): bool
    {
        return DB::table('detalle_compra as d')->join('lote_produccion as l', 'l.id', '=', 'd.lote_origen_id')
            ->where('l.produccion_id', $id)->exists();
    }

    /** Fecha de elaboración más antigua entre los lotes de la producción (AAAA-MM-DD) o null si ninguno la tiene. */
    public function primeraElaboracionDeLotes(int $produccionId): ?string
    {
        return DB::table('lote_produccion')->where('produccion_id', $produccionId)->min('fecha_elaboracion');
    }

    public function lotesDeProduccion(int $produccionId): Collection
    {
        return DB::table('lote_produccion as l')->where('l.produccion_id', $produccionId)->orderByDesc('l.id')
            ->get(['l.*', DB::raw($this->compradoSql('l.id').' AS comprometido'), DB::raw(self::TIENE_COMPRAS.' AS tiene_compras')]);
    }

    public function crearLote(int $produccionId, array $datos): int
    {
        $id = $this->siguienteId('lote_produccion');
        DB::table('lote_produccion')->insert([
            'id' => $id,
            'produccion_id' => $produccionId,
            'codigo_lote' => sprintf('LP-%s-%03d', now()->format('Y'), $id),
            'fecha_elaboracion' => $datos['fecha_elaboracion'] ?? null,
            'fecha_vencimiento' => $datos['fecha_vencimiento'] ?? null,
            'cantidad_producida' => $datos['cantidad_producida'],
            'cantidad_ofrecida' => $datos['cantidad_ofrecida'],
            'precio_ofrecido' => $datos['precio_ofrecido'] ?? null,
            'estado' => (float) $datos['cantidad_ofrecida'] > 0 ? 'DISPONIBLE' : 'RETIRADO',
        ]);

        return $id;
    }

    public function loteDelUsuario(int $loteId, int $usuarioId, bool $bloquear = false): ?object
    {
        $consulta = DB::table('lote_produccion as l')->join('produccion as pr', 'pr.id', '=', 'l.produccion_id')
            ->join('productor as po', 'po.id', '=', 'pr.productor_id')->where('l.id', $loteId)->where('po.usuario_id', $usuarioId)
            ->select(['l.*', 'pr.estado as estado_produccion', DB::raw($this->compradoSql('l.id').' AS comprometido'),
                DB::raw(self::TIENE_COMPRAS.' AS tiene_compras')]);

        // La consulta que espera el bloqueo todavía ve la instantánea anterior de detalle_compra.
        // Una segunda sentencia, con el lote ya bloqueado, lee las compras que acaban de confirmarse.
        if ($bloquear && (clone $consulta)->select('l.id')->lock('FOR UPDATE OF l')->first() === null) {
            return null;
        }

        return $consulta->first();
    }

    public function actualizarLote(int $id, array $datos): void
    {
        DB::table('lote_produccion')->where('id', $id)->update($datos);
    }

    public function sincronizarEstadoLote(int $id): void
    {
        $lote = DB::table('lote_produccion as l')->where('l.id', $id)
            ->first(['l.cantidad_ofrecida', DB::raw($this->compradoSql('l.id').' AS comprometido')]);
        if (! $lote) {
            return;
        }
        $estado = (float) $lote->cantidad_ofrecida <= 0.0005
            ? 'RETIRADO'
            : ((float) $lote->cantidad_ofrecida - (float) $lote->comprometido > 0.0005 ? 'DISPONIBLE' : 'AGOTADO');
        DB::table('lote_produccion')->where('id', $id)->update(['estado' => $estado]);
    }

    public function crearOperacionJson(int $usuarioId, string $correo, string $comando, array $parametros): ConfirmacionOperacion
    {
        EstructuraFilaWeb::exigir($comando, $parametros);

        return ConfirmacionOperacion::query()->create([
            'codigo_hash' => hash('sha256', 'FEDER_WEB:'.$comando.':'.$usuarioId.':'.Str::uuid()),
            'correo' => mb_strtolower($correo),
            'usuario_id' => $usuarioId,
            'comando' => $comando,
            'parametros' => $parametros,
            'expira_en' => self::VENCE_NUNCA,
        ]);
    }

    /** Filas vigentes del comando. Las que no tienen la estructura de su tipo se descartan (con aviso) y no rompen el listado. */
    public function operacionesJson(string $comando, ?int $usuarioId = null): Collection
    {
        return ConfirmacionOperacion::query()->where('comando', $comando)
            ->when($usuarioId !== null, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->whereNull('usada_en')->orderByDesc('creada_en')->orderByDesc('id')->get()
            ->filter(fn (ConfirmacionOperacion $fila) => $this->estructuraValida($fila))->values();
    }

    public function operacionJson(int $id, string $comando, bool $bloquear = false): ?ConfirmacionOperacion
    {
        $consulta = ConfirmacionOperacion::query()->whereKey($id)->where('comando', $comando)->whereNull('usada_en');
        $fila = ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();

        return $fila !== null && $this->estructuraValida($fila) ? $fila : null;
    }

    public function actualizarOperacionJson(ConfirmacionOperacion $operacion, array $parametros): void
    {
        EstructuraFilaWeb::exigir((string) $operacion->comando, $parametros);
        $operacion->parametros = $parametros;
        $operacion->save();
    }

    private function estructuraValida(ConfirmacionOperacion $fila): bool
    {
        $errores = EstructuraFilaWeb::errores((string) $fila->comando, $fila->parametros);
        if ($errores !== []) {
            EstructuraFilaWeb::avisar((string) $fila->comando, $fila->id, $errores);
        }

        return $errores === [];
    }

    /**
     * Lotes que FEDER puede comprar hoy (R004): oferta disponible, producto activo y productor habilitado
     * (solicitud aprobada, ficha y cuenta activas y rol PRODUCTOR vigente).
     */
    public function lotesOfertados(): Collection
    {
        return DB::table('lote_produccion as l')->join('produccion as pr', 'pr.id', '=', 'l.produccion_id')
            ->join('producto as p', 'p.id', '=', 'pr.producto_id')->join('productor as po', 'po.id', '=', 'pr.productor_id')
            ->join('usuario as u', 'u.id', '=', 'po.usuario_id')
            ->where('l.estado', 'DISPONIBLE')->where('l.cantidad_ofrecida', '>', 0)
            ->where('p.activo', true)->where('po.activo', true)->where('po.estado_solicitud', 'APROBADA')->where('u.activo', true)
            ->whereRaw(self::ROL_PRODUCTOR_VIGENTE)
            ->orderBy('p.nombre')->orderBy('l.fecha_elaboracion')
            ->get(['l.id', 'l.codigo_lote', 'l.cantidad_producida', 'l.cantidad_ofrecida', 'l.precio_ofrecido',
                'l.fecha_elaboracion', 'l.fecha_vencimiento', 'pr.id as produccion_id', 'pr.codigo as produccion',
                'p.nombre as producto', 'p.unidad_medida', 'po.id as productor_id', 'po.codigo_productor',
                DB::raw("u.nombres || ' ' || u.apellidos AS productor"), DB::raw($this->compradoSql('l.id').' AS comprometido')]);
    }

    /** Bloquea en orden común y relee las cantidades comprometidas después de cualquier espera. */
    public function bloquearLotesParaCompra(array $ids): Collection
    {
        $bloqueados = DB::table('lote_produccion')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');

        return DB::table('lote_produccion as l')->join('produccion as pr', 'pr.id', '=', 'l.produccion_id')
            ->join('producto as p', 'p.id', '=', 'pr.producto_id')->whereIn('l.id', $bloqueados)
            ->get(['l.*', 'pr.productor_id', 'pr.producto_id', 'pr.codigo as produccion', 'p.nombre as producto', 'p.unidad_medida',
                DB::raw($this->compradoSql('l.id').' AS comprometido')])->keyBy('id');
    }

    /**
     * R004: estado del productor al registrar una compra. Primero se bloquea su ficha (FOR SHARE) y después, en una
     * sentencia nueva, se leen la cuenta y la concesión: una revocación simultánea espera a que termine la compra,
     * o la compra ve la revocación ya confirmada. Debe llamarse dentro de la transacción de la compra.
     */
    public function productorParaCompra(int $productorId): ?object
    {
        if (DB::table('productor')->where('id', $productorId)->lock('FOR SHARE')->first(['id']) === null) {
            return null;
        }

        return DB::table('productor as po')->join('usuario as u', 'u.id', '=', 'po.usuario_id')->where('po.id', $productorId)
            ->first(['po.id', 'po.usuario_id', 'po.codigo_productor', 'po.estado_solicitud', 'po.activo', 'u.activo as cuenta_activa',
                DB::raw('('.self::ROL_PRODUCTOR_VIGENTE.') AS rol_vigente')]);
    }

    /** R004: producto del lote con su fila bloqueada (FOR SHARE) mientras se registra la compra. */
    public function productoParaCompra(int $productoId): ?object
    {
        return DB::table('producto')->where('id', $productoId)->lock('FOR SHARE')->first(['id', 'codigo', 'nombre', 'activo']);
    }

    /** Ficha de productor de una cuenta, en cualquier estado; bloqueada cuando se cambia junto con su concesión (R073). */
    public function fichaDeUsuario(int $usuarioId, bool $bloquear = false): ?object
    {
        $consulta = DB::table('productor')->where('usuario_id', $usuarioId);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    /** @return array{compra_id:int,codigo:string,total:float} */
    public function crearCompra(int $productorId, array $lineas, ?string $observacion): array
    {
        $id = $this->siguienteId('compra_feder');
        $codigo = sprintf('CMP-%s-%03d', now()->format('Y'), $id);
        $total = round(collect($lineas)->sum(fn ($l) => round((float) $l['cantidad'] * (float) $l['precio'], 2)), 2);
        DB::table('compra_feder')->insert([
            'id' => $id, 'productor_id' => $productorId, 'codigo' => $codigo, 'fecha_compra' => now(),
            'estado' => 'REGISTRADA', 'total' => $total, 'observacion' => $observacion,
        ]);
        foreach ($lineas as $linea) {
            DB::table('detalle_compra')->insert([
                'compra_id' => $id, 'lote_origen_id' => $linea['lote_id'], 'cantidad_comprada' => $linea['cantidad'],
                'precio_unitario' => $linea['precio'], 'cantidad_recibida' => 0, 'cantidad_rechazada' => 0, 'cantidad_cancelada' => 0,
            ]);
        }

        return ['compra_id' => $id, 'codigo' => $codigo, 'total' => $total];
    }

    public function compra(int $id, bool $bloquear = false): ?object
    {
        $consulta = DB::table('compra_feder as c')->join('productor as p', 'p.id', '=', 'c.productor_id')
            ->join('usuario as u', 'u.id', '=', 'p.usuario_id')->where('c.id', $id)
            ->select(['c.*', 'p.usuario_id', 'p.codigo_productor', DB::raw("u.nombres || ' ' || u.apellidos AS productor")]);

        return ($bloquear ? $consulta->lock('FOR UPDATE OF c') : $consulta)->first();
    }

    public function detallesCompra(int $compraId, bool $bloquear = false): Collection
    {
        $consulta = DB::table('detalle_compra as d')->join('lote_produccion as l', 'l.id', '=', 'd.lote_origen_id')
            ->join('produccion as pr', 'pr.id', '=', 'l.produccion_id')->join('producto as p', 'p.id', '=', 'pr.producto_id')
            ->where('d.compra_id', $compraId)->orderBy('d.id')
            ->select(['d.*', 'l.codigo_lote', 'l.fecha_vencimiento', 'p.nombre as producto', 'p.unidad_medida']);

        return ($bloquear ? $consulta->lock('FOR UPDATE OF d') : $consulta)->get();
    }

    public function actualizarDetalleCompra(int $id, array $datos): void
    {
        DB::table('detalle_compra')->where('id', $id)->update($datos);
    }

    public function actualizarCompra(int $id, array $datos): void
    {
        DB::table('compra_feder')->where('id', $id)->update($datos);
    }

    /**
     * Clave única de una operación sobre una línea de compra (64 hexadecimales). La misma clave identifica el movimiento
     * de inventario y su fila DETALLE_COMPRA en la bitácora.
     */
    public function nuevaClaveOperacion(string $tipo, int $detalleId): string
    {
        return hash('sha256', $tipo.'|'.$detalleId.'|'.Str::uuid());
    }

    /**
     * Entrada al inventario de lo aceptado en una recepción (ENTRADA_COMPRA con la clave indicada).
     * El lote de FEDER se crea con la primera aceptación; las siguientes suman al mismo lote.
     */
    public function ingresarInventario(int $detalleId, string $cantidad, int $usuarioId, string $ubicacion, string $claveOperacion): int
    {
        $lote = DB::table('lote_feder')->where('detalle_compra_id', $detalleId)->lockForUpdate()->first();
        if ($lote) {
            // R035: la entrada suma saldo y solo saca al lote de AGOTADO. Un lote BLOQUEADO o VENCIDO conserva su estado:
            // recibir más mercadería no lo devuelve a la venta (mismo criterio que LoteFederRepositorio.sumarEntrada del P1).
            DB::update("UPDATE lote_feder SET cantidad_disponible = cantidad_disponible + ?,
                               estado = CASE WHEN estado = 'AGOTADO' THEN 'DISPONIBLE' ELSE estado END,
                               ubicacion_almacen = ?
                         WHERE id = ?", [$cantidad, $ubicacion, $lote->id]);
            $loteId = (int) $lote->id;
        } else {
            $loteId = $this->siguienteId('lote_feder');
            DB::table('lote_feder')->insert([
                'id' => $loteId, 'detalle_compra_id' => $detalleId, 'codigo' => sprintf('LF-%s-%03d', now()->format('Y'), $loteId),
                'fecha_ingreso' => now(), 'cantidad_disponible' => $cantidad, 'cantidad_reservada' => 0,
                'ubicacion_almacen' => $ubicacion, 'estado' => 'DISPONIBLE',
            ]);
        }
        DB::table('movimiento_inventario')->insert([
            'lote_feder_id' => $loteId, 'usuario_id' => $usuarioId, 'tipo' => 'ENTRADA_COMPRA', 'cantidad' => $cantidad,
            'motivo' => 'Recepción de compra a productor',
            'clave_operacion' => $claveOperacion,
        ]);

        return $loteId;
    }

    private function siguienteId(string $tabla): int
    {
        return (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence(?, 'id')) AS id", [$tabla])->id;
    }

    private function compradoSql(string $lote): string
    {
        return "COALESCE((SELECT SUM(d.cantidad_comprada - d.cantidad_cancelada - d.cantidad_rechazada) FROM detalle_compra d
                 WHERE d.lote_origen_id = {$lote}), 0)";
    }
}
