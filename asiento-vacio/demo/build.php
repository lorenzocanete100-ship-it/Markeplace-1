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

$banner = <<<'HTML'
<div class="demo-bar" role="note">
  <div class="wrap demo-in">
    <p><strong>Modo prueba.</strong> Los viajes y las opiniones de ejemplo, y todo lo que cargues, quedan solo en este navegador: nada se publica de verdad.
      Creá una cuenta con cualquier correo o celular, o entrá como Marta (celular <code>11 0000 1111</code>, contraseña <code>marta-prueba</code>) para probar las calificaciones. Para probar <a href="#admin">Administración</a> (en el sitio real no hay link: se entra con la dirección terminada en <code>#admin</code>), el código de prueba es <code>PRUEBA-2026</code>.</p>
    <button type="button" class="btn btn-sm btn-ghost" id="demo-reset">Empezar de cero</button>
  </div>
</div>
HTML;

$demoCss = <<<'CSS'
.demo-bar { background: var(--warn-bg); color: var(--warn-ink); border-bottom: 1px solid var(--line); font-size: .9rem; }
.demo-in { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; padding-block: 10px; }
.demo-in p { margin: 0; flex: 1 1 280px; min-width: 0; }
.demo-in code { font-weight: 800; letter-spacing: .04em; user-select: all; }
.demo-in a { color: inherit; font-weight: 700; }
.demo-in .btn-ghost { color: var(--warn-ink); border-color: currentColor; }
CSS;

echo "<title>Asiento Vacío de prueba</title>\n";
echo '<meta name="description" content="Versión de prueba de Asiento Vacío: los datos quedan en este navegador.">' . "\n";
echo "<style>\n" . file_get_contents("$public/assets/styles.css") . "\n$demoCss\n</style>\n";
echo $banner . "\n" . trim($body) . "\n";
echo "<script>\n" . file_get_contents(__DIR__ . '/mock-api.js') . "</script>\n";
echo "<script>\n" . file_get_contents("$public/assets/app.js") . "</script>\n";
