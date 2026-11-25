<?php

namespace App\Http\Requests\Productor;

use Illuminate\Foundation\Http\FormRequest;

/** Filtros del listado de productores y solicitudes de afiliación (GET /api/gestion/productores). */
class ListarProductoresRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:80'],
            'estado' => ['nullable', 'in:PENDIENTE,APROBADA,RECHAZADA'],
            'comunidad' => ['nullable', 'integer', 'min:1'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['q' => 'búsqueda', 'estado' => 'estado de la solicitud', 'comunidad' => 'comunidad', 'pagina' => 'página'];
    }

    public function messages(): array
    {
        return ['estado.in' => 'El estado elegido no es válido.'];
    }
}
