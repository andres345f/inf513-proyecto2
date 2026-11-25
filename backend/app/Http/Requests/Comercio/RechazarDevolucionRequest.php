<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class RechazarDevolucionRequest extends FormRequest
{
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:3', 'max:200']];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Indica por qué se rechaza la devolución.',
            'motivo.min' => 'El motivo debe tener al menos 3 caracteres.',
        ];
    }
}
