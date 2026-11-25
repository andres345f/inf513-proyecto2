<?php

namespace App\Http\Requests\Comercio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos de una tarjeta simulada. Reglas compartidas por "agregar método de pago" y "pagar con tarjeta nueva".
 */
class TarjetaRequest extends FormRequest
{
    /** Reglas con un prefijo opcional (por ejemplo "tarjeta.") para reutilizarlas dentro de otro formulario. */
    public static function reglas(string $prefijo = '', string $requerido = 'required'): array
    {
        $anio = (int) now()->format('Y');

        return [
            $prefijo.'numero' => [$requerido, 'string', 'regex:/^[\d ]{13,23}$/'],
            $prefijo.'titular' => [$requerido, 'string', 'min:3', 'max:80', "regex:/^[\pL][\pL\s'.-]*$/u"],
            $prefijo.'expira_mes' => [$requerido, 'integer', 'between:1,12'],
            $prefijo.'expira_anio' => [$requerido, 'integer', 'between:'.$anio.','.($anio + 15)],
            $prefijo.'cvv' => [$requerido, 'string', 'regex:/^\d{3,4}$/'],
            $prefijo.'alias' => ['nullable', 'string', 'max:40'],
        ];
    }

    public static function mensajes(string $prefijo = ''): array
    {
        return [
            $prefijo.'numero.required' => 'Ingresa el número de la tarjeta.',
            $prefijo.'numero.required_without' => 'Ingresa el número de la tarjeta o elige una guardada.',
            $prefijo.'numero.regex' => 'El número de tarjeta debe tener entre 13 y 19 dígitos.',
            $prefijo.'titular.required' => 'Ingresa el nombre del titular como figura en la tarjeta.',
            $prefijo.'titular.regex' => 'El titular solo puede contener letras y espacios.',
            $prefijo.'expira_mes.between' => 'El mes de vencimiento debe estar entre 1 y 12.',
            $prefijo.'expira_anio.between' => 'El año de vencimiento no es válido.',
            $prefijo.'cvv.regex' => 'El código de seguridad (CVV) son 3 o 4 dígitos.',
            $prefijo.'cvv.required' => 'Ingresa el código de seguridad (CVV).',
        ];
    }

    public function rules(): array
    {
        return self::reglas();
    }

    public function messages(): array
    {
        return self::mensajes();
    }

    public function attributes(): array
    {
        return ['expira_mes' => 'mes de vencimiento', 'expira_anio' => 'año de vencimiento', 'titular' => 'titular'];
    }
}
