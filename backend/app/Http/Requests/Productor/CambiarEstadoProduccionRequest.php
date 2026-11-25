<?php

namespace App\Http\Requests\Productor;

use Illuminate\Foundation\Http\FormRequest;

/** Cambio de estado de una producción por su productor (POST /api/productor/producciones/{produccion}/estado). */
class CambiarEstadoProduccionRequest extends FormRequest
{
    public function rules(): array
    {
        return ['estado' => ['required', 'in:EN_CURSO,TERMINADA,CANCELADA']];
    }

    public function attributes(): array
    {
        return ['estado' => 'estado de la producción'];
    }

    public function messages(): array
    {
        return [
            'estado.required' => 'Indica el nuevo estado de la producción.',
            'estado.in' => 'El estado elegido no es válido.',
        ];
    }
}
