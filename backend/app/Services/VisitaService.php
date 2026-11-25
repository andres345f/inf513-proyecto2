<?php

namespace App\Services;

use App\Repositories\ConfiguracionWebRepository;
use App\Support\PaginasWeb;
use App\Support\SesionActiva;

/**
 * Contador de visitas del pie de página.
 *
 * Solo se cuenta una visita cuando la página existe en la aplicación y quien la abre puede verla:
 * una dirección inventada, o una pantalla privada a la que se llega sin permiso (la aplicación redirige o muestra
 * «sin permiso»), no deja fila en la bitácora ni suma. En esos casos se responde con total 0 y «registrada: false»
 * para que el pie siga mostrándose sin error.
 */
class VisitaService
{
    public function __construct(
        private readonly AuditoriaService $auditoria,
        private readonly ConfiguracionWebRepository $configuracion,
        private readonly PermisoService $permisos,
    ) {
    }

    /** @return array{pagina:string, total:int, registrada:bool} */
    public function registrar(string $pagina, ?SesionActiva $sesion, ?string $ip): array
    {
        $conocida = PaginasWeb::resolver($pagina, $this->configuracion->entradasMenu());

        if ($conocida === null) {
            return ['pagina' => PaginasWeb::normalizar($pagina), 'total' => 0, 'registrada' => false];
        }

        if ($conocida['permiso'] !== null && ! $this->permisos->tienePermiso($sesion, $conocida['permiso'])) {
            return ['pagina' => $conocida['pagina'], 'total' => 0, 'registrada' => false];
        }

        return [
            'pagina' => $conocida['pagina'],
            'total' => $this->auditoria->registrarVisita($conocida['pagina'], $sesion?->usuario->id, $ip),
            'registrada' => true,
        ];
    }
}
