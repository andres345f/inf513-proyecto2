<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class ListarComprasRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'],
            'estado' => ['nullable', 'in:REGISTRADA,RECIBIDA_PARCIAL,RECIBIDA,CANCELADA'],
            'pago' => ['nullable', 'in:PENDIENTE,PAGADA'],
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
        return ['q' => 'búsqueda', 'desde' => 'fecha inicial', 'hasta' => 'fecha final'];
    }
}
