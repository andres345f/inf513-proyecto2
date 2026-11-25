<?php

namespace App\Repositories;

use App\Models\Usuario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Acceso a datos de usuario y usuario_rol.
 */
class UsuarioRepository
{
    public function buscarPorCorreo(string $correo): ?Usuario
    {
        return Usuario::query()->whereRaw('lower(correo) = ?', [mb_strtolower(trim($correo))])->first();
    }

    public function buscarPorId(int $id): ?Usuario
    {
        return Usuario::query()->find($id);
    }

    public function existeCorreo(string $correo): bool
    {
        return Usuario::query()->whereRaw('lower(correo) = ?', [mb_strtolower(trim($correo))])->exists();
    }

    /** ¿Otro usuario (distinto de $exceptoId) ya usa este correo? */
    public function correoEnUsoPorOtro(string $correo, int $exceptoId): bool
    {
        return Usuario::query()->whereRaw('lower(correo) = ?', [mb_strtolower(trim($correo))])->where('id', '<>', $exceptoId)->exists();
    }

    /** Actualiza los datos personales editables (nombres, apellidos, correo, teléfono). */
    public function actualizarDatosPersonales(int $usuarioId, array $datos): void
    {
        Usuario::query()->whereKey($usuarioId)->update($datos);
    }

    public function existeDocumento(int $ci, ?string $complemento): bool
    {
        return Usuario::query()
            ->where('ci', $ci)
            ->whereRaw("COALESCE(ci_complemento, '') = ?", [$complemento ?? ''])
            ->exists();
    }

    public function crear(array $datos): Usuario
    {
        return Usuario::query()->create($datos);
    }

    public function registrarUltimoAcceso(int $usuarioId): void
    {
        Usuario::query()->whereKey($usuarioId)->update(['ultimo_acceso' => now()]);
    }

    /** Guarda el hash bcrypt de una contraseña nueva. El valor en claro nunca llega a este repositorio. */
    public function actualizarContrasena(int $usuarioId, string $hash): void
    {
        Usuario::query()->whereKey($usuarioId)->update(['password_hash' => $hash]);
    }

    /**
     * Cuentas para restablecer contraseñas (id, correo, activo y hash), en orden de id.
     * Con $correos se buscan solo esas (sin distinguir mayúsculas); sin ellos, todas las cuentas activas.
     *
     * @param  list<string>|null  $correos
     * @return Collection<int, object{id:int, correo:string, activo:bool, password_hash:?string}>
     */
    public function cuentasParaRestablecer(?array $correos = null): Collection
    {
        $consulta = DB::table('usuario')->orderBy('id');
        if ($correos === null) {
            $consulta->where('activo', true);
        } else {
            $consulta->whereIn(DB::raw('lower(correo)'), array_map(fn ($c) => mb_strtolower(trim($c)), $correos));
        }

        return $consulta->get(['id', 'correo', 'activo', 'password_hash'])
            ->map(fn ($u) => (object) ['id' => (int) $u->id, 'correo' => $u->correo, 'activo' => (bool) $u->activo, 'password_hash' => $u->password_hash]);
    }

    /**
     * Roles con concesión VIGENTE.
     * En el modelo, usuario_rol.fecha_expiracion es el plazo para aceptar una invitación PENDIENTE,
     * no el vencimiento del rol: una concesión VIGENTE sigue vigente hasta que se revoca.
     *
     * @return Collection<int, object{id:int, codigo:string, nombre:string}>
     */
    public function rolesVigentes(int $usuarioId): Collection
    {
        return DB::table('usuario_rol as ur')
            ->join('rol as r', 'r.id', '=', 'ur.rol_id')
            ->where('ur.usuario_id', $usuarioId)
            ->where('ur.estado', 'VIGENTE')
            ->orderBy('r.id')
            ->get(['r.id', 'r.codigo', 'r.nombre'])
            ->map(fn ($fila) => (object) ['id' => (int) $fila->id, 'codigo' => $fila->codigo, 'nombre' => $fila->nombre]);
    }

