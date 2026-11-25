<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CambiarRolRequest extends FormRequest
{
    public function rules(): array
    {
        return ['rol_id' => ['required', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['rol_id.required' => 'Elige el rol con el que quieres trabajar.'];
    }
}
