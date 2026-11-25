<?php

namespace App\Repositories;

use App\Models\ConfirmacionOperacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos web propios de cada usuario guardados como JSON en confirmacion_operacion:
 * METODO_PAGO (tarjetas simuladas) y PLAN_PAGO (planes de cuotas).
 * La baja es lógica (usada_en) porque la tabla no guarda historial aparte.
 */
class DatosWebUsuarioRepository
{
    public const METODO_PAGO = 'METODO_PAGO';
    public const PLAN_PAGO = 'PLAN_PAGO';

    private const VENCE_NUNCA = '9999-12-31 23:59:59-04';

    public function crear(int $usuarioId, string $correo, string $comando, array $parametros): ConfirmacionOperacion
    {
        return ConfirmacionOperacion::query()->create([
            'codigo_hash' => hash('sha256', 'FEDER_WEB:'.$comando.':'.$usuarioId.':'.Str::uuid()),
            'correo' => mb_strtolower($correo),
            'usuario_id' => $usuarioId,
            'comando' => $comando,
            'parametros' => $parametros,
            'expira_en' => self::VENCE_NUNCA,
        ]);
    }

    /**
     * Serializa las operaciones sobre los datos web de un usuario (por ejemplo sus tarjetas): bloquea su fila hasta
     * el final de la transacción. La tabla no tiene unicidad para estas filas JSON, así que la regla «máximo 5
     * tarjetas y una sola predeterminada» solo se sostiene si las comprobaciones se hacen de una en una por usuario.
     * FOR NO KEY UPDATE no estorba a las inserciones que referencian al usuario (pedidos, bitácora, avisos).
     */
    public function bloquearUsuario(int $usuarioId): void
    {
        DB::table('usuario')->where('id', $usuarioId)->lock('FOR NO KEY UPDATE')->first(['id']);
    }

    /** @return Collection<int, ConfirmacionOperacion> */
    public function vigentes(int $usuarioId, string $comando): Collection
    {
        return ConfirmacionOperacion::query()
            ->where('usuario_id', $usuarioId)
            ->where('comando', $comando)
            ->whereNull('usada_en')
            ->orderBy('creada_en')
            ->orderBy('id')
            ->get();
    }

    public function buscar(int $id, int $usuarioId, string $comando, bool $bloquear = false): ?ConfirmacionOperacion
    {
        $consulta = ConfirmacionOperacion::query()
            ->whereKey($id)->where('usuario_id', $usuarioId)->where('comando', $comando)->whereNull('usada_en');

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    public function buscarPlanDePedido(int $pedidoId, bool $bloquear = false): ?ConfirmacionOperacion
    {
        $consulta = ConfirmacionOperacion::query()
            ->where('comando', self::PLAN_PAGO)
            ->whereNull('usada_en')
            ->whereRaw("(parametros->>'pedido_id')::bigint = ?", [$pedidoId])
            ->orderBy('id');

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    /** Cuántos planes vigentes apuntan al pedido: un plan válido es exactamente uno. */
    public function contarPlanesDePedido(int $pedidoId): int
    {
        return ConfirmacionOperacion::query()
            ->where('comando', self::PLAN_PAGO)
            ->whereNull('usada_en')
            ->whereRaw("(parametros->>'pedido_id')::bigint = ?", [$pedidoId])
            ->count();
    }

    public function actualizarParametros(ConfirmacionOperacion $fila, array $parametros): void
    {
        $fila->parametros = $parametros;
        $fila->save();
    }

    public function darDeBaja(ConfirmacionOperacion $fila): void
    {
        $fila->usada_en = now();
        $fila->save();
    }
}
