<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\Usuario;
use App\Repositories\ProductorRepository;
use App\Repositories\RolRepository;
use App\Repositories\UsuarioRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Productores y comunidades agrícolas (CU2): revisión de solicitudes de afiliación.
 * Al aprobar se asigna el código de productor y el rol PRODUCTOR.
 */
class ProductorService
{
    public function __construct(
        private readonly ProductorRepository $productores,
        private readonly UsuarioRepository $usuarios,
        private readonly RolRepository $roles,
        private readonly AuditoriaService $auditoria,
    ) {
    }

    public function listar(array $filtros): array
    {
        $pagina = $this->productores->listar($filtros);

        return [
            'productores' => collect($pagina->items())->map(fn ($p) => [
                'id' => (int) $p->id,
                'nombre' => $p->nombre,
                'correo' => $p->correo,
                'telefono' => $p->telefono,
                'codigo' => $p->codigo_productor,
                'estado' => $p->estado_solicitud,
                'activo' => (bool) $p->activo,
                'comunidad' => $p->comunidad,
                'municipio' => $p->municipio,
                'comunidad_nueva' => ! $p->comunidad_activa && $this->productores->esComunidadPropuesta((int) $p->comunidad_id),
                'ubicacion' => $p->ubicacion_actividad,
                'fecha_solicitud' => $p->fecha_solicitud,
                'fecha_revision' => $p->fecha_revision,
                'motivo_rechazo' => $p->motivo_rechazo,
            ])->all(),
            'paginacion' => ['pagina' => $pagina->currentPage(), 'paginas' => $pagina->lastPage(), 'total' => $pagina->total()],
        ];
    }

    public function aprobar(Usuario $revisor, int $id, ?string $ip): array
    {
        return DB::transaction(function () use ($revisor, $id, $ip) {
            $productor = $this->solicitudPendiente($id);
            if ((int) $productor->usuario_id === (int) $revisor->id) {
                throw new NegocioException('No puedes aprobar tu propia solicitud de productor.', 403);
            }
            $comunidadId = (int) $productor->comunidad_agricola_id;
            $comunidad = $this->productores->bloquearComunidad($comunidadId);
            if (! $comunidad->activa && ! $this->productores->esComunidadPropuesta($comunidadId)) {
                throw new NegocioException('La comunidad está desactivada. Revisa su vigencia en Comunidades antes de aprobar.');
            }
            $codigo = $this->productores->aprobar($id);
            $comunidadActivada = ! $comunidad->activa && $this->productores->activarComunidad($comunidadId);
            if ($comunidadActivada) {
                $this->auditoria->operacion($revisor->id, 'MODIFICAR', 'COMUNIDAD_AGRICOLA', $comunidadId,
                    ['operacion' => 'APROBAR_COMUNIDAD_PROPUESTA', 'activa' => true], $ip);
            }

            $rol = $this->roles->buscarPorCodigo('PRODUCTOR');
            if (! $this->usuarios->rolesVigentes((int) $productor->usuario_id)->contains('codigo', 'PRODUCTOR')) {
                $this->usuarios->concederRolVigente((int) $productor->usuario_id, $rol->id, 'Solicitud de productor aprobada', $revisor->id);
            }

            $this->auditoria->operacion($revisor->id, 'APROBAR_SOLICITUD', 'PRODUCTOR', $id,
                ['codigo_productor' => $codigo, 'comunidad_activada' => $comunidadActivada], $ip);

            return ['message' => "Solicitud aprobada. Código de productor: {$codigo}."
                .($comunidadActivada ? ' La comunidad propuesta quedó habilitada.' : '')];
        });
    }

    public function rechazar(Usuario $revisor, int $id, string $motivo, ?string $ip): array
    {
        return DB::transaction(function () use ($revisor, $id, $motivo, $ip) {
            $this->solicitudPendiente($id);
            $this->productores->rechazar($id, $motivo);
            $this->auditoria->operacion($revisor->id, 'RECHAZAR_SOLICITUD', 'PRODUCTOR', $id, ['motivo' => $motivo], $ip);

            return ['message' => 'Solicitud rechazada.'];
        });
    }

