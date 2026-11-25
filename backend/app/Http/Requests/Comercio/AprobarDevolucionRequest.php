<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class AprobarDevolucionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'resolucion' => ['required', 'in:REPOSICION,REEMBOLSO'],
            'cantidad_recibida' => ['required', 'numeric', 'gt:0', 'max:999', 'decimal:0,3'],
            'cantidad_apta' => ['required', 'numeric', 'min:0', 'max:999', 'decimal:0,3'],
            'cantidad_aprobada' => ['required', 'numeric', 'gt:0', 'max:999', 'decimal:0,3'],
        ];
    }

    public function messages(): array
    {
        return [
            'resolucion.required' => 'Elige si se repone el producto o se reintegra el dinero.',
            'cantidad_recibida.gt' => 'Indica cuántas unidades recibió FEDER (al menos una).',
            'cantidad_aprobada.gt' => 'Aprueba al menos una unidad, o rechaza la devolución.',
        ];
    }

    public function attributes(): array
    {
        return ['resolucion' => 'resolución', 'cantidad_recibida' => 'cantidad recibida',
            'cantidad_apta' => 'cantidad apta', 'cantidad_aprobada' => 'cantidad aprobada'];
    }
}
