<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pago de un pedido o de la siguiente cuota de su plan.
 *
 * - clave_operacion: UUID que el cliente genera por cada intento y reutiliza si tiene que reintentar el mismo envío;
 *   con la misma clave el servidor devuelve el resultado ya registrado y no cobra otra vez.
 * - cuota_numero: la cuota que se quiere pagar (obligatoria cuando el pedido tiene plan de cuotas).
 * - anular_qr_pendiente: el cliente declara que no pagó su QR pendiente y quiere usar otro medio.
 */
class PagarRequest extends FormRequest
{
    public function rules(): array
    {
        $tarjetaNueva = $this->input('metodo') === 'TARJETA' && ! $this->filled('metodo_pago_id');

        return [
            'metodo' => ['required', 'in:QR,TARJETA,EFECTIVO'],
            'metodo_pago_id' => ['nullable', 'integer', 'min:1'],
            'guardar_tarjeta' => ['nullable', 'boolean'],
            'clave_operacion' => ['nullable', 'string', 'uuid:4'],
            'cuota_numero' => ['nullable', 'integer', 'between:1,3'],
            'anular_qr_pendiente' => ['nullable', 'boolean'],
        ] + ($tarjetaNueva ? TarjetaRequest::reglas('tarjeta.') : []);
    }

    public function messages(): array
    {
        return [
            'metodo.required' => 'Elige cómo quieres pagar.',
            'metodo.in' => 'El medio de pago debe ser QR, tarjeta o efectivo.',
            'clave_operacion.uuid' => 'La clave de la operación no es válida. Recarga la página e inténtalo de nuevo.',
            'clave_operacion.string' => 'La clave de la operación no es válida. Recarga la página e inténtalo de nuevo.',
            'cuota_numero.integer' => 'El número de cuota debe ser 1, 2 o 3.',
            'cuota_numero.between' => 'El número de cuota debe ser 1, 2 o 3.',
            'anular_qr_pendiente.boolean' => 'Indica con sí o no si anulas el QR pendiente.',
        ] + TarjetaRequest::mensajes('tarjeta.');
    }

    public function attributes(): array
    {
        return ['clave_operacion' => 'clave de la operación', 'cuota_numero' => 'número de cuota', 'anular_qr_pendiente' => 'anular QR pendiente'];
    }
}
