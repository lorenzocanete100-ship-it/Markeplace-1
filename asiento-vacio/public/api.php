<?php
/*
 * Asiento Vacío: tablero de viajes compartidos entre particulares.
 *
 * No hay precios, reservas ni pagos: el conductor publica los asientos libres
 * de un viaje que ya iba a hacer, su teléfono se muestra en la página y los
 * acompañantes solo comparten los gastos de combustible y alguna comida.
 */
declare(strict_types=1);

define('ASIENTO', true);
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Avisos con montos harían que el sitio parezca venta de pasajes.
const PRICE_WORDS = '/\$|\b(precios?|tarifas?|pesos|usd|d[oó]lar(es)?)\b/iu';
const MAX_DAYS_AHEAD = 180;
const SESSION_DAYS = 7;
const MAX_COMMENT = 300;
const MAX_PHOTO_BYTES = 8 * 1024 * 1024;
const MAX_PHOTO_PIXELS = 4096 * 4096;  // abierta, cada foto ocupa en memoria unos 4 bytes por píxel
const PHOTO_SIZE = 320;
const REPORT_REASONS = ['precio', 'datos', 'ofensivo', 'otro'];
const SUSPEND_DAYS = [1, 7, 30, 0];  // 0: sin fecha, hasta que la administración la levante
const SUSPEND_REASONS = [
    'precio' => 'Cobró un precio por el viaje o lo usó como negocio.',
    'datos' => 'Publicó datos falsos.',
    'conducta' => 'Tuvo una conducta ofensiva o peligrosa.',
    'denuncias' => 'Recibió varias denuncias.',
    'otro' => 'No cumplió las reglas del sitio.',
];
// En cada calificación se dice qué hacía en ese viaje la persona calificada.
const RATING_ROLES = ['driver', 'rider'];

function respond(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $message, int $code = 400): never
{
    respond(['error' => $message], $code);
}

function clean(mixed $value): string
{
    return is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
}

function multiline(mixed $value): string
{
    return is_string($value) && mb_check_encoding($value, 'UTF-8') ? trim(preg_replace('/[ \t]+/u', ' ', $value)) : '';
}

function length_between(string $value, int $min, int $max): bool
{
    $n = mb_strlen($value);
    return $n >= $min && $n <= $max;
}

