<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

const FOTOS_DIR = __DIR__ . '/fotos';
const FOTOS_JSON = FOTOS_DIR . '/fotos.json';
const MAX_BYTES = 10 * 1024 * 1024;
const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function leerFotos(): array {
    if (!is_file(FOTOS_JSON)) return [];
    $data = json_decode((string) file_get_contents(FOTOS_JSON), true);
    return is_array($data) ? $data : [];
}

function guardarFotos(array $fotos): void {
    file_put_contents(FOTOS_JSON, json_encode(array_values($fotos), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function titulo(string $s): string { return mb_substr(trim($s), 0, 80); }

$claveConfigurada = ADMIN_PASSWORD !== 'CAMBIAR-ESTA-CLAVE' && strlen(ADMIN_PASSWORD) >= 8;
$mensaje = '';
$error = '';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Solicitud inválida. Recargá la página e intentá de nuevo.');
    }
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'login' && $claveConfigurada) {
        if (hash_equals(ADMIN_PASSWORD, (string) ($_POST['clave'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
        } else {
            sleep(1);
            $error = 'Contraseña incorrecta.';
        }
    } elseif ($accion === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: admin.php');
        exit;
    } elseif (!empty($_SESSION['admin'])) {
        $fotos = leerFotos();

        if ($accion === 'subir') {
            if (!is_dir(FOTOS_DIR)) mkdir(FOTOS_DIR, 0755, true);
            $archivos = $_FILES['fotos'] ?? null;
            $subidas = 0;
            $nombreBase = titulo((string) ($_POST['titulo'] ?? ''));
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $total = is_array($archivos['name'] ?? null) ? count($archivos['name']) : 0;
            for ($i = 0; $i < $total; $i++) {
                if ($archivos['error'][$i] !== UPLOAD_ERR_OK) continue;
                $tmp = $archivos['tmp_name'][$i];
                $mime = $finfo->file($tmp);
                if (!isset(TIPOS[$mime]) || filesize($tmp) > MAX_BYTES || !getimagesize($tmp)) {
                    $error = 'Algunas fotos no se subieron: solo se aceptan JPG, PNG o WEBP de hasta 10 MB.';
                    continue;
                }
                $archivo = bin2hex(random_bytes(8)) . '.' . TIPOS[$mime];
                if (move_uploaded_file($tmp, FOTOS_DIR . '/' . $archivo)) {
                    array_unshift($fotos, ['src' => 'fotos/' . $archivo, 'titulo' => $nombreBase]);
                    $subidas++;
                }
            }
            if ($subidas) {
                guardarFotos($fotos);
                $mensaje = $subidas === 1 ? 'Foto subida.' : "$subidas fotos subidas.";
            } elseif (!$error) {
                $error = 'Elegí al menos una foto para subir.';
            }
        } elseif ($accion === 'titulo' || $accion === 'borrar' || $accion === 'subir_orden' || $accion === 'bajar_orden') {
            $i = (int) ($_POST['i'] ?? -1);
            if (isset($fotos[$i])) {
                if ($accion === 'titulo') {
                    $fotos[$i]['titulo'] = titulo((string) ($_POST['titulo'] ?? ''));
                    $mensaje = 'Título guardado.';
                } elseif ($accion === 'borrar') {
                    $ruta = FOTOS_DIR . '/' . basename($fotos[$i]['src']);
                    if (is_file($ruta)) unlink($ruta);
                    array_splice($fotos, $i, 1);
                    $mensaje = 'Foto borrada.';
                } else {
                    $j = $accion === 'subir_orden' ? $i - 1 : $i + 1;
                    if (isset($fotos[$j])) [$fotos[$i], $fotos[$j]] = [$fotos[$j], $fotos[$i]];
                }
                guardarFotos($fotos);
            }
        }
    }
}

$logueado = !empty($_SESSION['admin']);
$fotos = $logueado ? leerFotos() : [];
$csrf = e($_SESSION['csrf']);
?>
<!doctype html>
<html lang="es-AR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex" />
  <title>Administrar fotos | RevestimientoPro</title>
  <link rel="icon" href="favicon.svg" />
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="styles.css" />
</head>
<body class="admin">
  <header class="nav">
    <div class="container nav-inner">
      <a href="index.html" class="logo">Revestimiento<span>Pro</span></a>
      <?php if ($logueado): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>" /><button class="btn btn-ghost btn-sm" name="accion" value="logout">Salir</button></form>
      <?php endif; ?>
    </div>
  </header>

  <main class="section">
    <div class="container admin-wrap">
      <?php if ($mensaje): ?><p class="alert ok"><?= e($mensaje) ?></p><?php endif; ?>
      <?php if ($error): ?><p class="alert err"><?= e($error) ?></p><?php endif; ?>

      <?php if (!$claveConfigurada): ?>
        <h1 class="section-title">Falta configurar la contraseña</h1>
        <p class="section-sub">Abrí el archivo <strong>config.php</strong> en el Administrador de archivos de Hostinger y cambiá <code>CAMBIAR-ESTA-CLAVE</code> por una contraseña tuya de al menos 8 caracteres.</p>

      <?php elseif (!$logueado): ?>
        <h1 class="section-title">Administrar fotos</h1>
        <p class="section-sub">Ingresá tu contraseña para subir o borrar fotos de tus trabajos.</p>
        <form method="post" class="calc admin-login">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <div class="field">
            <label for="clave">Contraseña</label>
            <input id="clave" name="clave" type="password" autocomplete="current-password" required autofocus />
          </div>
          <button class="btn btn-block" name="accion" value="login">Entrar</button>
        </form>

      <?php else: ?>
        <h1 class="section-title">Fotos de mis trabajos</h1>
        <p class="section-sub">Lo que subas acá aparece en la sección "Mis trabajos" de la página. <a href="index.html#trabajos" class="link">Ver la página</a></p>

        <form method="post" enctype="multipart/form-data" class="calc upload-form">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <div class="field">
            <label for="fotos">Fotos (podés elegir varias)</label>
            <input id="fotos" name="fotos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required />
          </div>
          <div class="field">
            <label for="titulo">Título (opcional)</label>
            <input id="titulo" name="titulo" type="text" maxlength="80" placeholder="Ej: Frente de vivienda en Ancaflex" />
          </div>
          <button class="btn btn-block" name="accion" value="subir">Subir fotos</button>
        </form>

        <?php if (!$fotos): ?>
          <p class="empty">Todavía no subiste fotos. Mientras tanto, la página muestra texturas de ejemplo.</p>
        <?php else: ?>
          <ul class="admin-list">
            <?php foreach ($fotos as $i => $f): ?>
              <li class="admin-item">
                <img src="<?= e($f['src']) ?>" alt="" loading="lazy" />
                <form method="post" class="admin-item-form">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>" />
                  <input type="hidden" name="i" value="<?= $i ?>" />
                  <div class="field">
                    <label for="t<?= $i ?>">Título</label>
                    <input id="t<?= $i ?>" name="titulo" type="text" maxlength="80" value="<?= e($f['titulo'] ?? '') ?>" />
                  </div>
                  <div class="admin-actions">
                    <button class="btn btn-sm" name="accion" value="titulo">Guardar</button>
                    <button class="btn btn-ghost btn-sm" name="accion" value="subir_orden" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Mover antes">↑</button>
                    <button class="btn btn-ghost btn-sm" name="accion" value="bajar_orden" <?= $i === count($fotos) - 1 ? 'disabled' : '' ?> aria-label="Mover después">↓</button>
                    <button class="btn btn-danger btn-sm" name="accion" value="borrar" onclick="return confirm('¿Borrar esta foto?')">Borrar</button>
                  </div>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
