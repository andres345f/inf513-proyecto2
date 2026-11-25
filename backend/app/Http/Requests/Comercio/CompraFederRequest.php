<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class CompraFederRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'lineas' => ['required', 'array', 'min:1', 'max:20'],
            'lineas.*.lote_id' => ['required', 'integer', 'distinct', 'exists:lote_produccion,id'],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            'lineas.*.precio' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,2'],
            'modalidad_traslado' => ['required', 'in:ENTREGA_PRODUCTOR,RECOJO_FEDER'],
            'fecha_acordada' => ['nullable', 'date'],
            'responsable_traslado' => ['nullable', 'string', 'max:120'],
            'telefono_traslado' => ['nullable', 'string', 'max:20'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
