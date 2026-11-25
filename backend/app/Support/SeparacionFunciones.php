<?php

namespace App\Support;

use App\Exceptions\NegocioException;
use App\Models\Usuario;

/**
 * Separación de funciones heredada del Proyecto 1 (com.feder.negocio.pagos.SeparacionFunciones, versión dfbec26).
 *
 * Una cuenta del personal que también es cliente no realiza sobre SUS PROPIOS pedidos los actos que mueven dinero o
 * mercancía: verificar o rechazar su pago, cobrar su efectivo en la entrega, aprobar su devolución y registrar el
 * resultado de sus reembolsos. La comprobación usa la identidad de la cuenta (usuario_id), no el rol activo, así que
 * cambiar de rol o de sesión no la evita. No es una prohibición general sobre cualquier operación propia.
 */
final class SeparacionFunciones
{
    private function __construct()
    {
    }

    /**
     * @param  object  $pedido  fila de pedido con usuario_id y codigo
     * @param  string  $accion  frase en infinitivo para el mensaje, p. ej. «confirmar el pago»
     */
    public static function exigirAjeno(Usuario $actor, object $pedido, string $accion): void
    {
        if (isset($pedido->usuario_id) && (int) $pedido->usuario_id === (int) $actor->id) {
            throw new NegocioException(
                "No puedes {$accion} de tu propio pedido {$pedido->codigo}: debe hacerlo otro miembro del personal de FEDER.",
                403
            );
        }
    }
}
