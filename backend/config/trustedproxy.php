<?php

/*
 * Proxy inverso de confianza (middleware TrustProxies de Laravel, activo por defecto).
 *
 * Si el servidor termina HTTPS en un proxy delante de Apache, indique en TRUSTED_PROXIES la IP del proxy
 * (o varias separadas por comas). Solo entonces Laravel acepta X-Forwarded-For y X-Forwarded-Proto de esa IP:
 * la bitácora registra la IP real del visitante, los límites de peticiones son por visitante y la petición
 * se reconoce como HTTPS (cabecera HSTS y URL generadas con https).
 *
 * Vacío (valor por defecto): no se confía en ningún proxy y esas cabeceras se ignoran. No use '*' salvo que
 * Apache solo sea accesible a través del proxy.
 */
return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
