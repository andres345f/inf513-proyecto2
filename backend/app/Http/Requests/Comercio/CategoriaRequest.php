<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class CategoriaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'min:3', 'max:60'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'activa' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['descripcion' => 'descripción'];
    }

    public function messages(): array
    {
        return ['nombre.required' => 'Escribe el nombre de la categoría.', 'nombre.min' => 'El nombre debe tener al menos 3 caracteres.'];
    }
}
