<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Despliegue en subcarpeta (TecnoWeb): quita el prefijo del subpath para que
// Laravel enrute correctamente. En local (sin ese prefijo) no hace nada.
$__subpath = '/inf513/grupo29sa/proyecto2';
if (! empty($_SERVER['REQUEST_URI']) && str_starts_with($_SERVER['REQUEST_URI'], $__subpath)) {
    $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], strlen($__subpath)) ?: '/';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
}

// No revelar la versión exacta de PHP en las respuestas públicas.
header_remove('X-Powered-By');

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
