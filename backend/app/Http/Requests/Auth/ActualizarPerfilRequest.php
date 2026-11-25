<?php

namespace App\Http\Requests\Auth;

use App\Support\TextoPersona;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos personales editables desde "Mi perfil". El CI no se edita.
 * La contraseña actual solo se exige si cambia el correo (lo valida el servicio).
 */
class ActualizarPerfilRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombres' => TextoPersona::limpiar($this->input('nombres', '')),
            'apellidos' => TextoPersona::limpiar($this->input('apellidos', '')),
            'correo' => TextoPersona::limpiarCorreo($this->input('correo', '')),
        ]);
    }

    public function rules(): array
    {
        $soloLetras = TextoPersona::REGLA;

        return [
            'nombres' => ['required', 'string', 'max:80', $soloLetras],
            'apellidos' => ['required', 'string', 'max:80', $soloLetras],
            'correo' => ['required', 'string', 'email:rfc', 'max:120'],
            'telefono' => ['required', 'integer', 'between:20000000,79999999'],
            'password_actual' => ['nullable', 'string', 'max:72'],
        ];
    }

    public function attributes(): array
    {
        return [
            'nombres' => 'nombres',
            'apellidos' => 'apellidos',
            'correo' => 'correo electrónico',
            'telefono' => 'teléfono',
            'password_actual' => 'contraseña actual',
        ];
    }

    public function messages(): array
    {
        return [
            'nombres.regex' => 'Los nombres solo pueden contener letras y espacios.',
            'apellidos.regex' => 'Los apellidos solo pueden contener letras y espacios.',
            'correo.email' => 'Ingresa un correo electrónico válido, por ejemplo nombre@correo.com.',
            'telefono.integer' => 'El teléfono solo debe contener números.',
            'telefono.between' => 'Ingresa un teléfono boliviano de 8 dígitos (por ejemplo 70012345).',
        ];
    }
}
