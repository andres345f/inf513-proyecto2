<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Avance de un pedido por el personal (preparar, despachar o entregar). Lo comparten las rutas
 * /gestion/pedidos/{id}/avanzar y /gestion/entregas/{id}/avanzar.
 *
 * «hacia» es obligatorio: es el estado al que la pantalla ofrecía avanzar (acciones.avanzar en el detalle del
 * pedido, avanzar_a en la lista de entregas). Si el pedido ya no está donde se veía, el servicio responde 409 en
 * lugar de dar otro paso: una pestaña antigua o una petición repetida nunca ejecuta otra transición.
 */
class AvanzarPedidoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'hacia' => ['required', 'string', 'in:EN_PREPARACION,DESPACHADO,ENTREGADO'],
            'responsable_entrega' => ['nullable', 'string', 'min:3', 'max:120'],
            'telefono_responsable' => ['nullable', 'string', 'max:20'],
            'efectivo_confirmado' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'hacia.required' => 'Falta indicar a qué estado quieres llevar el pedido. Recarga la página e inténtalo de nuevo.',
            'hacia.in' => 'El estado al que quieres llevar el pedido no es válido. Recarga la página e inténtalo de nuevo.',
            'responsable_entrega.min' => 'Escribe el nombre de quien llevará el pedido (al menos 3 letras).',
            'responsable_entrega.max' => 'El nombre de quien llevará el pedido no puede pasar de 120 caracteres.',
            'telefono_responsable.max' => 'El teléfono del responsable no puede pasar de 20 caracteres.',
            'efectivo_confirmado.boolean' => 'Indica sí o no para confirmar que se recibió el efectivo.',
        ];
    }

    public function attributes(): array
    {
        return [
            'hacia' => 'estado de destino', 'responsable_entrega' => 'responsable de la entrega',
            'telefono_responsable' => 'teléfono del responsable', 'efectivo_confirmado' => 'confirmación del efectivo',
        ];
    }
}
