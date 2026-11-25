<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarVisitaRequest extends FormRequest
{
    public function rules(): array
    {
        return ['pagina' => ['required', 'string', 'max:200', 'regex:/^\/[^\s?#]*$/']];
    }

    public function messages(): array
    {
        return [
            'pagina.required' => 'Falta indicar la página visitada.',
            'pagina.regex' => 'La página visitada no tiene un formato válido.',
        ];
    }
}
