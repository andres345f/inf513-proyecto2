<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class RechazarPagoRequest extends FormRequest
{
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:3', 'max:200']];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Indica por qué el pago no se recibió.',
            'motivo.min' => 'El motivo debe tener al menos 3 caracteres.',
        ];
    }
}
