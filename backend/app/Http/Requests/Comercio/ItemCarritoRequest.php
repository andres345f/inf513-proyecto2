<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

/** Agregar un producto o cambiar su cantidad en el carrito. */
class ItemCarritoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'producto_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'cantidad' => ['required', 'numeric', 'gt:0', 'max:999', 'decimal:0,3'],
        ];
    }

    public function messages(): array
    {
        return [
            'producto_id.required' => 'Indica qué producto quieres agregar.',
            'cantidad.required' => 'Indica la cantidad.',
            'cantidad.gt' => 'La cantidad debe ser mayor que cero.',
            'cantidad.max' => 'Puedes llevar como máximo 999 de cada producto.',
            'cantidad.decimal' => 'La cantidad admite hasta 3 decimales.',
        ];
    }
}
