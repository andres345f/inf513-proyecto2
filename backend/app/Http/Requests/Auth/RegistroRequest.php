<?php

namespace App\Http\Requests\Auth;

use App\Support\CredencialesDemostracion;
use App\Support\TextoPersona;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro público de clientes. Las reglas reflejan las restricciones de la tabla usuario.
 */
class RegistroRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Solo se normaliza lo que llega como texto; una lista u otro tipo se deja igual para que la validación lo rechace.
        $complemento = $this->input('ci_complemento');
        if (is_string($complemento) || $complemento === null) {
            $complemento = strtoupper(trim((string) $complemento));
            $complemento = $complemento === '' ? null : $complemento;
        }

        $this->merge([
            'nombres' => TextoPersona::limpiar($this->input('nombres', '')),
            'apellidos' => TextoPersona::limpiar($this->input('apellidos', '')),
            'correo' => TextoPersona::limpiarCorreo($this->input('correo', '')),
            'ci_complemento' => $complemento,
        ]);
    }

    public function rules(): array
    {
        $soloLetras = TextoPersona::REGLA;

        return [
            'nombres' => ['required', 'string', 'max:80', $soloLetras],
            'apellidos' => ['required', 'string', 'max:80', $soloLetras],
            'correo' => ['required', 'string', 'email:rfc', 'max:120'],
            'ci' => ['required', 'integer', 'between:10000,999999999'],
            'ci_complemento' => ['nullable', 'string', 'regex:/^[0-9A-Z]{2}$/'],
            'telefono' => ['required', 'integer', 'between:20000000,79999999'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed',
                // H-02: la contraseña de demostración publicada no se admite como contraseña propia (en producción
                // tampoco serviría para entrar). Solo se compara si lo demás está bien: la comparación usa bcrypt.
                function (string $atributo, mixed $valor, Closure $fallar) {
                    if (is_string($valor) && strlen($valor) >= 8 && strlen($valor) <= 72
                        && CredencialesDemostracion::esContrasenaDeDemostracion($valor)) {
                        $fallar('No puedes usar la contraseña de demostración: elige una contraseña propia.');
                    }
                },
            ],
            'acepta_terminos' => ['accepted'],
        ];
    }

    public function attributes(): array
    {
        return [
            'nombres' => 'nombres',
            'apellidos' => 'apellidos',
            'correo' => 'correo electrónico',
            'ci' => 'carnet de identidad',
            'ci_complemento' => 'complemento',
            'telefono' => 'teléfono',
            'password' => 'contraseña',
            'acepta_terminos' => 'términos y condiciones',
        ];
    }

    public function messages(): array
    {
        return [
            'nombres.regex' => 'Los nombres solo pueden contener letras y espacios.',
            'apellidos.regex' => 'Los apellidos solo pueden contener letras y espacios.',
            'correo.email' => 'Ingresa un correo electrónico válido, por ejemplo nombre@correo.com.',
            'ci.integer' => 'El carnet de identidad solo debe contener números.',
            'ci.between' => 'El carnet de identidad debe tener entre 5 y 9 dígitos.',
            'ci_complemento.regex' => 'El complemento son 2 caracteres: números o letras mayúsculas (por ejemplo 1A).',
            'telefono.integer' => 'El teléfono solo debe contener números.',
            'telefono.between' => 'Ingresa un teléfono boliviano de 8 dígitos (por ejemplo 70012345).',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.regex' => 'La contraseña debe combinar letras y números.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'acepta_terminos.accepted' => 'Debes aceptar los términos y condiciones para registrarte.',
        ];
    }
}
