<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarPermisosRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'permisos' => ['present', 'array', 'max:60'],
            'permisos.*' => ['string', 'distinct', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'permisos.present' => 'Envía la lista de permisos del rol (puede estar vacía).',
            'permisos.*.distinct' => 'Hay permisos repetidos.',
        ];
    }
}
