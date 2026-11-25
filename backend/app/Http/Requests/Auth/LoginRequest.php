<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'correo' => ['required', 'string', 'email', 'max:120'],
            'password' => ['required', 'string', 'max:100'],
            'rol_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['correo' => 'correo electrónico', 'password' => 'contraseña', 'rol_id' => 'rol'];
    }

    public function messages(): array
    {
        return [
            'correo.required' => 'Ingresa tu correo electrónico.',
            'correo.email' => 'Ingresa un correo electrónico válido, por ejemplo nombre@correo.com.',
            'password.required' => 'Ingresa tu contraseña.',
        ];
    }
}
