<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class CatalogoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'],
            'categoria' => ['nullable', 'integer', 'min:1'],
            'comunidad' => ['nullable', 'integer', 'min:1'],
            'precio_min' => ['nullable', 'numeric', 'min:0'],
            'precio_max' => ['nullable', 'numeric', 'min:0', 'gte:precio_min'],
            'con_stock' => ['nullable', 'boolean'],
            'orden' => ['nullable', 'in:nombre,precio_asc,precio_desc'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'precio_max.gte' => 'El precio máximo no puede ser menor que el mínimo.',
            'orden.in' => 'El orden elegido no es válido.',
        ];
    }

    public function attributes(): array
    {
        return ['q' => 'búsqueda', 'precio_min' => 'precio mínimo', 'precio_max' => 'precio máximo'];
    }
}
