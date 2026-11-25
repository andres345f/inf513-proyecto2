<?php

namespace App\Http\Requests\Productor;

use Illuminate\Foundation\Http\FormRequest;

/** Aprobar o rechazar el cambio de comunidad de un productor (POST /api/gestion/cambios-comunidad-productor/{solicitud}). */
class ResolverCambioComunidadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'aprobar' => ['required', 'boolean'],
            // Al rechazar, el motivo es obligatorio (se le comunica al productor).
            'motivo' => ['nullable', 'required_if_declined:aprobar', 'string', 'min:5', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['aprobar' => 'decisión', 'motivo' => 'motivo del rechazo'];
    }

    public function messages(): array
    {
        return [
            'aprobar.required' => 'Indica si apruebas o rechazas el cambio de comunidad.',
            'motivo.required_if_declined' => 'Escribe el motivo del rechazo.',
            'motivo.min' => 'El motivo debe tener al menos 5 caracteres.',
        ];
    }
}
