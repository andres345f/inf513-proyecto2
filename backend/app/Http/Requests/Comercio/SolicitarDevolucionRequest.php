<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class SolicitarDevolucionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'despacho_id' => ['required', 'integer', 'min:1'],
            'cantidad' => ['required', 'numeric', 'gt:0', 'max:999', 'decimal:0,3'],
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'despacho_id.required' => 'Elige el producto que quieres devolver.',
            'cantidad.required' => 'Indica cuántas unidades devuelves.',
            'cantidad.gt' => 'La cantidad debe ser mayor a cero.',
            'motivo.required' => 'Cuéntanos qué pasó con el producto.',
            'motivo.min' => 'Describe el problema con al menos 5 caracteres.',
        ];
    }

    public function attributes(): array
    {
        return ['despacho_id' => 'producto', 'cantidad' => 'cantidad', 'motivo' => 'motivo'];
    }
}
