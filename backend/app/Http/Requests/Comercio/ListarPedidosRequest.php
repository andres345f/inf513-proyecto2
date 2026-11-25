<?php

namespace App\Http\Requests\Comercio;

use App\Repositories\PedidoRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListarPedidosRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'estado' => ['nullable', Rule::in(PedidoRepository::ESTADOS)],
            'tipo' => ['nullable', 'in:INMEDIATO,ENCARGO'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.'];
    }

    public function attributes(): array
    {
        return ['desde' => 'fecha inicial', 'hasta' => 'fecha final'];
    }
}
