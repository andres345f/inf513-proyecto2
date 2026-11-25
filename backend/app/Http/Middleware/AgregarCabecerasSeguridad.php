<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Cabeceras defensivas comunes para la API y el index de la aplicación React. */
class AgregarCabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        // geolocation=(self): «Usar mi ubicación» del checkout la pide en el propio sitio; nunca en marcos ajenos.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
        // Las tipografías de los temas (frontend/index.html) vienen de Google Fonts: hoja de estilos
        // desde fonts.googleapis.com y archivos de fuente desde fonts.gstatic.com. Ningún otro origen externo.
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "img-src 'self' data: blob: https://*.tile.openstreetmap.org",
            "font-src 'self' data: https://fonts.gstatic.com",
            "connect-src 'self'",
        ]));

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
