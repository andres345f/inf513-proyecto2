<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class RecepcionCompraRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.detalle_id' => ['required', 'integer', 'distinct', 'exists:detalle_compra,id'],
            'lineas.*.recibida' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'lineas.*.rechazada' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'lineas.*.cancelada' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'ubicacion_almacen' => ['nullable', 'string', 'max:60'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