    /** Listado para administración con sus roles vigentes (texto separado por comas). */
    public function listarAdministracion(array $filtros): LengthAwarePaginator
    {
        $roles = DB::table('usuario_rol as ur')->join('rol as r', 'r.id', '=', 'ur.rol_id')->where('ur.estado', 'VIGENTE')
            ->groupBy('ur.usuario_id')->select('ur.usuario_id', DB::raw("string_agg(r.codigo, ',' ORDER BY r.id) AS roles"));

        $consulta = DB::table('usuario as u')->leftJoinSub($roles, 'rv', 'rv.usuario_id', '=', 'u.id')
            ->select(['u.id', 'u.correo', 'u.nombres', 'u.apellidos', 'u.ci', 'u.ci_complemento', 'u.telefono', 'u.activo',
                'u.fecha_registro', 'u.ultimo_acceso', 'rv.roles'])
            ->orderBy('u.apellidos')->orderBy('u.nombres');

        if (! empty($filtros['q'])) {
            $patron = '%'.addcslashes($filtros['q'], '%_\\').'%';
            $consulta->where(fn ($q) => $q->whereRaw('u.correo ILIKE ?', [$patron])
                ->orWhereRaw("(u.nombres || ' ' || u.apellidos) ILIKE ?", [$patron])
                ->orWhereRaw('u.ci::text LIKE ?', [$patron]));
        }
        if (! empty($filtros['rol'])) {
            $consulta->whereRaw("',' || COALESCE(rv.roles, '') || ',' LIKE ?", ['%,'.$filtros['rol'].',%']);
        }
        if (isset($filtros['activo']) && $filtros['activo'] !== '') {
            $consulta->where('u.activo', (bool) $filtros['activo']);
        }

        return $consulta->paginate(15, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));
    }

    public function cambiarActivo(int $usuarioId, bool $activo): void
    {
        Usuario::query()->whereKey($usuarioId)->update(['activo' => $activo]);
    }

    /** Lee la cuenta con su fila bloqueada (FOR UPDATE). Debe llamarse dentro de una transacción. */
    public function bloquearPorId(int $id): ?Usuario
    {
        return Usuario::query()->whereKey($id)->lockForUpdate()->first();
    }

    /** Relectura del actor administrativo dentro de la transacción que bloqueó la fila ROL. */
    public function esAdministradorActivo(int $usuarioId): bool
    {
        return DB::table('usuario as u')->join('usuario_rol as ur', 'ur.usuario_id', '=', 'u.id')
            ->join('rol as r', 'r.id', '=', 'ur.rol_id')->where('r.codigo', 'ADMINISTRADOR')
            ->where('u.id', $usuarioId)->where('u.activo', true)->where('ur.estado', 'VIGENTE')->exists();
    }

    // ---------------- R102 «Último administrador» ----------------

    /**
     * Bloquea la fila ROL de ADMINISTRADOR (FOR UPDATE) y devuelve su id.
     *
     * Toda operación que puede dejar al sistema sin administrador (desactivar una cuenta o revocar ADMINISTRADOR)
     * toma primero este bloqueo: así quedan en fila y la segunda ve lo que confirmó la primera. Es la misma fila que
     * bloquea el Proyecto 1 (GuardiaAdministradores), de modo que las dos aplicaciones se ordenan entre sí.
     */
    public function bloquearRolAdministrador(): int
    {
        return (int) DB::table('rol')->where('codigo', 'ADMINISTRADOR')->lockForUpdate()->value('id');
    }

    /**
     * Cuentas activas con ADMINISTRADOR vigente, sin contar la indicada.
     * Debe llamarse con el bloqueo de bloquearRolAdministrador() ya tomado: cada consulta lee datos confirmados.
     */
    public function contarAdministradoresActivosSalvo(int $usuarioExcluido): int
    {
        return (int) DB::table('usuario_rol as ur')
            ->join('rol as r', 'r.id', '=', 'ur.rol_id')
            ->join('usuario as u', 'u.id', '=', 'ur.usuario_id')
            ->where('r.codigo', 'ADMINISTRADOR')->where('ur.estado', 'VIGENTE')->where('u.activo', true)
            ->where('ur.usuario_id', '<>', $usuarioExcluido)
            ->distinct()->count('ur.usuario_id');
    }

    public function tieneRolVigente(int $usuarioId, string $rolCodigo): bool
    {
        return DB::table('usuario_rol as ur')->join('rol as r', 'r.id', '=', 'ur.rol_id')
            ->where('ur.usuario_id', $usuarioId)->where('r.codigo', $rolCodigo)->where('ur.estado', 'VIGENTE')->exists();
    }

    /** Concesión VIGENTE de un rol (para revocarla). */
    public function concesionVigente(int $usuarioId, int $rolId): ?object
    {
        return DB::table('usuario_rol')->where('usuario_id', $usuarioId)->where('rol_id', $rolId)->where('estado', 'VIGENTE')
            ->lockForUpdate()->first();
    }

    public function revocarConcesion(int $concesionId, int $revocadoPorId, string $motivo): void
    {
        DB::table('usuario_rol')->where('id', $concesionId)->update([
            'estado' => 'REVOCADA',
            'fecha_revocacion' => DB::raw('now()'),
            'revocado_por_id' => $revocadoPorId,
            'motivo_revocacion' => $motivo,
        ]);
    }

    /**
     * Cierra las sesiones web abiertas del usuario (al desactivarlo, quitarle un rol o cambiar su contraseña).
     * Con $exceptoSesionId se conserva esa sesión (la que está usando quien cambia su propia contraseña).
     * Devuelve cuántas sesiones se cerraron.
     */
    public function cerrarSesiones(int $usuarioId, ?int $exceptoSesionId = null): int
    {
        return DB::table('confirmacion_operacion')->where('usuario_id', $usuarioId)->where('comando', 'SESION_WEB')
            ->whereNull('usada_en')
            ->when($exceptoSesionId !== null, fn ($q) => $q->where('id', '<>', $exceptoSesionId))
            ->update(['usada_en' => now()]);
    }

    /** Devuelve el id de la concesión creada. */
    public function concederRolVigente(int $usuarioId, int $rolId, string $motivo, ?int $asignadoPorId = null): int
    {
        return (int) DB::table('usuario_rol')->insertGetId([
            'usuario_id' => $usuarioId,
            'rol_id' => $rolId,
            'asignado_por_id' => $asignadoPorId,
            'estado' => 'VIGENTE',
            // Misma marca de tiempo que fecha_asignacion (now() de la transacción): exige activación >= asignación.
            'fecha_activacion' => DB::raw('now()'),
            'motivo' => $motivo,
        ]);
    }
}
