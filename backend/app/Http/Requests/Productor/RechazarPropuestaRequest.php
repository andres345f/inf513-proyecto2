<?php

namespace App\Http\Requests\Productor;

use Illuminate\Foundation\Http\FormRequest;

/** Rechazar una propuesta de producto (POST /api/gestion/productos-propuestos/{propuesta}/rechazar). */
class RechazarPropuestaRequest extends FormRequest
{
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:5', 'max:255']];
    }

    public function attributes(): array
    {
        return ['motivo' => 'motivo del rechazo'];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Escribe el motivo del rechazo.',
            'motivo.min' => 'El motivo debe tener al menos 5 caracteres.',
        ];
    }
}
