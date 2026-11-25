<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Entrega el index.html compilado de React para cualquier ruta que no sea de la API,
 * así las rutas del navegador (/catalogo, /perfil, ...) funcionan al recargar la página.
 */
class SpaController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Una ruta /api desconocida es un 404 de la API (JSON), no una página de React.
        if ($request->is('api', 'api/*')) {
            throw new NotFoundHttpException();
        }

        // Un archivo que no existe (imagen, script, hoja de estilos) es un 404 real: así el navegador
        // puede mostrar el reemplazo en lugar de recibir la página de React como si fuera una imagen.
        if (pathinfo($request->path(), PATHINFO_EXTENSION) !== '') {
            return response('Archivo no encontrado.', 404)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $index = public_path(config('feder.spa_index'));

        if (! is_file($index)) {
            return response('La interfaz web todavía no fue compilada.', 503)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response()->file($index, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
