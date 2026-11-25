<?php

namespace App\Services;

use App\Exceptions\NegocioException;
use App\Models\ConfirmacionOperacion;
use App\Models\Usuario;
use App\Repositories\DatosWebUsuarioRepository;
use App\Support\TarjetaSimulada;
use Illuminate\Support\Facades\DB;

/**
 * Métodos de pago del usuario: tarjetas simuladas guardadas como METODO_PAGO.
 * Se guardan titular, marca, últimos 4 dígitos, vencimiento y el resultado simulado; nunca el número ni el CVV.
 */
class MetodoPagoService
{
    private const MAXIMO_TARJETAS = 5;

    public function __construct(private readonly DatosWebUsuarioRepository $datos)
    {
    }

    public function listar(int $usuarioId): array
    {
        return $this->datos->vigentes($usuarioId, DatosWebUsuarioRepository::METODO_PAGO)
            ->map(fn (ConfirmacionOperacion $m) => $this->formatear($m))
            ->values()
            ->all();
    }

    /** Validaciones que dependen del número (Luhn) y de la fecha actual. */
    public function validarTarjetaNueva(array $tarjeta): void
    {
        if (! TarjetaSimulada::luhnValido($tarjeta['numero'])) {
            throw new NegocioException('El número de tarjeta no es válido. Revísalo e inténtalo de nuevo.');
        }
        if (TarjetaSimulada::vencida((int) $tarjeta['expira_mes'], (int) $tarjeta['expira_anio'])) {
            throw new NegocioException('La tarjeta está vencida.');
        }
    }

    /**
     * Alta de una tarjeta. Las altas, bajas y cambios de predeterminada de un mismo usuario se hacen de una en una:
     * se bloquea su fila y recién entonces se leen sus tarjetas, para que dos altas a la vez no dejen dos
     * predeterminadas ni superen el máximo.
     */
    public function registrar(Usuario $usuario, array $tarjeta): array
    {
        $fila = DB::transaction(function () use ($usuario, $tarjeta) {
            $this->datos->bloquearUsuario($usuario->id);

            $vigentes = $this->datos->vigentes($usuario->id, DatosWebUsuarioRepository::METODO_PAGO);
            if ($vigentes->count() >= self::MAXIMO_TARJETAS) {
                throw new NegocioException('Puedes guardar como máximo '.self::MAXIMO_TARJETAS.' tarjetas. Elimina una para agregar otra.');
            }

            $numero = TarjetaSimulada::normalizar($tarjeta['numero']);
            $ultimos4 = substr($numero, -4);
            $duplicada = $vigentes->contains(fn ($m) => $m->parametros['ultimos4'] === $ultimos4
                && (int) $m->parametros['expira_mes'] === (int) $tarjeta['expira_mes']
                && (int) $m->parametros['expira_anio'] === (int) $tarjeta['expira_anio']);
            if ($duplicada) {
                throw new NegocioException('Esa tarjeta ya está guardada en tus métodos de pago.');
            }

            return $this->datos->crear($usuario->id, $usuario->correo, DatosWebUsuarioRepository::METODO_PAGO, [
                'tipo' => 'TARJETA',
                'marca' => TarjetaSimulada::marca($numero),
                'ultimos4' => $ultimos4,
                'titular' => mb_strtoupper(trim($tarjeta['titular'])),
                'expira_mes' => (int) $tarjeta['expira_mes'],
                'expira_anio' => (int) $tarjeta['expira_anio'],
                'alias' => trim($tarjeta['alias'] ?? '') ?: null,
                'simulacion' => TarjetaSimulada::comportamiento($numero),
                'predeterminado' => ! $vigentes->contains(fn ($m) => (bool) ($m->parametros['predeterminado'] ?? false)),
            ]);
        });

        return $this->formatear($fila);
    }

    public function buscar(int $usuarioId, int $id): ConfirmacionOperacion
    {
        return $this->datos->buscar($id, $usuarioId, DatosWebUsuarioRepository::METODO_PAGO)
            ?? throw new NegocioException('Ese método de pago no existe.', 404);
    }

    public function eliminar(int $usuarioId, int $id): void
    {
        DB::transaction(function () use ($usuarioId, $id) {
            $this->datos->bloquearUsuario($usuarioId);
            $metodo = $this->buscar($usuarioId, $id);
            $eraPredeterminado = (bool) ($metodo->parametros['predeterminado'] ?? false);
            $this->datos->darDeBaja($metodo);

            if ($eraPredeterminado && ($siguiente = $this->datos->vigentes($usuarioId, DatosWebUsuarioRepository::METODO_PAGO)->first())) {
                $this->datos->actualizarParametros($siguiente, ['predeterminado' => true] + $siguiente->parametros);
            }
        });
    }

    public function marcarPredeterminado(int $usuarioId, int $id): void
    {
        DB::transaction(function () use ($usuarioId, $id) {
            $this->datos->bloquearUsuario($usuarioId);
            $this->buscar($usuarioId, $id);
            foreach ($this->datos->vigentes($usuarioId, DatosWebUsuarioRepository::METODO_PAGO) as $metodo) {
                $this->datos->actualizarParametros($metodo, ['predeterminado' => $metodo->id === $id] + $metodo->parametros);
            }
        });
    }

    public function formatear(ConfirmacionOperacion $m): array
    {
        $p = $m->parametros;

        return [
            'id' => $m->id,
            'tipo' => $p['tipo'],
            'marca' => $p['marca'],
            'ultimos4' => $p['ultimos4'],
            'titular' => $p['titular'],
            'expira' => sprintf('%02d/%d', $p['expira_mes'], $p['expira_anio']),
            'alias' => $p['alias'] ?? null,
            'predeterminado' => (bool) ($p['predeterminado'] ?? false),
            'vencida' => TarjetaSimulada::vencida((int) $p['expira_mes'], (int) $p['expira_anio']),
        ];
    }
}
