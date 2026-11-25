<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Revocar un rol (POST /api/admin/usuarios/{usuario}/roles/{rol}/revocar): el motivo queda en la concesión y en la bitácora. */
class RevocarRolRequest extends FormRequest
{
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:3', 'max:255']];
    }

    public function attributes(): array
    {
        return ['motivo' => 'motivo'];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Escribe el motivo de la revocación.',
            'motivo.min' => 'El motivo debe tener al menos 3 caracteres.',
            'motivo.max' => 'El motivo admite hasta 255 caracteres.',
        ];
    }
}
