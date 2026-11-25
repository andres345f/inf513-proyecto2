<?php

namespace App\Services;

use App\Models\Usuario;
use App\Repositories\SesionWebRepository;
use App\Repositories\UsuarioRepository;
use App\Support\SesionActiva;

/**
 * Emite, resuelve y cierra las sesiones web basadas en token Bearer.
 */
class SesionWebService
{
    public function __construct(
        private readonly SesionWebRepository $sesiones,
        private readonly UsuarioRepository $usuarios,
    ) {
    }

    /**
     * Crea una sesión y devuelve el token en claro (solo esta vez) y su vencimiento.
     *
     * @return array{token:string, expira_en:\Carbon\CarbonInterface}
     */
    public function iniciar(Usuario $usuario, object $rol): array
    {
        $token = bin2hex(random_bytes(32));
        $expiraEn = now()->addHours(config('feder.sesion_horas'));

        $this->sesiones->crear($this->hash($token), $usuario->correo, $usuario->id, $rol->id, $rol->codigo, $expiraEn);

        return ['token' => $token, 'expira_en' => $expiraEn];
    }

    /**
     * Devuelve la sesión si el token es vigente, la cuenta sigue activa y el rol sigue vigente.
     */
    public function resolver(?string $token): ?SesionActiva
    {
        if ($token === null || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $fila = $this->sesiones->buscarVigentePorHash($this->hash($token));
        $usuario = $fila ? $this->usuarios->buscarPorId((int) $fila->usuario_id) : null;

        if ($fila === null || $usuario === null || ! $usuario->activo) {
            return null;
        }

        $rolId = (int) ($fila->parametros['rol_id'] ?? 0);
        $rolVigente = $this->usuarios->rolesVigentes($usuario->id)->firstWhere('id', $rolId);

        if ($rolVigente === null) {
            return null;
        }

        return new SesionActiva($fila->id, $usuario, $rolVigente->id, $rolVigente->codigo, $fila->expira_en);
    }

    /** Devuelve true si la sesión seguía abierta (dos cierres simultáneos del mismo token: solo uno la cierra). */
    public function cerrar(SesionActiva $sesion): bool
    {
        return $this->sesiones->cerrar($sesion->id);
    }

    public function cambiarRol(SesionActiva $sesion, object $rol): void
    {
        $this->sesiones->cambiarRol($sesion->id, $rol->id, $rol->codigo);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
