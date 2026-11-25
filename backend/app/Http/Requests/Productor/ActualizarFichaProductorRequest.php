<?php

namespace App\Http\Requests\Productor;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarFichaProductorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ubicacion_actividad' => ['required', 'string', 'min:10', 'max:200'],
            'comunidad_id' => ['nullable', 'integer', 'exists:comunidad_agricola,id'],
        ];
    }
}
