<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class CotizarEnvioRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'lat.required' => 'Marca tu ubicación en el mapa.',
            'lng.required' => 'Marca tu ubicación en el mapa.',
        ];
    }
}
