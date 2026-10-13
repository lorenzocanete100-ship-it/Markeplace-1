<?php
/*
 * Arma una sola página con la app real y el servidor de mentira (mock-api.js),
 * para probar Asiento Vacío sin hosting, por ejemplo como artifact de Claude.
 *
 *   php demo/build.php > asiento-vacio-prueba.html
 *
 * La página sale sin <html>/<head>/<body>: el visor de artifacts los agrega.
 */
declare(strict_types=1);

$public = dirname(__DIR__) . '/public';
$index = file_get_contents("$public/index.html");
if (!preg_match('#<body>(.*)</body>#s', $index, $match)) {
    fwrite(STDERR, "No encontré el <body> de index.html\n");
    exit(1);
}
$favicon = 'data:image/svg+xml;base64,' . base64_encode(trim(file_get_contents("$public/favicon.svg")));
$body = str_replace('src="favicon.svg"', 'src="' . $favicon . '"', $match[1]);
// En la prueba la dirección de la página no es la del sitio: se muestra un ejemplo.
$body = str_replace('<span class="site-origins">la dirección de tu sitio, con y sin <code>www.</code></span>',
    'la dirección de tu sitio, con y sin www (por ejemplo <code>https://asientovacio.com.ar</code> y <code>https://www.asientovacio.com.ar</code>).', $body);

$banner = <<<'HTML'
<div class="demo-bar" role="note">
  <div class="wrap demo-in">
    <p><strong>Modo prueba.</strong> Los viajes y las opiniones de ejemplo, y todo lo que cargues, quedan solo en este navegador: nada se publica de verdad.
      Podés crear una cuenta con cualquier correo, o usar el botón de Google simulado para entrar como Marta, Lucía o Diego y probar las calificaciones. Código de administración de prueba: <code>PRUEBA-2026</code></p>
    <button type="button" class="btn btn-sm btn-ghost" id="demo-reset">Empezar de cero</button>
  </div>
</div>
HTML;

$demoCss = <<<'CSS'
.demo-bar { background: var(--warn-bg); color: var(--warn-ink); border-bottom: 1px solid var(--line); font-size: .9rem; }
.demo-in { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; padding-block: 10px; }
.demo-in p { margin: 0; flex: 1 1 280px; min-width: 0; }
.demo-in code { font-weight: 800; letter-spacing: .04em; user-select: all; }
.demo-in .btn-ghost { color: var(--warn-ink); border-color: currentColor; }
.google-prueba { display: grid; gap: 6px; justify-items: center; }
.google-prueba select { width: auto; max-width: 100%; }
#google-button { display: grid; gap: 10px; justify-items: center; }
CSS;

echo "<title>Asiento Vacío de prueba</title>\n";
echo '<meta name="description" content="Versión de prueba de Asiento Vacío: los datos quedan en este navegador.">' . "\n";
echo "<style>\n" . file_get_contents("$public/assets/styles.css") . "\n$demoCss\n</style>\n";
echo $banner . "\n" . trim($body) . "\n";
echo "<script>\n" . file_get_contents(__DIR__ . '/mock-api.js') . "</script>\n";
echo "<script>\n" . file_get_contents("$public/assets/app.js") . "</script>\n";
