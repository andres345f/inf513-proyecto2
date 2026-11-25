<?php

namespace App\Http\Requests\Productor;

use Illuminate\Foundation\Http\FormRequest;

class ProduccionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'producto_id' => ['required', 'integer', 'exists:producto,id'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_disponibilidad_estimada' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'cantidad_estimada' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            'ubicacion_produccion' => ['required', 'string', 'min:5', 'max:200'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** Nombres de los campos en los mensajes de validación (pedido del frontend: «fecha de inicio», no «fecha_inicio»). */
    public function attributes(): array
    {
        return [
            'producto_id' => 'producto',
            'fecha_inicio' => 'fecha de inicio',
            'fecha_disponibilidad_estimada' => 'fecha estimada de disponibilidad',
            'cantidad_estimada' => 'cantidad estimada',
            'ubicacion_produccion' => 'ubicación de la producción',
            'observacion' => 'observación',
        ];
    }

    public function messages(): array
    {
        return [
            'producto_id.required' => 'Elige el producto que estás produciendo.',
            'fecha_disponibilidad_estimada.after_or_equal' => 'La disponibilidad no puede ser anterior al inicio.',
            'cantidad_estimada.gt' => 'La cantidad estimada debe ser mayor que cero.',
        ];
    }
}
