<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Asignar un rol a una cuenta (POST /api/admin/usuarios/{usuario}/roles). */
class AsignarRolRequest extends FormRequest
{
    /** Roles que se conceden a una cuenta (VISITANTE no es un rol de cuenta). */
    public const ROLES = ['ADMINISTRADOR', 'PERSONAL_FEDER', 'PRODUCTOR', 'CLIENTE'];

    public function rules(): array
    {
        return ['rol' => ['required', 'in:'.implode(',', self::ROLES)]];
    }

    public function attributes(): array
    {
        return ['rol' => 'rol'];
    }

    public function messages(): array
    {
        return ['rol.required' => 'Elige el rol a asignar.', 'rol.in' => 'El rol elegido no existe.'];
    }
}
