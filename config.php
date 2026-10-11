<?php
// Configuración de Asiento Vacío. Este archivo no se puede abrir desde el navegador.
defined('ASIENTO') || exit;

return [
    // Correo de la cuenta que puede borrar cualquier viaje (moderación).
    // Primero creá tu cuenta en la página y después reemplazá '' por tu correo,
    // por ejemplo: getenv('ASIENTO_ADMIN_EMAIL') ?: 'tucorreo@gmail.com',
    'admin_email' => getenv('ASIENTO_ADMIN_EMAIL') ?: '',

    // Carpeta donde se guardan la base de datos y las sesiones.
    // Por defecto queda fuera de public_html, así nadie la puede descargar.
    'data_dir' => getenv('ASIENTO_DATA_DIR') ?: dirname(__DIR__) . '/asiento-vacio-datos',

    'timezone' => 'America/Argentina/Buenos_Aires',
];
