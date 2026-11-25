<?php

namespace App\Http\Requests\Productor;

use Illuminate\Foundation\Http\FormRequest;

class PropuestaProductoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'min:3', 'max:120'],
            'descripcion' => ['required', 'string', 'min:10', 'max:1000'],
            'presentacion' => ['required', 'string', 'min:3', 'max:60'],
            'unidad_medida' => ['required', 'in:KILOGRAMO,LIBRA,ARROBA,LITRO,UNIDAD'],
            'categoria_sugerida' => ['required', 'string', 'min:3', 'max:100'],
        ];
    }
}
