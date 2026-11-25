<?php

/*
| Configuración propia de FEDER Web.
| Los parámetros de negocio que cambian con frecuencia (permisos, menú, tarifas) viven en la base de datos
| (tabla confirmacion_operacion); aquí solo queda lo que depende del entorno.
*/

return [

    // Duración de una sesión web (token) en horas.
    'sesion_horas' => (int) env('FEDER_SESION_HORAS', 8),

    // Correo técnico con el que se guardan las filas de configuración en confirmacion_operacion.
    'correo_sistema' => env('FEDER_CORREO_SISTEMA', 'sistema@feder.example'),

    // Ruta, dentro de public/, del index.html compilado de React.
    'spa_index' => env('FEDER_SPA_INDEX', 'app/index.html'),

    // Prioridad para elegir el rol activo al iniciar sesión cuando el usuario tiene varios.
    'prioridad_roles' => ['ADMINISTRADOR', 'PERSONAL_FEDER', 'PRODUCTOR', 'CLIENTE'],

    // Pseudo-rol con el que se resuelven permisos y menú de quien no inició sesión.
    'rol_visitante' => 'VISITANTE',

    // Permite confirmar un QR desde la interfaz académica. Desactivar fuera de una demostración controlada.
    'qr_simulado' => (bool) env('FEDER_QR_SIMULADO', false),

    'ors' => [
        'url' => env('ORS_URL', 'https://api.openrouteservice.org'),
        'api_key' => env('ORS_API_KEY'),
    ],

];
