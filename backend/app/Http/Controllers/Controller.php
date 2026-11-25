<?php

namespace App\Http\Controllers;

use App\Support\SesionActiva;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Sesión web del pedido actual, o null si es un visitante.
     * Consultar el guard "api" resuelve el token Bearer y deja la sesión en los atributos del request.
     */
    protected function sesionActual(Request $request): ?SesionActiva
    {
        auth('api')->user();

        return $request->attributes->get('sesion');
    }
}
