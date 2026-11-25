<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Auth\RegistroRequest;

/**
 * Alta de cuenta por el administrador: mismos datos que el registro público, más el rol inicial
 * y sin aceptación de términos (la hace el titular al ingresar).
 */
class CrearUsuarioRequest extends RegistroRequest
{
    public function rules(): array
    {
        $reglas = parent::rules();
        unset($reglas['acepta_terminos']);

        return $reglas + ['rol' => ['required', 'in:ADMINISTRADOR,PERSONAL_FEDER,PRODUCTOR,CLIENTE']];
    }

    public function messages(): array
    {
        return parent::messages() + ['rol.required' => 'Elige el rol inicial de la cuenta.', 'rol.in' => 'El rol elegido no existe.'];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['rol' => 'rol'];
    }
}
