<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Rango de fechas opcional para el panel y las estadísticas de acceso. */
class RangoFechasRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ];
    }

    public function messages(): array
    {
        return ['hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.'];
    }

    public function attributes(): array
    {
        return ['desde' => 'fecha inicial', 'hasta' => 'fecha final'];
    }
}
