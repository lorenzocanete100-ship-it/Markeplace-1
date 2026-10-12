<?php
// Configuración de Asiento Vacío. Este archivo no se puede abrir desde el navegador.
defined('ASIENTO') || exit;

return [
    // Huella (SHA-256) del código que activa la primera cuenta de administración.
    // El código en sí no se guarda en ningún lado: solo lo tiene el dueño del sitio.
    // Una vez que hay un administrador, el código deja de servir y los demás
    // administradores se nombran desde la sección Administrar.
    'admin_code_sha256' => getenv('ASIENTO_ADMIN_CODE_SHA256')
        ?: '74a0de2dc79907d43041242c7df0d45410a8da8c3a612502e293b151a8ac1e11',

    // Carpeta donde se guardan la base de datos y las sesiones.
    // Queda al lado de public_html (no adentro), así nadie la puede descargar,
    // aunque la página esté en una subcarpeta.
    'data_dir' => getenv('ASIENTO_DATA_DIR')
        ?: (preg_match('#^(.*)/public_html(/|$)#', __DIR__, $m) ? $m[1] : dirname(__DIR__)) . '/asiento-vacio-datos',

    // Claves públicas de Google para comprobar el ingreso con Google. El ID de cliente
    // se carga desde Administrar → Ajustes, no hace falta tocar este archivo.
    'google_certs_url' => getenv('ASIENTO_GOOGLE_CERTS_URL') ?: 'https://www.googleapis.com/oauth2/v3/certs',
    // De dónde se acepta traer la foto de una cuenta de Google ("Usar mi foto de Google").
    'google_picture_pattern' => getenv('ASIENTO_GOOGLE_PICTURE_PATTERN') ?: '#^https://lh[0-9]\.googleusercontent\.com/#',

    'timezone' => 'America/Argentina/Buenos_Aires',
];
