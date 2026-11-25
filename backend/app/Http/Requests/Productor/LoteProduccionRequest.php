<?php

namespace App\Http\Requests\Productor;

use Illuminate\Foundation\Http\FormRequest;

class LoteProduccionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fecha_elaboracion' => ['nullable', 'date', 'required_with:fecha_vencimiento'],
            'fecha_vencimiento' => ['nullable', 'date', 'after_or_equal:fecha_elaboracion'],
            'cantidad_producida' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            'cantidad_ofrecida' => ['required', 'numeric', 'min:0', 'lte:cantidad_producida', 'max:999999999', 'decimal:0,3'],
            'precio_ofrecido' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha_vencimiento.after_or_equal' => 'El vencimiento no puede ser anterior a la elaboración.',
            'fecha_elaboracion.required_with' => 'Indica la fecha de elaboración si registras una fecha de vencimiento.',
            'cantidad_producida.gt' => 'La cantidad producida debe ser mayor que cero.',
            'cantidad_ofrecida.lte' => 'No puedes ofrecer más de lo producido.',
        ];
    }
}
