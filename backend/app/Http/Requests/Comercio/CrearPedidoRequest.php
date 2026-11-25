<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

/** Confirmación del carrito: tipo de pedido, entrega y forma de pago. */
class CrearPedidoRequest extends FormRequest
{
    public function rules(): array
    {
        $delivery = 'required_if:modalidad,DELIVERY';

        return [
            'tipo' => ['required', 'in:INMEDIATO,ENCARGO'],
            'modalidad' => ['required', 'in:RECOJO,DELIVERY'],
            'direccion' => [$delivery, 'nullable', 'string', 'min:5', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:200'],
            'lat' => [$delivery, 'nullable', 'numeric', 'between:-90,90'],
            'lng' => [$delivery, 'nullable', 'numeric', 'between:-180,180'],
            'nombre_receptor' => ['required', 'string', 'min:3', 'max:120', "regex:/^[\pL][\pL\s'.-]*$/u"],
            'telefono_contacto' => ['required', 'string', 'regex:/^[67]\d{7}$|^[23]\d{6,7}$/'],
            'observacion' => ['nullable', 'string', 'max:500'],
            'forma_pago' => ['required', 'in:UNICO,CUOTAS'],
            'num_cuotas' => ['required_if:forma_pago,CUOTAS', 'nullable', 'integer', 'in:2,3'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.in' => 'El tipo de pedido debe ser inmediato o encargo.',
            'modalidad.required' => 'Elige si recoges tu pedido o lo enviamos a tu domicilio.',
            'direccion.required_if' => 'Escribe la dirección de entrega.',
            'lat.required_if' => 'Marca tu ubicación en el mapa para calcular el envío.',
            'lng.required_if' => 'Marca tu ubicación en el mapa para calcular el envío.',
            'nombre_receptor.required' => 'Indica quién recibirá el pedido.',
            'nombre_receptor.regex' => 'El nombre solo puede contener letras y espacios.',
            'telefono_contacto.required' => 'Indica un teléfono de contacto.',
            'telefono_contacto.regex' => 'Ingresa un teléfono boliviano válido (celular de 8 dígitos, por ejemplo 70012345).',
            'forma_pago.required' => 'Elige si pagas en un solo pago o en cuotas.',
            'num_cuotas.required_if' => 'Elige en cuántas cuotas quieres pagar.',
            'num_cuotas.in' => 'Puedes pagar en 2 o 3 cuotas.',
        ];
    }

    public function attributes(): array
    {
        return [
            'direccion' => 'dirección', 'referencia' => 'referencia', 'nombre_receptor' => 'nombre de quien recibe',
            'telefono_contacto' => 'teléfono', 'observacion' => 'observación',
        ];
    }
}
