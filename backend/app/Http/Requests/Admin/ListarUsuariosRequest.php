<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Filtros del listado de cuentas (GET /api/admin/usuarios). */
class ListarUsuariosRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:80'],
            'rol' => ['nullable', 'in:'.implode(',', AsignarRolRequest::ROLES)],
            'activo' => ['nullable', 'boolean'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['q' => 'búsqueda', 'rol' => 'rol', 'activo' => 'estado de la cuenta', 'pagina' => 'página'];
    }

    public function messages(): array
    {
        return ['rol.in' => 'El rol elegido no existe.', 'pagina.min' => 'La página debe ser 1 o mayor.'];
    }
}
