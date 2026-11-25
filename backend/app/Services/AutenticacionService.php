<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Usuario;
use App\Repositories\RolRepository;
use App\Repositories\UsuarioRepository;
use App\Support\CredencialesDemostracion;
use App\Support\SesionActiva;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Casos de uso de acceso (CU1): iniciar y cerrar sesión, registrarse como cliente, cambiar de rol activo
 * y cambiar la propia contraseña.
 */
class AutenticacionService
{
    private const MENSAJE_CREDENCIALES = 'El correo o la contraseña no son correctos.';

    /** Cambio de contraseña: intentos con la contraseña actual equivocada que se admiten por cuenta... */
    private const MAX_FALLOS_CONTRASENA = 5;

    /** ...en esta ventana (segundos). Acertar la contraseña actual reinicia la cuenta de fallos. */
    private const VENTANA_FALLOS_CONTRASENA = 900;

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly RolRepository $roles,
        private readonly SesionWebService $sesiones,
        private readonly PermisoService $permisos,
        private readonly AuditoriaService $auditoria,
    ) {
    }

    public function iniciarSesion(string $correo, string $contrasena, ?int $rolSolicitadoId, ?string $ip): array
    {
        $usuario = $this->usuarios->buscarPorCorreo($correo);

        if ($usuario === null) {
            $this->auditoria->inicioSesionFallido(null, $correo, 'CORREO_NO_REGISTRADO', $ip);
            throw new NegocioException(self::MENSAJE_CREDENCIALES);
        }

        // H-02: en producción no vale la contraseña de demostración publicada con los datos iniciales.
        // Una cuenta que conserva ese hash queda bloqueada hasta que se ejecute feder:restablecer-contrasenas.
        if (app()->isProduction() && CredencialesDemostracion::esHashDeDemostracion($usuario->password_hash)) {
            $this->rechazarContrasenaDeDemostracion($usuario->id, $correo, $ip);
        }

        if (! $this->contrasenaValida($usuario, $contrasena)) {
            $this->auditoria->inicioSesionFallido($usuario->id, $correo, 'CONTRASENA_INCORRECTA', $ip);
            throw new NegocioException(self::MENSAJE_CREDENCIALES);
        }

        // La misma contraseña guardada con otra sal tampoco vale en producción.
        if (app()->isProduction() && CredencialesDemostracion::esContrasenaDeDemostracion($contrasena)) {
            $this->rechazarContrasenaDeDemostracion($usuario->id, $correo, $ip);
        }

        if (! $usuario->activo) {
            $this->auditoria->inicioSesionFallido($usuario->id, $correo, 'CUENTA_INACTIVA', $ip);
            throw new NegocioException('Tu cuenta está inactiva. Comunícate con FEDER para habilitarla.', 403);
        }

        $rolesVigentes = $this->usuarios->rolesVigentes($usuario->id);

        if ($rolesVigentes->isEmpty()) {
            $this->auditoria->inicioSesionFallido($usuario->id, $correo, 'SIN_ROL_VIGENTE', $ip);
            throw new NegocioException('Tu cuenta no tiene un rol vigente. Comunícate con FEDER.', 403);
        }

        $rol = $this->elegirRolInicial($rolesVigentes, $rolSolicitadoId);

        return $this->abrirSesion($usuario, $rol, $rolesVigentes, $ip);
    }

    /**
     * Registro público de clientes: la cuenta queda activa y con rol CLIENTE vigente al instante.
     */
    public function registrarCliente(array $datos, ?string $ip): array
    {
        $this->validarUnicidad($datos);

        $rolCliente = $this->roles->buscarPorCodigo('CLIENTE');

        $usuario = DB::transaction(function () use ($datos, $rolCliente, $ip) {
            $usuario = $this->usuarios->crear([
                'correo' => mb_strtolower(trim($datos['correo'])),
                'password_hash' => Hash::make($datos['password']),
                'nombres' => trim($datos['nombres']),
                'apellidos' => trim($datos['apellidos']),
                'ci' => (int) $datos['ci'],
                'ci_complemento' => $datos['ci_complemento'] ?? null,
                'telefono' => (int) $datos['telefono'],
                'activo' => true,
                'fecha_aceptacion_terminos' => now(),
            ]);

            $this->usuarios->concederRolVigente($usuario->id, $rolCliente->id, 'Registro web');
            $this->auditoria->registroUsuario($usuario->id, $rolCliente->codigo, $ip);

            return $usuario;
        });

        $rolesVigentes = $this->usuarios->rolesVigentes($usuario->id);

        return $this->abrirSesion($usuario, $rolesVigentes->first(), $rolesVigentes, $ip);
    }

    /** H-22: el cierre queda en la bitácora sobre la fila de la sesión (sin el token ni su hash). */
    public function cerrarSesion(SesionActiva $sesion, ?string $ip): void
    {
        DB::transaction(function () use ($sesion, $ip) {
            if ($this->sesiones->cerrar($sesion)) {
                $this->auditoria->operacion($sesion->usuario->id, 'MODIFICAR', 'SESION_WEB', $sesion->id,
                    ['operacion' => 'CIERRE_SESION', 'rol' => $sesion->rolCodigo], $ip);
            }
        });
    }

    /**
     * H-02: cambio de la propia contraseña. Exige la contraseña actual, guarda la nueva con bcrypt y cierra las DEMÁS
     * sesiones de la cuenta (la que hace el cambio sigue abierta). La bitácora registra el resultado, nunca los valores.
     * Tras MAX_FALLOS_CONTRASENA intentos con la contraseña actual equivocada, la cuenta espera antes de volver a probar.
     */
    public function cambiarContrasena(SesionActiva $sesion, string $actual, string $nueva, ?string $ip): array
    {
        $usuarioId = $sesion->usuario->id;
        $claveFallos = 'cambio-contrasena-fallido|'.$usuarioId;

        $sesionesCerradas = DB::transaction(function () use ($sesion, $usuarioId, $claveFallos, $actual, $nueva, $ip) {
            // Todas las peticiones de esta cuenta consultan y actualizan el contador bajo el mismo bloqueo.
            $usuario = $this->usuarios->bloquearPorId($usuarioId);
            if (RateLimiter::tooManyAttempts($claveFallos, self::MAX_FALLOS_CONTRASENA)) {
                $minutos = max(1, (int) ceil(RateLimiter::availableIn($claveFallos) / 60));
                throw new NegocioException("Hiciste demasiados intentos con una contraseña actual incorrecta. Espera {$minutos} minuto(s) e inténtalo de nuevo.", 429);
            }

            if ($usuario === null || ! $this->contrasenaValida($usuario, $actual)) {
                RateLimiter::hit($claveFallos, self::VENTANA_FALLOS_CONTRASENA);
                $this->auditoria->operacion($usuarioId, 'MODIFICAR', 'USUARIO', $usuarioId,
                    ['operacion' => 'CAMBIAR_CONTRASENA', 'resultado' => 'FALLIDO', 'motivo' => 'CONTRASENA_ACTUAL_INCORRECTA'], $ip);

                return null;
            }

            $this->usuarios->actualizarContrasena($usuarioId, Hash::make($nueva));
            $cerradas = $this->usuarios->cerrarSesiones($usuarioId, $sesion->id);
            $this->auditoria->operacion($usuarioId, 'MODIFICAR', 'USUARIO', $usuarioId,
                ['operacion' => 'CAMBIAR_CONTRASENA', 'resultado' => 'EXITO', 'sesiones_cerradas' => $cerradas], $ip);
            RateLimiter::clear($claveFallos);

            return $cerradas;
        });

        if ($sesionesCerradas === null) {
            throw ValidationException::withMessages(['contrasena_actual' => ['La contraseña actual no es correcta.']]);
        }

        return [
            'message' => $sesionesCerradas > 0
                ? "Tu contraseña se cambió. Se cerraron {$sesionesCerradas} sesión(es) abiertas en otros dispositivos."
                : 'Tu contraseña se cambió.',
            'sesiones_cerradas' => $sesionesCerradas,
        ];
    }

    public function perfil(SesionActiva $sesion): array
    {
        $rolesVigentes = $this->usuarios->rolesVigentes($sesion->usuario->id);
        $rolActivo = $rolesVigentes->firstWhere('id', $sesion->rolId);

        return $this->datosSesion($sesion->usuario, $rolActivo, $rolesVigentes) + ['expira_en' => $sesion->expiraEn->toIso8601String()];
    }

    /**
     * Edición de datos personales desde "Mi perfil". Cambiar el correo cambia el usuario con el que se
     * inicia sesión (y el remitente que reconoce el canal de correo), por eso exige la contraseña actual.
     */
    public function actualizarPerfil(SesionActiva $sesion, array $datos, ?string $ip): array
    {
        return DB::transaction(function () use ($sesion, $datos, $ip) {
            $usuario = $this->usuarios->bloquearPorId($sesion->usuario->id);
            if (! $usuario?->activo) {
                throw new NegocioException('La cuenta no está activa.', 403);
            }
            $nuevos = [
                'nombres' => $datos['nombres'],
                'apellidos' => $datos['apellidos'],
                'correo' => $datos['correo'],
                'telefono' => (int) $datos['telefono'],
            ];

            $cambiaCorreo = mb_strtolower($usuario->correo) !== $nuevos['correo'];
            if ($cambiaCorreo) {
                if (empty($datos['password_actual']) || ! $this->contrasenaValida($usuario, $datos['password_actual'])) {
                    throw ValidationException::withMessages(['password_actual' => ['Para cambiar el correo escribe tu contraseña actual correcta.']]);
                }
                if ($this->usuarios->correoEnUsoPorOtro($nuevos['correo'], $usuario->id)) {
                    throw ValidationException::withMessages(['correo' => ['Ese correo ya lo usa otra cuenta.']]);
                }
            } else {
                $nuevos['correo'] = $usuario->correo;
            }

            $cambiados = array_keys(array_filter($nuevos, fn ($valor, $campo) => (string) $usuario->{$campo} !== (string) $valor, ARRAY_FILTER_USE_BOTH));
            if ($cambiados === []) {
                return ['mensaje' => 'No hiciste cambios.'] + $this->perfil($sesion);
            }

            $this->usuarios->actualizarDatosPersonales($usuario->id, $nuevos);
            $this->auditoria->operacion($usuario->id, 'MODIFICAR', 'USUARIO', $usuario->id, ['operacion' => 'EDITAR_PERFIL', 'campos' => $cambiados], $ip);
            $sesion->usuario->refresh();

            return ['mensaje' => 'Tus datos se actualizaron.'] + $this->perfil($sesion);
        });
    }

    /** H-22: el cambio de rol activo queda en la bitácora (rol anterior y nuevo). Pedir el mismo rol no cambia nada. */
    public function cambiarRolActivo(SesionActiva $sesion, int $rolId, ?string $ip): array
    {
        $rolesVigentes = $this->usuarios->rolesVigentes($sesion->usuario->id);
        $rol = $rolesVigentes->firstWhere('id', $rolId);

        if ($rol === null) {
            throw new NegocioException('No tienes ese rol vigente.', 403);
        }

        if ($rol->id !== $sesion->rolId) {
            DB::transaction(function () use ($sesion, $rol, $ip) {
                $this->sesiones->cambiarRol($sesion, $rol);
                $this->auditoria->operacion($sesion->usuario->id, 'MODIFICAR', 'SESION_WEB', $sesion->id,
                    ['operacion' => 'CAMBIO_ROL_ACTIVO', 'rol_anterior' => $sesion->rolCodigo, 'rol_nuevo' => $rol->codigo], $ip);
            });
        }

        return $this->datosSesion($sesion->usuario, $rol, $rolesVigentes) + ['expira_en' => $sesion->expiraEn->toIso8601String()];
    }

    private function abrirSesion(Usuario $usuario, object $rol, Collection $rolesVigentes, ?string $ip): array
    {
        $emision = $this->sesiones->iniciar($usuario, $rol);
        $this->usuarios->registrarUltimoAcceso($usuario->id);
        $this->auditoria->inicioSesionExitoso($usuario->id, $rol->codigo, $ip);

        return ['token' => $emision['token'], 'expira_en' => $emision['expira_en']->toIso8601String()]
            + $this->datosSesion($usuario, $rol, $rolesVigentes);
    }

    private function datosSesion(Usuario $usuario, object $rolActivo, Collection $rolesVigentes): array
    {
        return [
            'usuario' => [
                'id' => $usuario->id,
                'nombres' => $usuario->nombres,
                'apellidos' => $usuario->apellidos,
                'nombre_completo' => $usuario->nombreCompleto(),
                'correo' => $usuario->correo,
                'telefono' => $usuario->telefono,
            ],
            'rol_activo' => $rolActivo,
            'roles' => $rolesVigentes->values()->all(),
            'permisos' => $this->permisos->permisosDe($rolActivo->codigo),
        ];
    }

    /** Usa el rol pedido si es vigente; si no, el de mayor prioridad. */
    private function elegirRolInicial(Collection $rolesVigentes, ?int $rolSolicitadoId): object
    {
        if ($rolSolicitadoId !== null && ($rol = $rolesVigentes->firstWhere('id', $rolSolicitadoId))) {
            return $rol;
        }

        foreach (config('feder.prioridad_roles') as $codigo) {
            if ($rol = $rolesVigentes->firstWhere('codigo', $codigo)) {
                return $rol;
            }
        }

        return $rolesVigentes->first();
    }

    private function rechazarContrasenaDeDemostracion(int $usuarioId, string $correo, ?string $ip): never
    {
        $this->auditoria->inicioSesionFallido($usuarioId, $correo, 'CONTRASENA_DEMOSTRACION', $ip);
        throw new NegocioException(CredencialesDemostracion::MENSAJE_BLOQUEO, 403);
    }

    /** Solo se aceptan contraseñas bcrypt (las cuentas del canal correo guardan un valor inerte). */
    private function contrasenaValida(Usuario $usuario, string $contrasena): bool
    {
        $hash = $usuario->getAuthPassword();

        return $hash !== ''
            && password_get_info($hash)['algoName'] === 'bcrypt'
            && Hash::check($contrasena, $hash);
    }

    private function validarUnicidad(array $datos): void
    {
        $errores = [];

        if ($this->usuarios->existeCorreo($datos['correo'])) {
            $errores['correo'] = ['Este correo ya está registrado. Inicia sesión o usa otro correo.'];
        }

        if ($this->usuarios->existeDocumento((int) $datos['ci'], $datos['ci_complemento'] ?? null)) {
            $errores['ci'] = ['Ya existe una cuenta con este documento de identidad.'];
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }
}
