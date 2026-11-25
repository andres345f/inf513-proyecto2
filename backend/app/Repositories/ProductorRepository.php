<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Productores, sus solicitudes de afiliación y comunidades agrícolas (CU2).
 */
class ProductorRepository
{
    public function listar(array $filtros): LengthAwarePaginator
    {
        $consulta = DB::table('productor as p')->join('usuario as u', 'u.id', '=', 'p.usuario_id')
            ->join('comunidad_agricola as c', 'c.id', '=', 'p.comunidad_agricola_id')
            ->select(['p.id', 'p.codigo_productor', 'p.estado_solicitud', 'p.fecha_solicitud', 'p.fecha_revision', 'p.motivo_rechazo',
                'p.ubicacion_actividad', 'p.fecha_afiliacion', 'p.activo', 'p.usuario_id', 'u.correo', 'u.telefono',
                DB::raw("u.nombres || ' ' || u.apellidos AS nombre"), 'c.id as comunidad_id', 'c.nombre as comunidad', 'c.municipio', 'c.activa as comunidad_activa'])
            ->orderByRaw("CASE p.estado_solicitud WHEN 'PENDIENTE' THEN 0 ELSE 1 END")->orderByDesc('p.fecha_solicitud');

        if (! empty($filtros['estado'])) {
            $consulta->where('p.estado_solicitud', $filtros['estado']);
        }
        if (! empty($filtros['comunidad'])) {
            $consulta->where('p.comunidad_agricola_id', (int) $filtros['comunidad']);
        }
        if (! empty($filtros['q'])) {
            $patron = '%'.addcslashes($filtros['q'], '%_\\').'%';
            $consulta->where(fn ($q) => $q->whereRaw("(u.nombres || ' ' || u.apellidos) ILIKE ?", [$patron])
                ->orWhereRaw('p.codigo_productor ILIKE ?', [$patron])->orWhereRaw('u.correo ILIKE ?', [$patron]));
        }

        return $consulta->paginate(15, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));
    }

    public function buscar(int $id, bool $bloquear = false): ?object
    {
        $consulta = DB::table('productor')->where('id', $id);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    public function buscarPorUsuario(int $usuarioId): ?object
    {
        return DB::table('productor as p')->join('comunidad_agricola as c', 'c.id', '=', 'p.comunidad_agricola_id')
            ->where('p.usuario_id', $usuarioId)
            ->first(['p.*', 'c.nombre as comunidad', 'c.municipio', 'c.departamento', 'c.activa as comunidad_activa']);
    }

    public function comunidadActiva(int $id): ?object
    {
        return DB::table('comunidad_agricola')->where('id', $id)->where('activa', true)
            ->first(['id', 'nombre', 'municipio', 'departamento']);
    }

    public function crearSolicitud(int $usuarioId, int $comunidadId, string $ubicacion): int
    {
        return (int) DB::table('productor')->insertGetId([
            'usuario_id' => $usuarioId,
            'comunidad_agricola_id' => $comunidadId,
            'estado_solicitud' => 'PENDIENTE',
            'fecha_solicitud' => DB::raw('now()'),
            'ubicacion_actividad' => $ubicacion,
            'activo' => false,
        ]);
    }

    public function reenviarSolicitud(int $id, int $comunidadId, string $ubicacion): void
    {
        DB::table('productor')->where('id', $id)->where('estado_solicitud', 'RECHAZADA')->update([
            'comunidad_agricola_id' => $comunidadId,
            'estado_solicitud' => 'PENDIENTE',
            'fecha_solicitud' => DB::raw('now()'),
            'fecha_revision' => null,
            'motivo_rechazo' => null,
            'codigo_productor' => null,
            'fecha_afiliacion' => null,
            'ubicacion_actividad' => $ubicacion,
            'activo' => false,
        ]);
    }

    public function aprobar(int $id): string
    {
        $codigo = sprintf('PROD-%04d', $id);
        DB::table('productor')->where('id', $id)->update([
            'estado_solicitud' => 'APROBADA',
            'fecha_revision' => DB::raw('now()'),
            'codigo_productor' => $codigo,
            'fecha_afiliacion' => DB::raw('current_date'),
            'motivo_rechazo' => null,
            'activo' => true,
        ]);

        return $codigo;
    }

    public function rechazar(int $id, string $motivo): void
    {
        DB::table('productor')->where('id', $id)->update([
            'estado_solicitud' => 'RECHAZADA',
            'fecha_revision' => DB::raw('now()'),
            'motivo_rechazo' => $motivo,
            'activo' => false,
        ]);
    }

    // ---------------- Comunidades ----------------

    public function comunidades(): Collection
    {
        return DB::table('comunidad_agricola as c')->leftJoin('productor as p', function ($j) {
            $j->on('p.comunidad_agricola_id', '=', 'c.id')->where('p.estado_solicitud', 'APROBADA');
        })->groupBy('c.id')->orderBy('c.nombre')
            ->get(['c.id', 'c.nombre', 'c.municipio', 'c.departamento', 'c.descripcion', 'c.activa', DB::raw('COUNT(p.id) AS productores')]);
    }

    /** Comunidad con ese nombre y municipio (sin distinguir mayúsculas), activa o no. */
    public function comunidadPorNombre(string $nombre, string $municipio): ?object
    {
        return DB::table('comunidad_agricola')->whereRaw('lower(nombre) = lower(?) AND lower(municipio) = lower(?)', [$nombre, $municipio])
            ->first(['id', 'nombre', 'municipio', 'activa']);
    }

    public function activarComunidad(int $id): bool
    {
        return DB::table('comunidad_agricola')->where('id', $id)->where('activa', false)->update(['activa' => true]) === 1;
    }

    public function bloquearComunidad(int $id): ?object
    {
        return DB::table('comunidad_agricola')->where('id', $id)->lockForUpdate()->first(['id', 'activa']);
    }

    /** Una propuesta sigue pendiente hasta que FEDER la activa o decide su vigencia en el mantenimiento. */
    public function esComunidadPropuesta(int $id): bool
    {
        $ultima = DB::table('bitacora')->where('entidad', 'COMUNIDAD_AGRICOLA')->where('entidad_id', $id)
            ->orderByDesc('id')->value('detalle->operacion');

        return $ultima === 'PROPUESTA';
    }

    public function existeComunidad(string $nombre, string $municipio, ?int $excepto = null): bool
    {
        return DB::table('comunidad_agricola')->whereRaw('lower(nombre) = lower(?) AND lower(municipio) = lower(?)', [$nombre, $municipio])
            ->when($excepto, fn ($q) => $q->where('id', '<>', $excepto))->exists();
    }

    public function guardarComunidad(?int $id, array $datos): int
    {
        if ($id === null) {
            return (int) DB::table('comunidad_agricola')->insertGetId($datos);
        }

        DB::table('comunidad_agricola')->where('id', $id)->update($datos);

        return $id;
    }
}
