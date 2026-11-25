<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

class ProductoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'foto' => ['nullable', 'file', 'max:5120', 'prohibited_if:retirar_foto,1'],
            'retirar_foto' => ['sometimes', 'boolean'],
            'imagen_url' => ['prohibited'],
            'categoria_id' => ['required', 'integer', 'exists:categoria,id'],
            'codigo' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/'],
            'nombre' => ['required', 'string', 'min:3', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'presentacion' => ['required', 'string', 'min:3', 'max:60'],
            'unidad_medida' => ['required', 'in:KILOGRAMO,LIBRA,ARROBA,LITRO,UNIDAD'],
            'precio_venta' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,2'],
            'stock_minimo' => ['required', 'numeric', 'min:0', 'max:999999', 'decimal:0,3'],
            'peso_kg' => ['nullable', 'numeric', 'gt:0', 'max:1000'],
            'permite_encargo' => ['nullable', 'boolean'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'foto.max' => 'La foto admite un máximo de 5 MB.',
            'foto.uploaded' => 'No se pudo cargar la foto. Elige un archivo de hasta 5 MB.',
            'foto.prohibited_if' => 'Elige entre reemplazar la foto y retirarla.',
            'imagen_url.prohibited' => 'Selecciona un archivo de imagen desde el formulario.',
            'categoria_id.required' => 'Elige la categoría.',
            'categoria_id.exists' => 'La categoría elegida no existe.',
            'codigo.regex' => 'El código solo puede tener letras, números y guiones.',
            'unidad_medida.in' => 'La unidad de medida no es válida.',
            'precio_venta.gt' => 'El precio debe ser mayor que cero.',
            'precio_venta.decimal' => 'El precio admite hasta 2 decimales.',
            'peso_kg.gt' => 'El peso debe ser mayor que cero.',
        ];
    }

    public function attributes(): array
    {
        return [
            'codigo' => 'código', 'descripcion' => 'descripción', 'presentacion' => 'presentación',
            'unidad_medida' => 'unidad de medida', 'precio_venta' => 'precio', 'stock_minimo' => 'stock mínimo', 'peso_kg' => 'peso por unidad',
        ];
    }
}