    /** Ficha del productor que inició sesión. */
    public function miFicha(int $usuarioId): array
    {
        $p = $this->productores->buscarPorUsuario($usuarioId) ?? throw new NegocioException('Tu cuenta no tiene una ficha de productor.', 404);

        return [
            'id' => (int) $p->id,
            'comunidad_id' => (int) $p->comunidad_agricola_id,
            'codigo' => $p->codigo_productor,
            'estado' => $p->estado_solicitud,
            'activo' => (bool) $p->activo,
            'comunidad' => $p->comunidad,
            'municipio' => $p->municipio,
            'departamento' => $p->departamento,
            'ubicacion' => $p->ubicacion_actividad,
            'fecha_solicitud' => $p->fecha_solicitud,
            'fecha_afiliacion' => $p->fecha_afiliacion,
            'motivo_rechazo' => $p->motivo_rechazo,
        ];
    }

    /** Estado de afiliación visible desde el perfil de cualquier cliente. */
    public function estadoSolicitud(int $usuarioId): array
    {
        $p = $this->productores->buscarPorUsuario($usuarioId);

        return ['solicitud' => $p ? [
            'id' => (int) $p->id,
            'estado' => $p->estado_solicitud,
            'comunidad_id' => (int) $p->comunidad_agricola_id,
            'comunidad' => $p->comunidad,
            'comunidad_nueva' => ! $p->comunidad_activa && $this->productores->esComunidadPropuesta((int) $p->comunidad_agricola_id),
            'municipio' => $p->municipio,
            'ubicacion' => $p->ubicacion_actividad,
            'fecha_solicitud' => $p->fecha_solicitud,
            'fecha_revision' => $p->fecha_revision,
            'motivo_rechazo' => $p->motivo_rechazo,
        ] : null];
    }

    public function comunidadesDisponibles(): array
    {
        return $this->productores->comunidades()->where('activa', true)->map(fn ($c) => [
            'id' => (int) $c->id,
            'nombre' => $c->nombre,
            'municipio' => $c->municipio,
            'departamento' => $c->departamento,
        ])->values()->all();
    }

    public function solicitar(Usuario $usuario, array $datos, ?string $ip): array
    {
        if (empty($datos['comunidad_nueva'])) {
            $comunidad = $this->productores->comunidadActiva((int) $datos['comunidad_id']);
            if ($comunidad === null) {
                throw ValidationException::withMessages(['comunidad_id' => ['Esa comunidad no está habilitada para nuevas solicitudes.']]);
            }
        }

        $ubicacion = trim($datos['ubicacion_actividad']);
        $existente = $this->productores->buscarPorUsuario($usuario->id);
        if ($existente && $existente->estado_solicitud === 'APROBADA') {
            throw new NegocioException('Tu cuenta ya está afiliada como productor.');
        }
        if ($existente && $existente->estado_solicitud === 'PENDIENTE') {
            throw new NegocioException('Ya tienes una solicitud pendiente de revisión.');
        }

        $id = DB::transaction(function () use ($usuario, $existente, $datos, $ubicacion, $ip) {
            $comunidadId = empty($datos['comunidad_nueva'])
                ? (int) $datos['comunidad_id']
                : $this->comunidadPropuesta($usuario, $datos['comunidad_nueva'], $ip);

            if ($existente) {
                $this->productores->reenviarSolicitud((int) $existente->id, $comunidadId, $ubicacion);
                $id = (int) $existente->id;
            } else {
                $id = $this->productores->crearSolicitud($usuario->id, $comunidadId, $ubicacion);
            }
            $this->auditoria->operacion($usuario->id, 'CREAR', 'PRODUCTOR', $id,
                ['operacion' => $existente ? 'REENVIAR_SOLICITUD' : 'SOLICITAR_AFILIACION', 'comunidad_nueva' => ! empty($datos['comunidad_nueva'])], $ip);

            return $id;
        });

        $mensaje = empty($datos['comunidad_nueva'])
            ? 'Enviamos tu solicitud. El personal de FEDER revisará los datos y te notificará el resultado.'
            : 'Enviamos tu solicitud junto con tu comunidad. El personal de FEDER la revisará y, si la aprueba, tu comunidad quedará registrada.';

        return ['id' => $id, 'message' => $mensaje];
    }

