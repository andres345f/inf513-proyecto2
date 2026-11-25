<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class ListarProduccionesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'],
            'estado' => ['nullable', 'in:PLANIFICADA,EN_CURSO,TERMINADA,CANCELADA'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['q' => 'búsqueda'];
    }
}
