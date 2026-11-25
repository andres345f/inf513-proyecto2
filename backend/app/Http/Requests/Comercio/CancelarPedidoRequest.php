<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class CancelarPedidoRequest extends FormRequest
{
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:3', 'max:200']];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Cuéntanos brevemente por qué cancelas el pedido.',
            'motivo.min' => 'El motivo debe tener al menos 3 caracteres.',
        ];
    }
}
