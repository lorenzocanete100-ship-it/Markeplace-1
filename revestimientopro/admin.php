<?php
declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

const FOTOS_DIR = __DIR__ . '/fotos';
const FOTOS_JSON = FOTOS_DIR . '/fotos.json';
const MAX_BYTES = 10 * 1024 * 1024;
const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
// La contraseña se guarda cifrada en este archivo. Si te la olvidás, borralo
// desde el Administrador de archivos y volvé a entrar a admin.php para crear otra.
const CLAVE_FILE = __DIR__ . '/admin-clave.php';
const CLAVE_MIN = 6;
const CLAVE_CABECERA = "<?php http_response_code(404); exit; ?>\n";

function leerHash(): ?string {
    if (!is_file(CLAVE_FILE)) return null;
    $hash = trim(substr((string) file_get_contents(CLAVE_FILE), strlen(CLAVE_CABECERA)));
    return $hash !== '' ? $hash : null;
}

// $crear = true solo deja escribir si el archivo todavía no existe (primera vez).
function guardarHash(string $clave, bool $crear): bool {
    $contenido = CLAVE_CABECERA . password_hash($clave, PASSWORD_DEFAULT) . "\n";
    if ($crear) {
        $f = @fopen(CLAVE_FILE, 'x');
        if (!$f) return false;
        $ok = fwrite($f, $contenido) === strlen($contenido);
        fclose($f);
        return $ok;
    }
    $tmp = CLAVE_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $contenido, LOCK_EX) !== strlen($contenido)) { @unlink($tmp); return false; }
    return rename($tmp, CLAVE_FILE);
}

// La sesión guarda una huella de la contraseña: si se cambia o se resetea, hay que volver a entrar.
function huella(string $hash): string { return hash('sha256', $hash); }

function validarNueva(string $clave, string $repetida): string {
    if (mb_strlen($clave) < CLAVE_MIN) return 'La contraseña tiene que tener al menos ' . CLAVE_MIN . ' caracteres.';
    if ($clave !== $repetida) return 'Las dos contraseñas no coinciden. Escribilas de nuevo.';
    return '';
}

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

