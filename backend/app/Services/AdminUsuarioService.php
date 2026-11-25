<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Usuario;
use App\Repositories\OperacionProductorRepository;
use App\Repositories\RolRepository;
use App\Repositories\UsuarioRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Administración de cuentas y roles (CU1). Todo cambio queda en la bitácora con el administrador que lo hizo.
 */
class AdminUsuarioService
{
    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly RolRepository $roles,
        private readonly AuditoriaService $auditoria,
        private readonly OperacionProductorRepository $productores,
    ) {
    }

    public function listar(array $filtros): array
    {
        $pagina = $this->usuarios->listarAdministracion($filtros);

        return [
            'usuarios' => collect($pagina->items())->map(fn ($u) => [
                'id' => (int) $u->id,
                'correo' => $u->correo,
                'nombre' => trim($u->nombres.' '.$u->apellidos),
                'ci' => trim($u->ci.' '.($u->ci_complemento ?? '')),
                'telefono' => $u->telefono,
                'activo' => (bool) $u->activo,
                'roles' => $u->roles ? explode(',', $u->roles) : [],
                'fecha_registro' => $u->fecha_registro,
                'ultimo_acceso' => $u->ultimo_acceso,
            ])->all(),
            'paginacion' => ['pagina' => $pagina->currentPage(), 'paginas' => $pagina->lastPage(), 'total' => $pagina->total()],
        ];
    }

    /** Alta de una cuenta por el administrador (por ejemplo, personal de FEDER) con su rol inicial. */
    public function crear(Usuario $admin, array $datos, ?string $ip): array
    {
        $errores = [];
        if ($this->usuarios->existeCorreo($datos['correo'])) {
            $errores['correo'] = ['Este correo ya está registrado.'];
        }
        if ($this->usuarios->existeDocumento((int) $datos['ci'], $datos['ci_complemento'] ?? null)) {
            $errores['ci'] = ['Ya existe una cuenta con este documento de identidad.'];
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $rol = $this->roles->buscarPorCodigo($datos['rol']);

        $usuario = DB::transaction(function () use ($admin, $datos, $rol, $ip) {
            $this->usuarios->bloquearRolAdministrador();
            $this->exigirAdministradorVigente($admin);
            $usuario = $this->usuarios->crear([
                'correo' => mb_strtolower(trim($datos['correo'])),
                'password_hash' => Hash::make($datos['password']),
                'nombres' => trim($datos['nombres']),
                'apellidos' => trim($datos['apellidos']),
                'ci' => (int) $datos['ci'],
                'ci_complemento' => $datos['ci_complemento'] ?? null,
                'telefono' => (int) $datos['telefono'],
                'activo' => true,
            ]);
            $this->usuarios->concederRolVigente($usuario->id, $rol->id, 'Alta por el administrador', $admin->id);
            $this->auditoria->operacion($admin->id, 'CREAR', 'USUARIO', $usuario->id, ['rol' => $rol->codigo], $ip);

            return $usuario;
        });

        return ['id' => $usuario->id, 'message' => 'Cuenta creada correctamente.'];
    }

    public function cambiarActivo(Usuario $admin, int $usuarioId, bool $activo, ?string $ip): void
    {
        if ($usuarioId === $admin->id && ! $activo) {
            throw new NegocioException('No puedes desactivar tu propia cuenta.');
        }

        DB::transaction(function () use ($admin, $usuarioId, $activo, $ip) {
            $this->usuarios->bloquearRolAdministrador();

            $usuario = $this->usuarios->bloquearPorId($usuarioId) ?? throw new NegocioException('Ese usuario no existe.', 404);
            if ($activo && empty($usuario->password_hash)) {
                throw new NegocioException('Esta cuenta nunca definió una contraseña: no se puede activar desde aquí.');
            }
            if (! $activo && $usuario->activo && $this->usuarios->tieneRolVigente($usuarioId, 'ADMINISTRADOR')) {
                $this->exigirOtroAdministrador($usuarioId, 'desactivar esta cuenta');
            }

            $this->exigirAdministradorVigente($admin);
            $this->usuarios->cambiarActivo($usuarioId, $activo);
            if (! $activo) {
                $this->usuarios->cerrarSesiones($usuarioId);
            }
            $this->auditoria->operacion($admin->id, 'MODIFICAR', 'USUARIO', $usuarioId, ['activo' => $activo], $ip);
        });
    }

    public function asignarRol(Usuario $admin, int $usuarioId, string $rolCodigo, ?string $ip): void
    {
        $usuario = $this->usuarios->buscarPorId($usuarioId) ?? throw new NegocioException('Ese usuario no existe.', 404);
        if ($usuarioId === $admin->id) {
            throw new NegocioException('Nadie puede concederse un rol a sí mismo.');
        }

        $rol = $this->roles->buscarPorCodigo($rolCodigo);
        DB::transaction(function () use ($admin, $usuario, $rol, $ip) {
            $this->usuarios->bloquearRolAdministrador();
            $this->exigirAdministradorVigente($admin);
            if ($this->usuarios->tieneRolVigente($usuario->id, $rol->codigo)) {
                throw new NegocioException('El usuario ya tiene ese rol vigente.');
            }
            $concesionId = $this->usuarios->concederRolVigente($usuario->id, $rol->id, 'Asignado por el administrador', $admin->id);
            $this->auditoria->operacion($admin->id, 'ASIGNAR_ROL', 'USUARIO_ROL', $concesionId, ['usuario_id' => $usuario->id, 'rol' => $rol->codigo], $ip);
            if ($rol->codigo === 'PRODUCTOR') {
                $this->sincronizarFichaProductor($admin, $usuario->id, true, 'Rol PRODUCTOR asignado de nuevo por el administrador', $ip);
            }
        });
    }

    public function revocarRol(Usuario $admin, int $usuarioId, string $rolCodigo, string $motivo, ?string $ip): void
    {
        if ($usuarioId === $admin->id && $rolCodigo === 'ADMINISTRADOR') {
            throw new NegocioException('No puedes quitarte tu propio rol de administrador.');
        }

        $rol = $this->roles->buscarPorCodigo($rolCodigo) ?? throw new NegocioException('Ese rol no existe.', 404);

        DB::transaction(function () use ($admin, $usuarioId, $rol, $motivo, $ip) {
            $this->usuarios->bloquearRolAdministrador();

            $concesion = $this->usuarios->concesionVigente($usuarioId, $rol->id)
                ?? throw new NegocioException('El usuario no tiene ese rol vigente.');
            // Una cuenta inactiva ya no cuenta como administradora: quitarle el rol no reduce los administradores activos.
            if ($rol->codigo === 'ADMINISTRADOR' && $this->usuarios->buscarPorId($usuarioId)?->activo) {
                $this->exigirOtroAdministrador($usuarioId, 'revocar este rol de administrador');
            }

            $this->exigirAdministradorVigente($admin);
            $this->usuarios->revocarConcesion($concesion->id, $admin->id, $motivo);
            $this->usuarios->cerrarSesiones($usuarioId);
            $this->auditoria->operacion($admin->id, 'REVOCAR_ROL', 'USUARIO_ROL', $concesion->id,
                ['usuario_id' => $usuarioId, 'rol' => $rol->codigo, 'motivo' => $motivo], $ip);
            if ($rol->codigo === 'PRODUCTOR') {
                $this->sincronizarFichaProductor($admin, $usuarioId, false, 'Revocación del rol PRODUCTOR: '.$motivo, $ip);
            }
        });
    }

    /**
     * R073/R101: la ficha del productor sigue a su concesión PRODUCTOR. Se desactiva en la misma transacción que la
     * revocación y vuelve a quedar activa cuando el rol se devuelve a una ficha ya APROBADA. No crea fichas ni toca
     * solicitudes pendientes o rechazadas: esas siguen su trámite de aprobación.
     */
    private function sincronizarFichaProductor(Usuario $admin, int $usuarioId, bool $activa, string $motivo, ?string $ip): void
    {
        $ficha = $this->productores->fichaDeUsuario($usuarioId, bloquear: true);
        if ($ficha === null || $ficha->estado_solicitud !== 'APROBADA' || (bool) $ficha->activo === $activa) {
            return;
        }

        $this->productores->actualizarProductor((int) $ficha->id, ['activo' => $activa]);
        $this->auditoria->operacion($admin->id, 'MODIFICAR', 'PRODUCTOR', (int) $ficha->id,
            ['activo_anterior' => ! $activa, 'activo_nuevo' => $activa, 'motivo' => $motivo], $ip);
    }

    /**
     * R102 «Último administrador»: siempre queda al menos una cuenta activa con ADMINISTRADOR vigente.
     * Debe llamarse con el bloqueo de la fila ROL ya tomado (UsuarioRepository::bloquearRolAdministrador).
     */
    private function exigirOtroAdministrador(int $usuarioAfectado, string $operacion): void
    {
        if ($this->usuarios->contarAdministradoresActivosSalvo($usuarioAfectado) === 0) {
            throw new NegocioException("No se puede {$operacion}: es la última cuenta activa con rol de administrador ".
                'y el sistema nunca debe quedarse sin administrador. Incorpora antes a otro administrador.');
        }
    }

    /** La cuenta y su concesión ADMINISTRADOR se releen después de adquirir el bloqueo común de R102. */
    private function exigirAdministradorVigente(Usuario $actor): void
    {
        if (! $this->usuarios->esAdministradorActivo($actor->id)) {
            throw new NegocioException('Tu sesión ya no es válida. Inicia sesión nuevamente.', 401);
        }
    }
}
