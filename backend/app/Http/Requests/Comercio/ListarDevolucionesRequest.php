<?php

namespace App\Http\Requests\Comercio;

use App\Services\DevolucionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListarDevolucionesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'],
            'estado' => ['nullable', Rule::in(DevolucionService::ESTADOS)],
            'resolucion' => ['nullable', 'in:REPOSICION,REEMBOLSO'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.'];
    }

    public function attributes(): array
    {
        return ['q' => 'búsqueda', 'resolucion' => 'resolución', 'desde' => 'fecha inicial', 'hasta' => 'fecha final'];
    }
}