$hash = leerHash();
$mensaje = '';
$error = '';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Solicitud inválida. Recargá la página e intentá de nuevo.');
    }
    $accion = $_POST['accion'] ?? '';

    $logueado = $hash !== null && hash_equals(huella($hash), (string) ($_SESSION['admin'] ?? ''));

    if ($accion === 'crear_clave' && $hash === null) {
        $clave = (string) ($_POST['clave'] ?? '');
        $error = validarNueva($clave, (string) ($_POST['clave2'] ?? ''));
        if (!$error) {
            if (guardarHash($clave, true) && ($hash = leerHash()) !== null) {
                session_regenerate_id(true);
                $_SESSION['admin'] = huella($hash);
                $mensaje = '¡Listo! Tu contraseña quedó guardada. Ya podés subir tus fotos.';
            } else {
                $hash = leerHash();
                $error = $hash === null
                    ? 'No se pudo guardar la contraseña. Revisá que la carpeta del sitio tenga permiso de escritura.'
                    : 'Ya hay una contraseña creada. Ingresala para entrar.';
            }
        }
    } elseif ($accion === 'login' && $hash !== null) {
        if (password_verify((string) ($_POST['clave'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = huella($hash);
        } else {
            sleep(1);
            $error = 'Contraseña incorrecta.';
        }
    } elseif ($accion === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: admin.php');
        exit;
    } elseif ($accion === 'cambiar_clave' && $logueado) {
        $nueva = (string) ($_POST['clave_nueva'] ?? '');
        if (!password_verify((string) ($_POST['clave_actual'] ?? ''), $hash)) {
            sleep(1);
            $error = 'La contraseña actual no es correcta.';
        } elseif (!($error = validarNueva($nueva, (string) ($_POST['clave_nueva2'] ?? '')))) {
            if (guardarHash($nueva, false) && ($hash = leerHash()) !== null) {
                $_SESSION['admin'] = huella($hash);
                $mensaje = 'Contraseña cambiada.';
            } else {
                $error = 'No se pudo cambiar la contraseña. Probá de nuevo.';
            }
        }
    } elseif ($logueado) {
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

$logueado = $hash !== null && hash_equals(huella($hash), (string) ($_SESSION['admin'] ?? ''));
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

      <?php if ($hash === null): ?>
        <h1 class="section-title">Creá tu contraseña</h1>
        <p class="section-sub">Es la primera vez que entrás. Elegí una contraseña para que solo vos puedas subir y borrar fotos. La vas a usar cada vez que entres acá.</p>
        <form method="post" class="calc admin-login">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <div class="field">
            <label for="clave">Nueva contraseña (mínimo <?= CLAVE_MIN ?> caracteres)</label>
            <input id="clave" name="clave" type="password" minlength="<?= CLAVE_MIN ?>" autocomplete="new-password" required autofocus />
          </div>
          <div class="field">
            <label for="clave2">Repetí la contraseña</label>
            <input id="clave2" name="clave2" type="password" minlength="<?= CLAVE_MIN ?>" autocomplete="new-password" required />
          </div>
          <label class="ver-clave"><input type="checkbox" data-ver-clave /> Mostrar contraseña</label>
          <button class="btn btn-block" name="accion" value="crear_clave">Guardar contraseña y entrar</button>
        </form>

      <?php elseif (!$logueado): ?>
        <h1 class="section-title">Administrar fotos</h1>
        <p class="section-sub">Ingresá tu contraseña para subir o borrar fotos de tus trabajos.</p>
        <form method="post" class="calc admin-login">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <div class="field">
            <label for="clave">Contraseña</label>
            <input id="clave" name="clave" type="password" autocomplete="current-password" required autofocus />
          </div>
          <label class="ver-clave"><input type="checkbox" data-ver-clave /> Mostrar contraseña</label>
          <button class="btn btn-block" name="accion" value="login">Entrar</button>
        </form>
        <details class="ayuda">
          <summary>¿Te olvidaste la contraseña?</summary>
          <p>Entrá al Administrador de archivos de Hostinger, abrí <strong>public_html</strong> y borrá el archivo <strong>admin-clave.php</strong>. Después volvé a esta página y vas a poder crear una contraseña nueva. Tus fotos no se borran.</p>
        </details>

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

        <details class="ayuda">
          <summary>Cambiar contraseña</summary>
          <form method="post" class="calc admin-login">
            <input type="hidden" name="csrf" value="<?= $csrf ?>" />
            <div class="field">
              <label for="clave_actual">Contraseña actual</label>
              <input id="clave_actual" name="clave_actual" type="password" autocomplete="current-password" required />
            </div>
            <div class="field">
              <label for="clave_nueva">Nueva contraseña (mínimo <?= CLAVE_MIN ?> caracteres)</label>
              <input id="clave_nueva" name="clave_nueva" type="password" minlength="<?= CLAVE_MIN ?>" autocomplete="new-password" required />
            </div>
            <div class="field">
              <label for="clave_nueva2">Repetí la nueva contraseña</label>
              <input id="clave_nueva2" name="clave_nueva2" type="password" minlength="<?= CLAVE_MIN ?>" autocomplete="new-password" required />
            </div>
            <label class="ver-clave"><input type="checkbox" data-ver-clave /> Mostrar contraseñas</label>
            <button class="btn btn-block" name="accion" value="cambiar_clave">Cambiar contraseña</button>
          </form>
        </details>
      <?php endif; ?>
    </div>
  </main>
  <script>
    document.querySelectorAll("[data-ver-clave]").forEach((box) =>
      box.addEventListener("change", () =>
        box.closest("form").querySelectorAll(".field input").forEach((i) => (i.type = box.checked ? "text" : "password"))
      )
    );
  </script>
</body>
</html>
