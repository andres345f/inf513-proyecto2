<?php

namespace App\Http\Requests\Productor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solicitud de afiliación: se elige una comunidad existente o se propone una nueva
 * (que queda inactiva hasta que el personal apruebe la solicitud).
 */
class SolicitudProductorRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $nueva = $this->input('comunidad_nueva');
        if (is_array($nueva)) {
            $this->merge(['comunidad_nueva' => array_map(fn ($v) => is_string($v) ? trim($v) : $v, $nueva)]);
        }
    }

    public function rules(): array
    {
        $texto = "regex:/^[\pL0-9][\pL0-9\s'.,-]*$/u";

        return [
            'comunidad_id' => ['nullable', 'required_without:comunidad_nueva', 'prohibits:comunidad_nueva', 'integer', 'exists:comunidad_agricola,id'],
            'comunidad_nueva' => ['nullable', 'array'],
            'comunidad_nueva.nombre' => ['required_with:comunidad_nueva', 'string', 'min:3', 'max:120', $texto],
            'comunidad_nueva.municipio' => ['required_with:comunidad_nueva', 'string', 'min:3', 'max:80', $texto],
            'comunidad_nueva.departamento' => ['nullable', 'string', 'max:40', $texto],
            'ubicacion_actividad' => ['required', 'string', 'min:10', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'comunidad_id.required_without' => 'Elige la comunidad donde realizas tu actividad productiva o propón una nueva.',
            'comunidad_id.prohibits' => 'Elige una comunidad de la lista o propón una nueva, no ambas.',
            'comunidad_id.exists' => 'La comunidad elegida no existe.',
            'comunidad_nueva.nombre.required_with' => 'Escribe el nombre de tu comunidad.',
            'comunidad_nueva.municipio.required_with' => 'Escribe el municipio de tu comunidad.',
            'comunidad_nueva.nombre.regex' => 'El nombre de la comunidad solo puede tener letras, números y espacios.',
            'comunidad_nueva.municipio.regex' => 'El municipio solo puede tener letras, números y espacios.',
            'ubicacion_actividad.required' => 'Describe dónde realizas tu actividad productiva.',
            'ubicacion_actividad.min' => 'Describe la ubicación con un poco más de detalle.',
        ];
    }

    public function attributes(): array
    {
        return [
            'comunidad_id' => 'comunidad',
            'comunidad_nueva.nombre' => 'nombre de la comunidad',
            'comunidad_nueva.municipio' => 'municipio',
            'comunidad_nueva.departamento' => 'departamento',
            'ubicacion_actividad' => 'ubicación de la actividad',
        ];
    }
}
