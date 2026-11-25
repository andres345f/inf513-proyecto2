<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BitacoraRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'usuario' => ['nullable', 'string', 'max:80'],
            'accion' => ['nullable', 'in:CREAR,MODIFICAR,ANULAR,ASIGNAR_ROL,REVOCAR_ROL,INICIO_SESION,CAMBIO_PRECIO,APROBAR_SOLICITUD,RECHAZAR_SOLICITUD,INVITAR'],
            'entidad' => ['nullable', 'string', 'max:60'],
            'resultado' => ['nullable', 'in:EXITO,FALLIDO'],
            'ip' => ['nullable', 'string', 'max:45', 'regex:/^[0-9a-fA-F.:]+$/'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'accion.in' => 'La acción elegida no es válida.',
            'ip.regex' => 'La IP solo puede contener números, puntos o dos puntos.',
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ];
    }
}
