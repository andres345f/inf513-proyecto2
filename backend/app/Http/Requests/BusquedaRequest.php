<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BusquedaRequest extends FormRequest
{
    public function rules(): array
    {
        return ['q' => ['required', 'string', 'min:2', 'max:60']];
    }

    public function messages(): array
    {
        return [
            'q.required' => 'Escribe lo que quieres buscar.',
            'q.min' => 'Escribe al menos 2 caracteres para buscar.',
            'q.max' => 'La búsqueda admite como máximo 60 caracteres.',
        ];
    }
}
