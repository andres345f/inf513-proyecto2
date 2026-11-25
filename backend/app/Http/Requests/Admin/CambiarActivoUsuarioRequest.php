<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Activar o desactivar una cuenta (PATCH /api/admin/usuarios/{usuario}/activo). */
class CambiarActivoUsuarioRequest extends FormRequest
{
    public function rules(): array
    {
        return ['activo' => ['required', 'boolean']];
    }

    public function attributes(): array
    {
        return ['activo' => 'estado de la cuenta'];
    }

    public function messages(): array
    {
        return [
            'activo.required' => 'Indica si la cuenta queda activa.',
            'activo.boolean' => 'Indica si la cuenta queda activa (sí o no).',
        ];
    }
}
