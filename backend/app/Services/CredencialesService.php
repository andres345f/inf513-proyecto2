<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Repositories\BitacoraRepository;
use App\Repositories\UsuarioRepository;
use App\Support\CredencialesDemostracion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Restablecimiento de contraseñas por consola (php artisan feder:restablecer-contrasenas), para el despliegue (H-02).
 *
 * Este servicio elige las cuentas y aplica el cambio; las contraseñas en claro las genera y entrega el comando.
 * Aquí solo se guardan sus hashes bcrypt. La bitácora registra cada cuenta restablecida sin actor (canal CONSOLA)
 * y nunca el valor.
 */
class CredencialesService
{
    public const MODO_ALEATORIA = 'ALEATORIA';

    public const MODO_LABORATORIO = 'LABORATORIO';

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly BitacoraRepository $bitacora,
    ) {
    }

    /**
     * Cuentas a restablecer: las indicadas por correo (todas deben existir y estar activas) o todas las activas;
     * con $soloDemostracion, solo las que conservan la contraseña de demostración.
     *
     * @param  list<string>  $correos
     * @return Collection<int, object{id:int, correo:string}>
     *
     * @throws NegocioException si algún correo no existe o es de una cuenta inactiva (no se cambia nada)
     */
    public function cuentas(array $correos, bool $soloDemostracion): Collection
    {
        $pedidos = array_values(array_unique(array_filter(array_map(fn ($c) => mb_strtolower(trim((string) $c)), $correos), fn ($c) => $c !== '')));
        $cuentas = $this->usuarios->cuentasParaRestablecer($pedidos === [] ? null : $pedidos);

        if ($pedidos !== []) {
            $encontradas = $cuentas->mapWithKeys(fn ($c) => [mb_strtolower($c->correo) => $c]);
            $problemas = [];
            foreach ($pedidos as $correo) {
                $cuenta = $encontradas->get($correo);
                if ($cuenta === null) {
                    $problemas[] = "{$correo} (no existe)";
                } elseif (! $cuenta->activo) {
                    $problemas[] = "{$correo} (cuenta inactiva)";
                }
            }
            if ($problemas !== []) {
                throw new NegocioException('No se cambió ninguna contraseña. Revisa estas cuentas: '.implode(', ', $problemas).'.');
            }
        }

        return $cuentas
            ->filter(fn ($c) => $c->activo && (! $soloDemostracion || CredencialesDemostracion::esHashDeDemostracion($c->password_hash)))
            ->map(fn ($c) => (object) ['id' => $c->id, 'correo' => $c->correo])
            ->values();
    }

    /**
     * Guarda las contraseñas nuevas, cierra todas las sesiones de esas cuentas y deja una fila de bitácora por cuenta.
     * Todo en una transacción: o se restablecen todas las cuentas o ninguna.
     *
     * @param  array<int, string>  $contrasenas  usuario_id => contraseña en claro
     * @return array{cuentas:int, sesiones_cerradas:int}
     */
    public function restablecer(array $contrasenas, string $modo): array
    {
        // bcrypt es lento: los hashes se calculan antes de abrir la transacción y bloquear filas.
        $hashes = array_map(fn (string $contrasena) => Hash::make($contrasena), $contrasenas);

        return DB::transaction(function () use ($hashes, $modo) {
            $sesiones = 0;
            foreach ($hashes as $usuarioId => $hash) {
                $usuario = $this->usuarios->bloquearPorId($usuarioId);
                if ($usuario === null || ! $usuario->activo) {
                    throw new NegocioException("La cuenta #{$usuarioId} dejó de estar activa mientras se preparaba el cambio. No se cambió ninguna contraseña.");
                }
                $this->usuarios->actualizarContrasena($usuarioId, $hash);
                $cerradas = $this->usuarios->cerrarSesiones($usuarioId);
                $sesiones += $cerradas;
                $this->bitacora->registrar(null, 'MODIFICAR', 'USUARIO', $usuarioId, [
                    'canal' => 'CONSOLA', 'operacion' => 'RESTABLECER_CONTRASENA', 'modo' => $modo, 'sesiones_cerradas' => $cerradas,
                ], null);
            }

            return ['cuentas' => count($hashes), 'sesiones_cerradas' => $sesiones];
        });
    }
}
