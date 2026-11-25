<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class ListarProductosRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'],
            'categoria' => ['nullable', 'integer', 'min:1'],
            'activo' => ['nullable', 'boolean'],
            'stock_bajo' => ['nullable', 'boolean'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['q' => 'búsqueda', 'categoria' => 'categoría', 'stock_bajo' => 'stock bajo', 'pagina' => 'página'];
    }
}
