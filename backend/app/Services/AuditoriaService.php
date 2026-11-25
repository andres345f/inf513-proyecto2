<?php

namespace App\Services;

use App\Models\Bitacora;
use App\Repositories\BitacoraRepository;

/**
 * Registro en bitácora de inicios de sesión, altas y visitas a páginas.
 */
class AuditoriaService
{
    public function __construct(private readonly BitacoraRepository $bitacora)
    {
    }

    public function inicioSesionExitoso(int $usuarioId, string $rolCodigo, ?string $ip): void
    {
        $this->bitacora->registrar($usuarioId, Bitacora::INICIO_SESION, 'USUARIO', $usuarioId,
            ['resultado' => 'EXITO', 'canal' => 'WEB', 'rol' => $rolCodigo], $ip);
    }

    public function inicioSesionFallido(?int $usuarioId, string $correo, string $motivo, ?string $ip): void
    {
        $this->bitacora->registrar($usuarioId, Bitacora::INICIO_SESION, 'USUARIO', $usuarioId,
            ['resultado' => 'FALLIDO', 'canal' => 'WEB', 'correo' => mb_strtolower(trim($correo)), 'motivo' => $motivo], $ip);
    }

    public function registroUsuario(int $usuarioId, string $rolCodigo, ?string $ip): void
    {
        $this->bitacora->registrar($usuarioId, Bitacora::CREAR, 'USUARIO', $usuarioId,
            ['canal' => 'WEB', 'operacion' => 'REGISTRO', 'rol' => $rolCodigo], $ip);
    }

    /** Operación administrativa o de negocio hecha por un usuario (alta, cambio, aprobación, rol, precio…). */
    public function operacion(int $actorId, string $accion, string $entidad, ?int $entidadId, array $detalle, ?string $ip): void
    {
        $this->bitacora->registrar($actorId, $accion, $entidad, $entidadId, ['canal' => 'WEB'] + $detalle, $ip);
    }

    /** Registra la visita y devuelve el total acumulado de esa página. */
    public function registrarVisita(string $pagina, ?int $usuarioId, ?string $ip): int
    {
        $this->bitacora->registrar($usuarioId, Bitacora::CREAR, Bitacora::ENTIDAD_ACCESO_WEB, null,
            ['pagina' => $pagina], $ip);

        return $this->bitacora->contarVisitasPagina($pagina);
    }
}