    /**
     * Comunidad que el solicitante no encontró en la lista: se registra inactiva (no aparece para otros)
     * y se activa cuando el personal aprueba la solicitud. Si ya estaba propuesta por otra persona, se reutiliza.
     */
    private function comunidadPropuesta(Usuario $usuario, array $nueva, ?string $ip): int
    {
        $existente = $this->productores->comunidadPorNombre($nueva['nombre'], $nueva['municipio']);
        if ($existente && $existente->activa) {
            throw ValidationException::withMessages(['comunidad_nueva.nombre' => ["La comunidad «{$existente->nombre}» ya está en la lista: elígela allí."]]);
        }
        if ($existente) {
            if (! $this->productores->esComunidadPropuesta((int) $existente->id)) {
                throw ValidationException::withMessages(['comunidad_nueva.nombre' => ['La comunidad ya existe y está desactivada. Contacta con FEDER para revisar su vigencia.']]);
            }

            return (int) $existente->id;
        }

        $id = $this->productores->guardarComunidad(null, [
            'nombre' => $nueva['nombre'],
            'municipio' => $nueva['municipio'],
            'departamento' => ($nueva['departamento'] ?? '') ?: 'Santa Cruz',
            'descripcion' => 'Propuesta por '.$usuario->nombreCompleto().' al solicitar su afiliación como productor.',
            'activa' => false,
        ]);
        $this->auditoria->operacion($usuario->id, 'CREAR', 'COMUNIDAD_AGRICOLA', $id, ['nombre' => $nueva['nombre'], 'operacion' => 'PROPUESTA'], $ip);

        return $id;
    }

    public function comunidades(): array
    {
        return $this->productores->comunidades()->map(fn ($c) => [
            'id' => (int) $c->id, 'nombre' => $c->nombre, 'municipio' => $c->municipio, 'departamento' => $c->departamento,
            'descripcion' => $c->descripcion, 'activa' => (bool) $c->activa, 'productores' => (int) $c->productores,
        ])->all();
    }

    public function guardarComunidad(Usuario $personal, ?int $id, array $datos, ?string $ip): array
    {
        return DB::transaction(function () use ($personal, $id, $datos, $ip) {
            if ($id !== null && ! $this->productores->bloquearComunidad($id)) {
                throw new NegocioException('Esa comunidad no existe.', 404);
            }
            if ($this->productores->existeComunidad($datos['nombre'], $datos['municipio'], $id)) {
                throw ValidationException::withMessages(['nombre' => ['Ya existe una comunidad con ese nombre en ese municipio.']]);
            }

            $comunidadId = $this->productores->guardarComunidad($id, [
                'nombre' => trim($datos['nombre']),
                'municipio' => trim($datos['municipio']),
                'departamento' => trim($datos['departamento'] ?? 'Santa Cruz') ?: 'Santa Cruz',
                'descripcion' => $datos['descripcion'] ?? null,
                'activa' => (bool) ($datos['activa'] ?? true),
            ]);
            $this->auditoria->operacion($personal->id, $id === null ? 'CREAR' : 'MODIFICAR', 'COMUNIDAD_AGRICOLA', $comunidadId, ['nombre' => $datos['nombre'], 'activa' => (bool) ($datos['activa'] ?? true)], $ip);

            return ['id' => $comunidadId, 'message' => $id === null ? 'Comunidad creada.' : 'Comunidad actualizada.'];
        });
    }

    private function solicitudPendiente(int $id): object
    {
        $productor = $this->productores->buscar($id, bloquear: true) ?? throw new NegocioException('Ese productor no existe.', 404);
        if ($productor->estado_solicitud !== 'PENDIENTE') {
            throw new NegocioException('Esta solicitud ya fue revisada.');
        }

        return $productor;
    }
}
