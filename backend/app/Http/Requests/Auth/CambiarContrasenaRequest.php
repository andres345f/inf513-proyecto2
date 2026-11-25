<?php

namespace App\Http\Requests\Auth;

use App\Support\CredencialesDemostracion;
use App\Support\PoliticaContrasena;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambio de la propia contraseña (PUT /api/auth/contrasena).
 *
 * Política de la contraseña nueva: la de PoliticaContrasena (al menos 8 caracteres, con letras y números, hasta 72 bytes),
 * confirmada, distinta de la actual y que no sea la de demostración.
 * Que la contraseña actual sea la correcta lo comprueba el servicio, con la fila de la cuenta bloqueada.
 */
class CambiarContrasenaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'contrasena_actual' => ['bail', 'required', 'string', 'max:200'],
            'contrasena_nueva' => [
                'bail', 'required', 'string', 'confirmed',
                ...PoliticaContrasena::reglas(),
                'different:contrasena_actual',
                // La más costosa (compara con bcrypt) va al final: «bail» evita ejecutarla si ya falló otra regla.
                function (string $atributo, mixed $valor, Closure $fallar) {
                    if (CredencialesDemostracion::esContrasenaDeDemostracion((string) $valor)) {
                        $fallar('No puedes usar la contraseña de demostración: elige una contraseña propia.');
                    }
                },
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'contrasena_actual' => 'contraseña actual',
            'contrasena_nueva' => 'contraseña nueva',
            'contrasena_nueva_confirmation' => 'confirmación de la contraseña nueva',
        ];
    }

    public function messages(): array
    {
        return [
            'contrasena_actual.required' => 'Escribe tu contraseña actual.',
            'contrasena_nueva.required' => 'Escribe la contraseña nueva.',
            'contrasena_nueva.confirmed' => 'Las contraseñas nuevas no coinciden.',
            'contrasena_nueva.min' => 'La contraseña nueva debe tener al menos 8 caracteres.',
            'contrasena_nueva.regex' => 'La contraseña nueva debe combinar letras y números.',
            'contrasena_nueva.different' => 'La contraseña nueva debe ser distinta de la actual.',
        ];
    }
}
