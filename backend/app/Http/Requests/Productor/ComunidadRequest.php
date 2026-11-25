<?php

namespace App\Http\Requests\Productor;

use Illuminate\Foundation\Http\FormRequest;

/** Alta o edición de una comunidad agrícola (POST /api/gestion/comunidades y PUT /api/gestion/comunidades/{comunidad}). */
class ComunidadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'min:3', 'max:120'],
            'municipio' => ['required', 'string', 'min:3', 'max:80'],
            'departamento' => ['nullable', 'string', 'max:40'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'activa' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'nombre' => 'nombre de la comunidad',
            'municipio' => 'municipio',
            'departamento' => 'departamento',
            'descripcion' => 'descripción',
            'activa' => 'estado de la comunidad',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre de la comunidad.',
            'municipio.required' => 'Escribe el municipio.',
            'nombre.min' => 'El nombre debe tener al menos 3 caracteres.',
            'municipio.min' => 'El municipio debe tener al menos 3 caracteres.',
        ];
    }
}
