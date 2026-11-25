<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnularFacturaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            'referencia' => ['required', 'string', 'max:250'],
            'fecha' => ['required', 'date_format:Y-m-d\TH:i:sP'],
        ];
    }

    public function attributes(): array
    {
        return ['referencia' => 'referencia del sustento', 'fecha' => 'fecha de anulación'];
    }
}
