<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// Cualquier ruta que no sea de la API ni un archivo de public/ la resuelve React.
Route::fallback(SpaController::class);
