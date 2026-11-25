<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class ListarInventarioRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'],
            'estado' => ['nullable', 'in:DISPONIBLE,AGOTADO,VENCIDO,BLOQUEADO'],
            'por_vencer' => ['nullable', 'boolean'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['q' => 'búsqueda', 'por_vencer' => 'próximo a vencer', 'pagina' => 'página'];
    }

    public function messages(): array
    {
        return ['estado.in' => 'El estado elegido no es válido.'];
    }
}
