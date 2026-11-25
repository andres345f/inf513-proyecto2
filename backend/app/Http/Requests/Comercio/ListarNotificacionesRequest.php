<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class ListarNotificacionesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'no_leidas' => ['nullable', 'boolean'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['no_leidas' => 'solo las no leídas', 'pagina' => 'página'];
    }
}