function data_dir(array $config): string
{
    $dir = rtrim($config['data_dir'], '/');
    if (!is_dir($dir . '/sesiones') && !@mkdir($dir . '/sesiones', 0700, true)) {
        fail('El servidor no puede guardar datos. Revisá la carpeta configurada en config.php.', 503);
    }
    // Por si la carpeta termina dentro de public_html: que no se pueda descargar.
    if (!is_file($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

function connect(string $dir): PDO
{
    $pdo = new PDO('sqlite:' . $dir . '/viajes.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=10000');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS users (
          id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE,
          name TEXT NOT NULL, password TEXT NOT NULL, created INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS trips (
          id TEXT PRIMARY KEY, owner TEXT NOT NULL REFERENCES users(id),
          origin TEXT NOT NULL, destination TEXT NOT NULL,
          day TEXT NOT NULL, hour TEXT NOT NULL, seats INTEGER NOT NULL,
          driver TEXT NOT NULL, phone TEXT NOT NULL, phone_digits TEXT NOT NULL,
          vehicle TEXT NOT NULL, notes TEXT NOT NULL,
          status TEXT NOT NULL DEFAULT \'active\', created INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS idx_trips_owner ON trips(owner);
        CREATE INDEX IF NOT EXISTS idx_trips_status_day ON trips(status, day, hour);
        CREATE TABLE IF NOT EXISTS reports (
          id TEXT PRIMARY KEY, trip TEXT NOT NULL REFERENCES trips(id),
          reason TEXT NOT NULL, details TEXT NOT NULL,
          status TEXT NOT NULL DEFAULT \'open\', created INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS idx_reports_status ON reports(status, created);
        CREATE TABLE IF NOT EXISTS ratings (
          id TEXT PRIMARY KEY, rater TEXT NOT NULL REFERENCES users(id), rated TEXT NOT NULL REFERENCES users(id),
          stars INTEGER NOT NULL, comment TEXT NOT NULL DEFAULT \'\',
          created INTEGER NOT NULL, updated INTEGER NOT NULL, UNIQUE(rater, rated));
        CREATE INDEX IF NOT EXISTS idx_ratings_rated ON ratings(rated, updated);
        CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS limits (key TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires INTEGER NOT NULL);
    ');
    // Bases creadas con versiones anteriores no tienen estas columnas.
    $added = [
        'users' => [
            'is_admin' => 'INTEGER NOT NULL DEFAULT 0', 'blocked' => 'INTEGER NOT NULL DEFAULT 0',
            'blocked_until' => 'INTEGER NOT NULL DEFAULT 0', 'blocked_reason' => "TEXT NOT NULL DEFAULT ''",
            'photo' => "TEXT NOT NULL DEFAULT ''", 'auth_epoch' => 'INTEGER NOT NULL DEFAULT 0',
        ],
        'ratings' => ['role' => "TEXT NOT NULL DEFAULT ''"],
    ];
    $columnsOf = fn(string $table): array => array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    // Lo que hay que completar en las filas que ya existían cuando se agrega la columna. Las calificaciones
    // de antes no decían cómo viajaron: se toman como de conductor si la persona calificada publicó viajes.
    $backfill = [
        'ratings.role' => "UPDATE ratings SET role = CASE WHEN rated IN (SELECT owner FROM trips) THEN 'driver' ELSE 'rider' END WHERE role = ''",
    ];
    $pending = function () use ($added, $backfill, $columnsOf): array {
        $changes = [];
        foreach ($added as $table => $columns) {
            foreach (array_diff(array_keys($columns), $columnsOf($table)) as $column) {
                $changes[] = "ALTER TABLE $table ADD COLUMN $column {$columns[$column]}";
                if (isset($backfill["$table.$column"])) {
                    $changes[] = $backfill["$table.$column"];
                }
            }
        }
        return $changes;
    };
    if ($pending()) {
        // Justo después de actualizar pueden llegar dos pedidos a la vez: uno solo hace los cambios.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach ($pending() as $change) {
                $pdo->exec($change);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $error) {
            $pdo->exec('ROLLBACK');
            throw $error;
        }
    }
    $columns = $columnsOf('users');

    // La verificación con DNI se quitó: si quedaron fotos o solicitudes de una versión anterior, se borran.
    if (is_dir("$dir/verificaciones")) {
        foreach (glob("$dir/verificaciones/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir("$dir/verificaciones");
    }
    if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='verifications'")->fetch()) {
        $pdo->exec('DROP TABLE IF EXISTS verifications');
        if (in_array('verified', $columns, true)) {
            $pdo->exec('UPDATE users SET verified=0, verified_at=0');
        }
    }

    // Las suspensiones con plazo se levantan solas cuando se cumple la fecha.
    $now = time();
    if ($pdo->query("SELECT 1 FROM users WHERE blocked=1 AND blocked_until>0 AND blocked_until<=$now LIMIT 1")->fetch()) {
        $pdo->exec("UPDATE users SET blocked=0, blocked_until=0, blocked_reason='' WHERE blocked=1 AND blocked_until>0 AND blocked_until<=$now");
    }
    return $pdo;
}

function start_session(string $dir): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)(SESSION_DAYS * 86400));
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '200');
    session_save_path($dir . '/sesiones');
    session_name('asiento');
    session_set_cookie_params([
        'lifetime' => SESSION_DAYS * 86400, 'path' => '/',
        'secure' => $https, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

// Cuenta un intento y dice si todavía está dentro del límite.
function limit(PDO $db, string $key, int $maximum, int $window): bool
{
    $now = time();
    $db->beginTransaction();
    $db->prepare('DELETE FROM limits WHERE expires < ?')->execute([$now]);
    $db->prepare('INSERT INTO limits(key,hits,expires) VALUES (?,1,?) ON CONFLICT(key) DO UPDATE SET hits=hits+1')->execute([$key, $now + $window]);
    $stmt = $db->prepare('SELECT hits FROM limits WHERE key=?');
    $stmt->execute([$key]);
    $hits = (int)$stmt->fetchColumn();
    $db->commit();
    return $hits <= $maximum;
}

// Dice si ya se llegó al límite, sin contar un intento.
function limited(PDO $db, string $key, int $maximum): bool
{
    return (int)query($db, 'SELECT hits FROM limits WHERE key=? AND expires>=?', [$key, time()])->fetchColumn() >= $maximum;
}

function setting(PDO $db, string $key): string
{
    return (string)query($db, 'SELECT value FROM settings WHERE key=?', [$key])->fetchColumn();
}

function today(): string
{
    return date('Y-m-d');
}

function query(PDO $db, string $sql, array $params = []): PDOStatement
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// Promedio y cantidad de calificaciones de una cuenta: todas, o solo como conductor ('driver') o
// como acompañante ('rider'). No cuentan las de cuentas suspendidas.
function rating_sql(string $who, string $as, string $role = ''): string
{
    $only = in_array($role, RATING_ROLES, true) ? " AND r.role = '$role'" : '';
    $from = "FROM ratings r JOIN users w ON w.id = r.rater WHERE r.rated = $who AND w.blocked = 0$only";
    return "(SELECT ROUND(AVG(r.stars), 1) $from) AS {$as}_rating, (SELECT COUNT(*) $from) AS {$as}_ratings";
}

function rating_pair(array $row, string $as): array
{
    return ['rating' => $row["{$as}_rating"] === null ? null : (float)$row["{$as}_rating"], 'count' => (int)$row["{$as}_ratings"]];
}

// Las versiones anteriores tenían ingreso con Google. Esas cuentas (google_sub != '') guardaban una
// contraseña al azar que nadie conoce: hasta que elijan una, entran con una sesión que ya tenían
// abierta (eligen la contraseña sin escribir la actual) o con una nueva que les da la administración.
function no_password_sql(PDO $db): string
{
    static $sql = null;
    return $sql ??= in_array('google_sub', array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name'), true)
        ? "(google_sub != '')" : '0';
}

// Cambia la contraseña y cierra todas las sesiones abiertas de la cuenta.
function set_password(PDO $db, string $id, string $password): void
{
    query($db, 'UPDATE users SET password=?, auth_epoch=auth_epoch+1' . (no_password_sql($db) === '0' ? '' : ", google_sub=''")
        . ' WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $id]);
}

function load_user(PDO $db, string $id): ?array
{
    return query($db, 'SELECT id,email,name,is_admin,blocked,photo,created,auth_epoch,' . no_password_sql($db) . ' AS no_password
        FROM users WHERE id=?', [$id])->fetch() ?: null;
}

function public_user(?array $user): ?array
{
    return $user ? [
        'id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'created' => (int)$user['created'],
        'admin' => (int)$user['is_admin'] === 1, 'photo' => photo_url($user['photo']),
        'noPassword' => (int)$user['no_password'] === 1,
    ] : null;
}

// Las fotos se guardan fuera de public_html y se sirven con api.php?a=photo.
function photo_url(?string $file): ?string
{
    return $file ? "api.php?a=photo&f=$file" : null;
}

// Recorta la foto en un cuadrado y la guarda como JPEG nuevo: al recodificarla se pierden los
// datos ocultos de la cámara (como la ubicación GPS). Devuelve null si no es una foto válida.
function save_photo(string $bytes, string $folder): ?string
{
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > MAX_PHOTO_PIXELS) {
        return null;
    }
    $image = @imagecreatefromstring($bytes);
    if (!$image) {
        return null;
    }
    $width = imagesx($image);
    $height = imagesy($image);
    $side = min($width, $height);
    $square = imagecreatetruecolor(PHOTO_SIZE, PHOTO_SIZE);
    imagefill($square, 0, 0, imagecolorallocate($square, 255, 255, 255));  // fondo blanco para PNG transparentes
    imagecopyresampled($square, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), PHOTO_SIZE, PHOTO_SIZE, $side, $side);
    $image = null;
    // Se endereza la foto ya recortada y achicada (el cuadrado del centro es el mismo): girar la
    // original ocuparía el doble de memoria.
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);
        $turn = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
        if ($turn) {
            $square = imagerotate($square, $turn, 0);
        }
    }
    if (!is_dir($folder) && !@mkdir($folder, 0700, true)) {
        return null;
    }
    $name = bin2hex(random_bytes(16)) . '.jpg';
    return imagejpeg($square, "$folder/$name", 85) ? $name : null;
}

function replace_photo(PDO $db, string $folder, array $user, string $file): void
{
    query($db, 'UPDATE users SET photo=? WHERE id=?', [$file, $user['id']]);
    if ($user['photo'] !== '' && preg_match('/^[a-f0-9]{32}\.jpg$/', $user['photo'])) {
        @unlink("$folder/{$user['photo']}");
    }
}

// Las cuentas se identifican con un correo o con un número de celular. Los celulares argentinos se
// guardan como +54 9, la característica y el número (sin 0 ni 15), así se pueden escribir de distintas
// formas: "11 2345 6789", "011 15 2345 6789", "0351 15 555 1234" o "+54 9 11 2345 6789". Un celular de
// otro país se escribe con + y su código. Devuelve null si no es un correo ni un celular válido.
function login_id(mixed $value): ?string
{
    $value = strtolower(clean($value));
    if (str_contains($value, '@')) {
        return filter_var($value, FILTER_VALIDATE_EMAIL) && strlen($value) <= 254 ? $value : null;
    }
    if (!preg_match('/^\+?[0-9 ()\-.]{8,25}$/', $value)) {
        return null;
    }
    $digits = preg_replace('/\D/', '', $value);
    $international = str_starts_with($value, '+') || str_starts_with($digits, '00');
    $digits = $international ? ltrim($digits, '0') : $digits;
    if (str_starts_with($digits, '54') && ($international || strlen($digits) >= 12)) {
        $digits = substr($digits, 2);
    } elseif ($international) {
        return strlen($digits) >= 10 && strlen($digits) <= 15 ? '+' . $digits : null;  // otro país
    }
    // Queda el número argentino: puede tener el 9 de los celulares (después del 54), el 0 de larga
    // distancia y el 15 entre la característica (de 2 a 4 cifras) y el número.
    if (str_starts_with($digits, '9')) {
        $digits = substr($digits, 1);
    }
    if (str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 12) {
        // Las características son 11 (Buenos Aires) o de 3 o 4 cifras que empiezan con 2 o 3.
        foreach (str_starts_with($digits, '11') ? [2] : [3, 4] as $at) {
            if (substr($digits, $at, 2) === '15') {
                $digits = substr($digits, 0, $at) . substr($digits, $at + 2);
                break;
            }
        }
    }
    return preg_match('/^(11|[23])\d+$/', $digits) && strlen($digits) === 10 ? '+549' . $digits : null;
}

function new_password(): string
{
    // Fácil de dictar o copiar: sin letras que se confunden (0/O, 1/l/I).
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    $groups = [];
    for ($g = 0; $g < 3; $g++) {
        $groups[] = implode('', array_map(fn() => $chars[random_int(0, strlen($chars) - 1)], range(1, 4)));
    }
    return implode('-', $groups);
}

function admin_code_ok(array $config, mixed $code): bool
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', is_string($code) ? $code : ''));
    return $code !== '' && hash_equals(strtolower($config['admin_code_sha256']), hash('sha256', $code));
}

function suspended(array $account): never
{
    $until = (int)$account['blocked_until'];
    fail('Tu cuenta está suspendida' . ($until ? date(' \h\a\s\t\a \e\l d/m/Y \a \l\a\s H:i', $until) : '') . '.'
        . ($account['blocked_reason'] !== '' ? ' Motivo: ' . $account['blocked_reason'] : ''), 403);
}

function sign_in(PDO $db, string $uid, bool $new = false): never
{
    $user = load_user($db, $uid);
    session_regenerate_id(true);
    $_SESSION = ['uid' => $uid, 'epoch' => (int)$user['auth_epoch'], 'csrf' => bin2hex(random_bytes(32))];
    respond(['csrf' => $_SESSION['csrf'], 'user' => public_user($user), 'new' => $new]);
}

try {
    $dir = data_dir($config);
    $db = connect($dir);
    $photos = "$dir/fotos";

    // Las fotos de perfil son públicas: se sirven sin abrir sesión y se guardan en el navegador,
    // porque cada foto nueva tiene otro nombre.
    if (($_GET['a'] ?? '') === 'photo' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $file = (string)($_GET['f'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}\.jpg$/', $file) || !is_file("$photos/$file")
            || !query($db, 'SELECT 1 FROM users WHERE photo=? AND blocked=0', [$file])->fetch()) {
            fail('Foto no encontrada.', 404);
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize("$photos/$file"));
        readfile("$photos/$file");
        exit;
    }

    start_session($dir);

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['a'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    $packed = @inet_pton($ip);
    if ($packed !== false && strlen($packed) === 16) {
        // Con IPv6 cada conexión puede cambiar de dirección dentro de su bloque /64: los límites cuentan el bloque.
        $ip = bin2hex(substr($packed, 0, 8)) . '/64';
    }

    $input = [];
    if ($method === 'POST') {
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            respond(['error' => 'La sesión venció. Recargá la página e intentá nuevamente.', 'csrf' => true], 403);
        }
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if (strlen($raw) > 65536) {
            fail('Solicitud demasiado grande.', 413);
        }
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $input = [];
        }
    } elseif ($method !== 'GET') {
        fail('Método no permitido.', 405);
    }

    $user = empty($_SESSION['uid']) ? null : load_user($db, $_SESSION['uid']);
    if ($user && ((int)$user['blocked'] === 1 || (int)$user['auth_epoch'] !== (int)($_SESSION['epoch'] ?? 0))) {
        // Una cuenta suspendida, o cuya contraseña cambió después de abrir esta sesión, pierde la sesión en el siguiente pedido.
        unset($_SESSION['uid']);
        $user = null;
    }
    $isAdmin = $user && (int)$user['is_admin'] === 1;
    $serialize = function (array $row) use ($user, $isAdmin): array {
        $mine = $user && $row['owner'] === $user['id'];
        $row['driver_id'] = $row['owner'];
        $row['driver_rating'] = $row['driver_rating'] === null ? null : (float)$row['driver_rating'];
        $row['driver_ratings'] = (int)$row['driver_ratings'];
        $row['driver_photo'] = photo_url($row['driver_photo']);
        unset($row['owner']);
        return [...$row, 'mine' => $mine, 'canDelete' => $mine || $isAdmin];
    };
    // El nombre del conductor es siempre el de la cuenta (reemplaza a t.driver, que en viajes viejos se escribía a mano).
    $tripsSql = 'SELECT t.*, u.name AS driver, u.photo AS driver_photo, ' . rating_sql('t.owner', 'driver', 'driver')
        . ' FROM trips t JOIN users u ON u.id=t.owner';
    $requireUser = function () use ($user): array {
        return $user ?? fail('Iniciá sesión.', 401);
    };
    $ownTrip = function () use ($db, $input, $user, $isAdmin, $action): array {
        if (!$user) {
            fail('Iniciá sesión.', 401);
        }
        $row = query($db, "SELECT * FROM trips WHERE id=? AND status!='deleted'", [(string)($input['id'] ?? '')])->fetch();
        if (!$row) {
            fail('Viaje no encontrado.', 404);
        }
        if ($row['owner'] !== $user['id'] && !($action === 'trip_delete' && $isAdmin)) {
            fail('No podés modificar este viaje.', 403);
        }
        return $row;
    };
    if (str_starts_with($action, 'admin_') && $action !== 'admin_claim' && !$isAdmin) {
        fail($user ? 'Esta sección es solo para administración.' : 'Iniciá sesión.', $user ? 403 : 401);
    }

    switch ("$method $action") {
        case 'GET health':
            $db->query('SELECT 1')->fetch();
            respond(['ok' => true]);

        case 'GET session':
            // contact: el WhatsApp de la administración, para quien se olvida la contraseña.
            respond(['csrf' => $_SESSION['csrf'], 'today' => today(), 'user' => public_user($user), 'contact' => setting($db, 'contact')]);

        case 'POST logout':
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            respond(['ok' => true, 'csrf' => $_SESSION['csrf']]);

        case 'POST login':
        case 'POST register':
            if (!limit($db, 'auth:' . $ip, 25, 900)) {
                fail('Demasiados intentos. Esperá 15 minutos.', 429);
            }
            // Se entra con correo o con número de celular (antes el campo se llamaba "email").
            $login = login_id($input['login'] ?? $input['email'] ?? '');
            $password = is_string($input['password'] ?? null) ? $input['password'] : '';
            if ($login === null) {
                fail('Escribí tu correo o tu celular con código de área, sin 0 ni 15 (por ejemplo 11 2345 6789).');
            }
            if (!length_between($password, 10, 128)) {
                fail('Usá una contraseña de 10 a 128 caracteres.');
            }
            $phone = !str_contains($login, '@');
            if ($action === 'register') {
                $name = clean($input['name'] ?? '');
                if (!length_between($name, 2, 80)) {
                    fail('Ingresá tu nombre (2 a 80 caracteres).');
                }
                $uid = bin2hex(random_bytes(16));
                try {
                    query($db, 'INSERT INTO users(id,email,name,password,created) VALUES (?,?,?,?,?)',
                        [$uid, $login, $name, password_hash($password, PASSWORD_DEFAULT), time()]);
                } catch (PDOException) {
                    fail('Ya existe una cuenta con ese ' . ($phone ? 'celular' : 'correo') . '. Probá entrar.', 409);
                }
                sign_in($db, $uid, true);
            }
            // Además del límite por conexión, uno por cuenta: el celular de quien publica se ve en sus viajes.
            $failures = 'auth-fail:' . $login;
            if (limited($db, $failures, 10)) {
                fail('Demasiados intentos con esta cuenta. Esperá 15 minutos.', 429);
            }
            $row = query($db, 'SELECT id,password,blocked,blocked_until,blocked_reason,' . no_password_sql($db) . ' AS no_password
                FROM users WHERE email=?', [$login])->fetch();
            if ($row && (int)$row['no_password'] === 1) {
                fail('Esta cuenta se creó con Google, que ya no se usa. Pedile a la administración una contraseña nueva.', 401);
            }
            if (!$row || !password_verify($password, $row['password'])) {
                limit($db, $failures, 10, 900);
                fail(($phone ? 'Celular' : 'Correo') . ' o contraseña incorrectos.', 401);
            }
            if ((int)$row['blocked'] === 1) {
                suspended($row);
            }
            sign_in($db, $row['id']);

        case 'GET trips':
            if (($_GET['mine'] ?? '') === '1') {
                if (!$user) {
                    fail('Iniciá sesión.', 401);
                }
                // Primero los próximos viajes, al final los que ya pasaron.
                $stmt = query($db, "$tripsSql WHERE t.owner=? AND t.status!='deleted' ORDER BY t.day < ?, t.day, t.hour LIMIT 300",
                    [$user['id'], today()]);
            } else {
                // Los viajes pasados desaparecen solos; los completos quedan ocultos hasta que el conductor los reabra.
                $stmt = query($db, "$tripsSql WHERE t.status='active' AND t.day>=? AND u.blocked=0 ORDER BY t.day, t.hour LIMIT 300",
                    [today()]);
            }
            respond(['items' => array_map($serialize, $stmt->fetchAll())]);

        case 'POST trip_create':
            if (!$user) {
                fail('Iniciá sesión para publicar un viaje.', 401);
            }
            $v = [];
            foreach (['origin', 'destination', 'day', 'hour', 'phone', 'vehicle'] as $k) {
                $v[$k] = clean($input[$k] ?? '');
            }
            $v['notes'] = multiline($input['notes'] ?? '');
            // Las calificaciones son de la cuenta, así que el viaje sale con el nombre de la cuenta.
            $v['driver'] = $user['name'];
            if (($input['confirmPrivate'] ?? null) !== true || ($input['confirmPhone'] ?? null) !== true) {
                fail('Confirmá que sos un particular que comparte gastos y que aceptás mostrar tu teléfono.');
            }
            if (!length_between($v['origin'], 2, 80) || !length_between($v['destination'], 2, 80)) {
                fail('Indicá desde dónde salís y hacia dónde vas.');
            }
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $v['day']);
            if (!$day || $day->format('Y-m-d') !== $v['day']) {
                fail('Indicá una fecha válida.');
            }
            $last = (new DateTimeImmutable(today()))->modify('+' . MAX_DAYS_AHEAD . ' days')->format('Y-m-d');
            if ($v['day'] < today() || $v['day'] > $last) {
                fail('La fecha tiene que ser de hoy en adelante (hasta 6 meses).');
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v['hour'])) {
                fail('Indicá una hora de salida válida.');
            }
            $seats = filter_var($input['seats'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 8]]);
            if ($seats === false) {
                fail('Podés ofrecer de 1 a 8 asientos.');
            }
            $digits = preg_replace('/\D/', '', $v['phone']);
            if (!preg_match('/^[0-9+()\-. ]{8,25}$/', $v['phone']) || strlen($digits) < 10 || strlen($digits) > 15) {
                fail('Ingresá un teléfono con código de país y de área, por ejemplo 54 9 11 2345 6789.');
            }
            if (mb_strlen($v['vehicle']) > 60 || mb_strlen($v['notes']) > 600) {
                fail('El vehículo o las notas son demasiado largos.');
            }
            foreach (['origin', 'destination', 'vehicle', 'notes'] as $k) {
                if (preg_match(PRICE_WORDS, $v[$k])) {
                    fail('No pongas precios ni montos. En Asiento Vacío solo se comparten los gastos de combustible y alguna comida.');
                }
            }
            if (!limit($db, 'publish:' . $user['id'], 10, 3600)) {
                fail('Llegaste al límite de viajes publicados por hora.', 429);
            }
            $id = bin2hex(random_bytes(16));
            query($db, 'INSERT INTO trips(id,owner,origin,destination,day,hour,seats,driver,phone,phone_digits,vehicle,notes,status,created)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $id, $user['id'], $v['origin'], $v['destination'], $v['day'], $v['hour'], $seats,
                $v['driver'], $v['phone'], $digits, $v['vehicle'], $v['notes'], 'active', time(),
            ]);
            respond(['id' => $id], 201);

        case 'POST trip_update':
            $row = $ownTrip();
            $status = $input['status'] ?? $row['status'];
            $seats = $input['seats'] ?? $row['seats'];
            if (!in_array($status, ['active', 'full'], true) || !is_int($seats) || $seats < 1 || $seats > 8) {
                fail('Datos no válidos.');
            }
            query($db, 'UPDATE trips SET status=?, seats=? WHERE id=?', [$status, $seats, $row['id']]);
            respond(['ok' => true]);

        case 'POST trip_delete':
            $row = $ownTrip();
            query($db, "UPDATE trips SET status='deleted' WHERE id=?", [$row['id']]);
            query($db, "UPDATE reports SET status='closed' WHERE trip=?", [$row['id']]);
            respond(['ok' => true]);

        case 'POST report':
            // Cualquiera puede avisar de un viaje sospechoso, sin cuenta.
            if (!limit($db, 'report:' . $ip, 10, 3600)) {
                fail('Mandaste muchas denuncias seguidas. Probá más tarde.', 429);
            }
            $reason = $input['reason'] ?? '';
            $details = multiline($input['details'] ?? '');
            if (!in_array($reason, REPORT_REASONS, true) || mb_strlen($details) > 500) {
                fail('Elegí un motivo para la denuncia.');
            }
            $trip = query($db, "SELECT id FROM trips WHERE id=? AND status!='deleted'", [(string)($input['trip'] ?? '')])->fetch();
            if (!$trip) {
                fail('Viaje no encontrado.', 404);
            }
            query($db, 'INSERT INTO reports(id,trip,reason,details,created) VALUES (?,?,?,?,?)',
                [bin2hex(random_bytes(16)), $trip['id'], $reason, $details, time()]);
            respond(['ok' => true], 201);

        case 'GET profile':
            $row = query($db, 'SELECT u.id, u.name, u.created, u.blocked, u.photo, ' . rating_sql('u.id', 'user') . ', '
                . rating_sql('u.id', 'driver', 'driver') . ', ' . rating_sql('u.id', 'rider', 'rider') . ' FROM users u WHERE u.id=?',
                [(string)($_GET['id'] ?? '')])->fetch();
            if (!$row || (int)$row['blocked'] === 1) {
                fail('Perfil no encontrado.', 404);
            }
            $reviews = array_map(fn($r) => [...$r, 'rater_photo' => photo_url($r['rater_photo'])],
                query($db, 'SELECT r.stars, r.comment, r.role, r.updated, w.id AS rater_id, w.name AS rater_name, w.photo AS rater_photo
                    FROM ratings r JOIN users w ON w.id=r.rater WHERE r.rated=? AND w.blocked=0 ORDER BY r.updated DESC LIMIT 50',
                    [$row['id']])->fetchAll());
            $mine = $user ? query($db, 'SELECT stars, comment, role FROM ratings WHERE rater=? AND rated=?', [$user['id'], $row['id']])->fetch() : null;
            $trips = query($db, "$tripsSql WHERE t.owner=? AND t.status='active' AND t.day>=? ORDER BY t.day, t.hour LIMIT 50",
                [$row['id'], today()])->fetchAll();
            respond([
                'profile' => ['id' => $row['id'], 'name' => $row['name'], 'created' => (int)$row['created'], 'photo' => photo_url($row['photo']),
                    'rating' => rating_pair($row, 'user')['rating'], 'ratings' => (int)$row['user_ratings'],
                    'asDriver' => rating_pair($row, 'driver'), 'asRider' => rating_pair($row, 'rider')],
                'reviews' => $reviews,
                'mine' => $mine ?: null,
                'trips' => array_map($serialize, $trips),
            ]);

        case 'POST rate':
            $me = $requireUser();
            $target = query($db, 'SELECT id, blocked FROM users WHERE id=?', [(string)($input['user'] ?? '')])->fetch();
            if (!$target || (int)$target['blocked'] === 1) {
                fail('Perfil no encontrado.', 404);
            }
            if ($target['id'] === $me['id']) {
                fail('No podés calificarte a vos mismo.');
            }
            $stars = $input['stars'] ?? null;
            if (!is_int($stars) || $stars < 1 || $stars > 5) {
                fail('Elegí de 1 a 5 estrellas.');
            }
            $role = $input['role'] ?? '';
            if (!in_array($role, RATING_ROLES, true)) {
                fail('Elegí si esta persona manejaba o si viajó como acompañante.');
            }
            $comment = clean($input['comment'] ?? '');
            if (mb_strlen($comment) > MAX_COMMENT) {
                fail('El comentario puede tener hasta ' . MAX_COMMENT . ' letras.');
            }
            // También por conexión, para que no se pueda llenar un perfil de estrellas con cuentas inventadas.
            if (!limit($db, 'rate:' . $me['id'], 30, 86400) || !limit($db, 'rate-ip:' . $ip, 40, 86400)) {
                fail('Hiciste muchas calificaciones hoy. Probá mañana.', 429);
            }
            // Una sola calificación por persona: si ya había una, se reemplaza.
            query($db, 'INSERT INTO ratings(id,rater,rated,stars,comment,role,created,updated) VALUES (?,?,?,?,?,?,?,?)
                ON CONFLICT(rater, rated) DO UPDATE SET stars=excluded.stars, comment=excluded.comment, role=excluded.role, updated=excluded.updated',
                [bin2hex(random_bytes(16)), $me['id'], $target['id'], $stars, $comment, $role, time(), time()]);
            respond(['ok' => true]);

        case 'POST profile_name':
            $me = $requireUser();
            $name = clean($input['name'] ?? '');
            if (!length_between($name, 2, 80)) {
                fail('Ingresá tu nombre (2 a 80 caracteres).');
            }
            if (!limit($db, 'name:' . $me['id'], 10, 86400)) {
                fail('Cambiaste el nombre muchas veces hoy. Probá mañana.', 429);
            }
            query($db, 'UPDATE users SET name=? WHERE id=?', [$name, $me['id']]);
            respond(['user' => public_user(load_user($db, $me['id']))]);

        case 'POST rate_delete':
            $me = $requireUser();
            query($db, 'DELETE FROM ratings WHERE rater=? AND rated=?', [$me['id'], (string)($input['user'] ?? '')]);
            respond(['ok' => true]);

        case 'POST photo_upload':
            $me = $requireUser();
            if (!limit($db, 'photo:' . $me['id'], 10, 86400) || !limit($db, 'photo-ip:' . $ip, 30, 86400)) {
                fail('Cambiaste la foto muchas veces hoy. Probá mañana.', 429);
            }
            $upload = $_FILES['photo'] ?? null;
            $ok = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
                && is_uploaded_file($upload['tmp_name']) && $upload['size'] <= MAX_PHOTO_BYTES;
            $file = $ok ? save_photo((string)file_get_contents($upload['tmp_name']), $photos) : null;
            if (!$file) {
                fail('Elegí una foto JPG, PNG o WebP de hasta 8 MB.');
            }
            replace_photo($db, $photos, $me, $file);
            respond(['user' => public_user(load_user($db, $me['id']))]);

        case 'POST photo_delete':
            $me = $requireUser();
            replace_photo($db, $photos, $me, '');
            respond(['user' => public_user(load_user($db, $me['id']))]);

        case 'POST password_change':
            $me = $requireUser();
            if (!limit($db, 'password:' . $me['id'], 10, 3600)) {
                fail('Demasiados intentos. Esperá una hora.', 429);
            }
            $current = query($db, 'SELECT password FROM users WHERE id=?', [$me['id']])->fetchColumn();
            // Las cuentas que eran de Google nunca tuvieron contraseña: eligen una sin escribir la actual.
            if ((int)$me['no_password'] !== 1
                && (!is_string($input['current'] ?? null) || !password_verify($input['current'], (string)$current))) {
                fail('La contraseña actual no es correcta.', 403);
            }
            $password = is_string($input['password'] ?? null) ? $input['password'] : '';
            if (!length_between($password, 10, 128)) {
                fail('Usá una contraseña nueva de 10 a 128 caracteres.');
            }
            // Se cierran las otras sesiones abiertas (por ejemplo, en un teléfono perdido o con la cookie
            // copiada): esta sigue, con otro identificador.
            set_password($db, $me['id'], $password);
            session_regenerate_id(true);
            $_SESSION['epoch'] = (int)$me['auth_epoch'] + 1;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            respond(['csrf' => $_SESSION['csrf'], 'user' => public_user(load_user($db, $me['id']))]);

        case 'POST admin_claim':
            if (!$user) {
                fail('Iniciá sesión.', 401);
            }
            if (!limit($db, 'claim:' . $ip, 10, 3600)) {
                fail('Demasiados intentos. Esperá una hora.', 429);
            }
            // El código sirve siempre: si el dueño pierde el acceso a su cuenta, crea otra y lo vuelve a poner.
            if (!admin_code_ok($config, $input['code'] ?? '')) {
                fail('El código no es correcto.', 403);
            }
            query($db, 'UPDATE users SET is_admin=1 WHERE id=?', [$user['id']]);
            respond(['user' => public_user(load_user($db, $user['id']))]);

        case 'GET admin_overview':
            $count = fn(string $sql, array $params = []) => (int)query($db, $sql, $params)->fetchColumn();
            respond([
                'upcoming' => $count("SELECT COUNT(*) FROM trips t JOIN users u ON u.id=t.owner WHERE t.status='active' AND t.day>=? AND u.blocked=0", [today()]),
                'week' => $count('SELECT COUNT(*) FROM trips WHERE created>=?', [time() - 7 * 86400]),
                'drivers' => $count('SELECT COUNT(*) FROM users'),
                'reports' => $count("SELECT COUNT(*) FROM reports WHERE status='open'"),
            ]);

        case 'GET admin_reports':
            respond(['items' => query($db, "SELECT r.id, r.reason, r.details, r.created,
                    t.id AS trip, t.origin, t.destination, t.day, t.hour, u.name AS driver, t.phone, t.status AS trip_status,
                    u.email AS owner_email
                FROM reports r JOIN trips t ON t.id=r.trip JOIN users u ON u.id=t.owner
                WHERE r.status='open' ORDER BY r.created DESC LIMIT 300")->fetchAll()]);

        case 'GET admin_ratings':
            respond(['items' => query($db, 'SELECT r.id, r.stars, r.comment, r.role, r.updated,
                    w.id AS rater_id, w.name AS rater_name, w.email AS rater_email,
                    w.created AS rater_created,
                    d.id AS rated_id, d.name AS rated_name, d.email AS rated_email
                FROM ratings r JOIN users w ON w.id=r.rater JOIN users d ON d.id=r.rated
                ORDER BY r.updated DESC LIMIT 500')->fetchAll()]);

        case 'POST admin_rating':
            if (($input['action'] ?? '') !== 'delete') {
                fail('Acción no válida.');
            }
            if (query($db, 'DELETE FROM ratings WHERE id=?', [(string)($input['id'] ?? '')])->rowCount() !== 1) {
                fail('Esa calificación ya no existe.', 404);
            }
            respond(['ok' => true]);

        case 'GET admin_trips':
            respond(['items' => query($db, "SELECT t.*, u.name AS driver, u.email AS owner_email, u.blocked AS owner_blocked,
                    (SELECT COUNT(*) FROM reports r WHERE r.trip=t.id AND r.status='open') AS reports
                FROM trips t JOIN users u ON u.id=t.owner ORDER BY t.created DESC LIMIT 500")->fetchAll()]);

        case 'GET admin_users':
            respond(['items' => array_map(fn($u) => [...$u, 'photo' => photo_url($u['photo'])], query($db, "SELECT u.id, u.email, u.name, u.created, u.is_admin, u.blocked,
                    u.blocked_until, u.blocked_reason,
                    (SELECT COUNT(*) FROM trips t WHERE t.owner=u.id AND t.status!='deleted') AS trips,
                    (SELECT COUNT(*) FROM trips t WHERE t.owner=u.id) AS trips_total, u.photo,
                    " . no_password_sql($db) . ' AS no_password, ' . rating_sql('u.id', 'user') . ', '
                    . rating_sql('u.id', 'driver', 'driver') . ', ' . rating_sql('u.id', 'rider', 'rider') . "
                FROM users u ORDER BY u.created DESC LIMIT 1000")->fetchAll())]);

        case 'POST admin_contact':
            $written = clean($input['phone'] ?? '');
            $phone = $written === '' ? '' : login_id($written);
            if ($phone === null || str_contains($phone, '@')) {
                fail('Escribí un celular con código de área, sin 0 ni 15 (por ejemplo 11 2345 6789).');
            }
            query($db, 'INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', ['contact', $phone]);
            respond(['contact' => $phone]);

        case 'GET admin_settings':
            // Lo pide al abrir Administrar la página de una versión anterior que quedó guardada en el navegador.
            respond(['googleClientId' => '']);

        case 'POST admin_trip':
            $trip = query($db, 'SELECT id,status FROM trips WHERE id=?', [(string)($input['id'] ?? '')])->fetch();
            if (!$trip) {
                fail('Viaje no encontrado.', 404);
            }
            if (($input['action'] ?? '') === 'delete') {
                query($db, "UPDATE trips SET status='deleted' WHERE id=?", [$trip['id']]);
                query($db, "UPDATE reports SET status='closed' WHERE trip=?", [$trip['id']]);
            } elseif (($input['action'] ?? '') === 'restore' && $trip['status'] === 'deleted') {
                query($db, "UPDATE trips SET status='active' WHERE id=?", [$trip['id']]);
            } else {
                fail('Acción no válida.');
            }
            respond(['ok' => true]);

        case 'POST admin_user':
            $target = load_user($db, (string)($input['id'] ?? ''));
            $do = $input['action'] ?? '';
            if (!$target) {
                fail('Cuenta no encontrada.', 404);
            }
            if ($target['id'] === $user['id'] && in_array($do, ['block', 'remove_admin', 'reset_password'], true)) {
                fail($do === 'reset_password' ? 'Tu contraseña la cambiás en Mi perfil.' : 'No podés hacerte eso a vos mismo.');
            }
            // A otro administrador no se lo suspende ni se le da una contraseña nueva (se podría entrar con su cuenta).
            if (in_array($do, ['block', 'reset_password'], true) && (int)$target['is_admin'] === 1) {
                fail('Primero quitale la administración.');
            }
            if ($do === 'block') {
                $days = $input['days'] ?? null;
                $reason = SUSPEND_REASONS[$input['reason'] ?? ''] ?? null;
                if (!in_array($days, SUSPEND_DAYS, true) || $reason === null) {
                    fail('Elegí cuánto dura la suspensión y el motivo.');
                }
                query($db, 'UPDATE users SET blocked=1, blocked_until=?, blocked_reason=? WHERE id=?',
                    [$days ? time() + $days * 86400 : 0, $reason, $target['id']]);
                respond(['ok' => true]);
            }
            if ($do === 'remove_photo') {
                replace_photo($db, $photos, $target, '');
                respond(['ok' => true]);
            }
            if ($do === 'reset_password') {
                // No hay recuperación por correo ni por SMS: si alguien se olvida la contraseña, la administración
                // le da una nueva (y se la manda a su celular o a su correo). Se cierran las sesiones que tenía abiertas.
                $password = new_password();
                set_password($db, $target['id'], $password);
                respond(['password' => $password]);
            }
            $changes = ['unblock' => "blocked=0, blocked_until=0, blocked_reason=''", 'make_admin' => 'is_admin=1',
                'remove_admin' => 'is_admin=0'];
            if (!isset($changes[$do])) {
                fail('Acción no válida.');
            }
            query($db, "UPDATE users SET {$changes[$do]} WHERE id=?", [$target['id']]);
            respond(['ok' => true]);

        case 'POST admin_report':
            if (($input['action'] ?? '') !== 'dismiss') {
                fail('Acción no válida.');
            }
            query($db, "UPDATE reports SET status='closed' WHERE id=?", [(string)($input['id'] ?? '')]);
            respond(['ok' => true]);

        default:
            fail('Acción no válida.', 404);
    }
} catch (Throwable $e) {
    error_log('Asiento Vacío: ' . $e);
    fail('No se pudo completar la operación. Intentá nuevamente.', 503);
}
