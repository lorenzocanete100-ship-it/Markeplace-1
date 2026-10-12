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
const GOOGLE_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];
const GOOGLE_CLIENT_ID = '/^[0-9]{5,30}-[a-z0-9]{8,64}\.apps\.googleusercontent\.com$/';

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
    $columns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    $added = [
        'is_admin' => 'INTEGER NOT NULL DEFAULT 0', 'blocked' => 'INTEGER NOT NULL DEFAULT 0',
        'blocked_until' => 'INTEGER NOT NULL DEFAULT 0', 'blocked_reason' => "TEXT NOT NULL DEFAULT ''",
        'google_sub' => "TEXT NOT NULL DEFAULT ''",
        'photo' => "TEXT NOT NULL DEFAULT ''", 'google_picture' => "TEXT NOT NULL DEFAULT ''",
        'auth_epoch' => 'INTEGER NOT NULL DEFAULT 0',
    ];
    if (array_diff(array_keys($added), $columns)) {
        // Justo después de actualizar pueden llegar dos pedidos a la vez: uno solo hace los cambios.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $columns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
            foreach ($added as $column => $type) {
                if (!in_array($column, $columns, true)) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN $column $type");
                }
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $error) {
            $pdo->exec('ROLLBACK');
            throw $error;
        }
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_google ON users(google_sub) WHERE google_sub != ''");

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

function setting(PDO $db, string $key): string
{
    return (string)(query($db, 'SELECT value FROM settings WHERE key=?', [$key])->fetchColumn() ?: '');
}

// Promedio y cantidad de calificaciones de una cuenta. No cuentan las de cuentas suspendidas.
function rating_sql(string $who, string $as): string
{
    $from = "FROM ratings r JOIN users w ON w.id = r.rater WHERE r.rated = $who AND w.blocked = 0";
    return "(SELECT ROUND(AVG(r.stars), 1) $from) AS {$as}_rating, (SELECT COUNT(*) $from) AS {$as}_ratings";
}

function load_user(PDO $db, string $id): ?array
{
    return query($db, 'SELECT id,email,name,is_admin,blocked,google_sub,google_picture,photo,created,auth_epoch FROM users WHERE id=?', [$id])->fetch() ?: null;
}

function public_user(?array $user): ?array
{
    return $user ? [
        'id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'created' => (int)$user['created'],
        'admin' => (int)$user['is_admin'] === 1, 'google' => $user['google_sub'] !== '',
        'photo' => photo_url($user['photo']), 'googlePicture' => $user['google_picture'] !== '',
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
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > 30_000_000) {
        return null;
    }
    $image = @imagecreatefromstring($bytes);
    if (!$image) {
        return null;
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);
        $turn = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
        if ($turn) {
            $image = imagerotate($image, $turn, 0);
        }
    }
    $width = imagesx($image);
    $height = imagesy($image);
    $side = min($width, $height);
    $square = imagecreatetruecolor(PHOTO_SIZE, PHOTO_SIZE);
    imagefill($square, 0, 0, imagecolorallocate($square, 255, 255, 255));  // fondo blanco para PNG transparentes
    imagecopyresampled($square, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), PHOTO_SIZE, PHOTO_SIZE, $side, $side);
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

function suspended(array $account): never
{
    $until = (int)$account['blocked_until'];
    fail('Tu cuenta está suspendida' . ($until ? date(' \h\a\s\t\a \e\l d/m/Y \a \l\a\s H:i', $until) : '') . '.'
        . ($account['blocked_reason'] !== '' ? ' Motivo: ' . $account['blocked_reason'] : ''), 403);
}

function sign_in(PDO $db, string $uid): never
{
    $user = load_user($db, $uid);
    session_regenerate_id(true);
    $_SESSION = ['uid' => $uid, 'epoch' => (int)$user['auth_epoch'], 'csrf' => bin2hex(random_bytes(32))];
    respond(['csrf' => $_SESSION['csrf'], 'user' => public_user($user)]);
}

function b64url(string $value): string|false
{
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function der(string $tag, string $value): string
{
    $length = strlen($value);
    $bytes = ltrim(pack('N', $length), "\0");
    return $tag . ($length < 128 ? chr($length) : chr(0x80 | strlen($bytes)) . $bytes) . $value;
}

// Arma la clave pública (PEM) a partir de la clave RSA que publica Google en formato JWK.
function jwk_pem(array $jwk): ?string
{
    $n = b64url((string)($jwk['n'] ?? ''));
    $e = b64url((string)($jwk['e'] ?? ''));
    if (!$n || !$e) {
        return null;
    }
    $integer = fn(string $x) => der("\x02", ord($x[0]) > 0x7f ? "\0" . $x : $x);
    $algorithm = der("\x30", der("\x06", "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
    $spki = der("\x30", $algorithm . der("\x03", "\0" . der("\x30", $integer($n) . $integer($e))));
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

// Claves públicas de Google, guardadas un rato para no pedirlas en cada ingreso.
function google_keys(string $dir, string $url, bool $refresh): array
{
    $file = "$dir/google-certs.json";
    $cache = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    $cache = is_array($cache) && is_array($cache['keys'] ?? null) ? $cache : null;
    $now = time();
    // Aunque pidan una clave desconocida, se vuelve a consultar a Google como mucho cada 5 minutos.
    if ($cache && $cache['expires'] > $now && (!$refresh || $cache['fetched'] > $now - 300)) {
        return $cache['keys'];
    }
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8]]));
    $jwks = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($jwks['keys'] ?? null)) {
        error_log("Asiento Vacío: no se pudieron bajar las claves de Google de $url");
        return $cache['keys'] ?? [];
    }
    $maxAge = 3600;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^cache-control:.*max-age=(\d+)/i', $line, $m)) {
            $maxAge = max(300, min(86400, (int)$m[1]));
        }
    }
    @file_put_contents($file, json_encode(['fetched' => $now, 'expires' => $now + $maxAge, 'keys' => $jwks['keys']]), LOCK_EX);
    return $jwks['keys'];
}

// Comprueba el comprobante que entrega el botón de Google: firma, destinatario, vencimiento y correo confirmado.
function google_identity(string $jwt, string $clientId, string $dir, string $certsUrl): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    [$head, $body, $signature] = $parts;
    $header = json_decode((string)b64url($head), true);
    $claims = json_decode((string)b64url($body), true);
    $signature = b64url($signature);
    if (!is_array($header) || !is_array($claims) || !is_string($signature)
        || ($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null)) {
        return null;
    }
    $pem = null;
    foreach ([false, true] as $refresh) {  // si Google cambió sus claves, se vuelven a pedir una vez
        foreach (google_keys($dir, $certsUrl, $refresh) as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $header['kid'] && ($key['kty'] ?? '') === 'RSA') {
                $pem = jwk_pem($key);
            }
        }
        if ($pem) {
            break;
        }
    }
    if (!$pem || openssl_verify("$head.$body", $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
        return null;
    }
    $now = time();
    $valid = in_array($claims['iss'] ?? null, GOOGLE_ISSUERS, true)
        && ($claims['aud'] ?? null) === $clientId
        && is_int($claims['exp'] ?? null) && $claims['exp'] > $now - 60
        && is_int($claims['iat'] ?? null) && $claims['iat'] < $now + 300
        && is_string($claims['sub'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,255}$/', $claims['sub'])
        && ($claims['email_verified'] ?? false) === true
        && is_string($claims['email'] ?? null) && filter_var($claims['email'], FILTER_VALIDATE_EMAIL);
    return $valid ? $claims : null;
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
        // Una cuenta suspendida, o unida a Google después de abrir esta sesión, pierde la sesión en el siguiente pedido.
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
    $tripsSql = 'SELECT t.*, u.name AS driver, u.photo AS driver_photo, ' . rating_sql('t.owner', 'driver') . ' FROM trips t JOIN users u ON u.id=t.owner';
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
            respond(['csrf' => $_SESSION['csrf'], 'today' => today(), 'user' => public_user($user),
                'googleClientId' => setting($db, 'google_client_id')]);

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
            $email = strtolower(clean($input['email'] ?? ''));
            $password = is_string($input['password'] ?? null) ? $input['password'] : '';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || !length_between($password, 10, 128)) {
                fail('Revisá el correo y usá una contraseña de 10 a 128 caracteres.');
            }
            if ($action === 'register') {
                $name = clean($input['name'] ?? '');
                if (!length_between($name, 2, 80)) {
                    fail('Ingresá tu nombre (2 a 80 caracteres).');
                }
                $uid = bin2hex(random_bytes(16));
                try {
                    query($db, 'INSERT INTO users(id,email,name,password,created) VALUES (?,?,?,?,?)',
                        [$uid, $email, $name, password_hash($password, PASSWORD_DEFAULT), time()]);
                } catch (PDOException) {
                    fail('Ya existe una cuenta con ese correo. Probá entrar.', 409);
                }
                sign_in($db, $uid);
            }
            $row = query($db, 'SELECT id,password,google_sub,blocked,blocked_until,blocked_reason FROM users WHERE email=?', [$email])->fetch();
            if (!$row || !password_verify($password, $row['password'])) {
                fail($row && $row['google_sub'] !== ''
                    ? 'Esta cuenta entra con Google: tocá "Continuar con Google".'
                    : 'Correo o contraseña incorrectos.', 401);
            }
            if ((int)$row['blocked'] === 1) {
                suspended($row);
            }
            sign_in($db, $row['id']);

        case 'POST google':
            $clientId = setting($db, 'google_client_id');
            if ($clientId === '') {
                fail('El ingreso con Google no está activado.', 404);
            }
            if (!limit($db, 'google:' . $ip, 30, 900)) {
                fail('Demasiados intentos. Esperá 15 minutos.', 429);
            }
            $token = $input['credential'] ?? '';
            $claims = is_string($token) && strlen($token) < 8192
                ? google_identity($token, $clientId, $dir, $config['google_certs_url'])
                : null;
            if (!$claims) {
                fail('No pudimos confirmar tu cuenta de Google. Probá de nuevo.', 401);
            }
            $email = strtolower($claims['email']);
            $domain = substr($email, strrpos($email, '@') + 1);
            // Google solo responde por quién es el dueño del correo en Gmail y en dominios de Google Workspace (hd).
            $googleOwnsEmail = in_array($domain, ['gmail.com', 'googlemail.com'], true)
                || (is_string($claims['hd'] ?? null) && strtolower($claims['hd']) === $domain);
            // El nombre que muestra Google (nunca el correo: quedaría a la vista en los viajes).
            $name = mb_substr(clean($claims['name'] ?? ''), 0, 80);
            if (mb_strlen($name) < 2) {
                $name = mb_substr(clean($claims['given_name'] ?? ''), 0, 80);
            }
            $picture = is_string($claims['picture'] ?? null) && preg_match($config['google_picture_pattern'], $claims['picture'])
                ? $claims['picture'] : '';
            $fields = 'SELECT id,name,google_sub,blocked,blocked_until,blocked_reason FROM users';
            $account = query($db, "$fields WHERE google_sub=?", [$claims['sub']])->fetch()
                ?: query($db, "$fields WHERE email=?", [$email])->fetch();
            if ($account) {
                if ($account['google_sub'] !== '' && $account['google_sub'] !== $claims['sub']) {
                    fail('Ese correo ya está unido a otra cuenta de Google.', 409);
                }
                if ((int)$account['blocked'] === 1) {
                    suspended($account);
                }
                if ($account['google_sub'] === '') {
                    if (!$googleOwnsEmail) {
                        fail('Ya hay una cuenta con ese correo. Entrá con tu correo y contraseña.', 409);
                    }
                    // Google confirmó que el correo es suyo. Por si otra persona hubiera creado la cuenta con ese
                    // correo antes que el dueño, la contraseña anterior deja de servir, se cierran las sesiones
                    // que estaban abiertas y queda el nombre de la cuenta de Google.
                    query($db, 'UPDATE users SET google_sub=?, password=?, name=?, auth_epoch=auth_epoch+1 WHERE id=?', [
                        $claims['sub'], password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                        mb_strlen($name) >= 2 ? $name : $account['name'], $account['id'],
                    ]);
                }
                query($db, 'UPDATE users SET google_picture=? WHERE id=?', [$picture, $account['id']]);
                sign_in($db, $account['id']);
            }
            if (mb_strlen($name) < 2) {
                $name = 'Usuario de Google';  // se cambia en Mi perfil
            }
            $uid = bin2hex(random_bytes(16));
            query($db, 'INSERT INTO users(id,email,name,password,created,google_sub,google_picture) VALUES (?,?,?,?,?,?,?)',
                [$uid, $email, $name, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), time(), $claims['sub'], $picture]);
            sign_in($db, $uid);

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
            $row = query($db, 'SELECT u.id, u.name, u.created, u.blocked, u.photo, ' . rating_sql('u.id', 'user') . ' FROM users u WHERE u.id=?',
                [(string)($_GET['id'] ?? '')])->fetch();
            if (!$row || (int)$row['blocked'] === 1) {
                fail('Perfil no encontrado.', 404);
            }
            $reviews = array_map(fn($r) => [...$r, 'rater_photo' => photo_url($r['rater_photo'])],
                query($db, 'SELECT r.stars, r.comment, r.updated, w.id AS rater_id, w.name AS rater_name, w.photo AS rater_photo
                    FROM ratings r JOIN users w ON w.id=r.rater WHERE r.rated=? AND w.blocked=0 ORDER BY r.updated DESC LIMIT 50',
                    [$row['id']])->fetchAll());
            $mine = $user ? query($db, 'SELECT stars, comment FROM ratings WHERE rater=? AND rated=?', [$user['id'], $row['id']])->fetch() : null;
            $trips = query($db, "$tripsSql WHERE t.owner=? AND t.status='active' AND t.day>=? ORDER BY t.day, t.hour LIMIT 50",
                [$row['id'], today()])->fetchAll();
            respond([
                'profile' => ['id' => $row['id'], 'name' => $row['name'], 'created' => (int)$row['created'], 'photo' => photo_url($row['photo']),
                    'rating' => $row['user_rating'] === null ? null : (float)$row['user_rating'], 'ratings' => (int)$row['user_ratings']],
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
            $comment = clean($input['comment'] ?? '');
            if (mb_strlen($comment) > MAX_COMMENT) {
                fail('El comentario puede tener hasta ' . MAX_COMMENT . ' letras.');
            }
            // También por conexión, para que no se pueda llenar un perfil de estrellas con cuentas inventadas.
            if (!limit($db, 'rate:' . $me['id'], 30, 86400) || !limit($db, 'rate-ip:' . $ip, 40, 86400)) {
                fail('Hiciste muchas calificaciones hoy. Probá mañana.', 429);
            }
            // Una sola calificación por persona: si ya había una, se reemplaza.
            query($db, 'INSERT INTO ratings(id,rater,rated,stars,comment,created,updated) VALUES (?,?,?,?,?,?,?)
                ON CONFLICT(rater, rated) DO UPDATE SET stars=excluded.stars, comment=excluded.comment, updated=excluded.updated',
                [bin2hex(random_bytes(16)), $me['id'], $target['id'], $stars, $comment, time(), time()]);
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
            if (!limit($db, 'photo:' . $me['id'], 10, 86400)) {
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

        case 'POST photo_google':
            $me = $requireUser();
            $url = $me['google_picture'];
            if ($url === '' || !preg_match($config['google_picture_pattern'], $url)) {
                fail('Tu cuenta no tiene una foto de Google para usar.', 404);
            }
            if (!limit($db, 'photo:' . $me['id'], 10, 86400)) {
                fail('Cambiaste la foto muchas veces hoy. Probá mañana.', 429);
            }
            // Google entrega la foto chica (=s96-c): se pide una más grande.
            $url = preg_replace('/=s\d+(-c)?$/', '=s400-c', $url);
            $bytes = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 0]]), 0, MAX_PHOTO_BYTES + 1);
            $file = is_string($bytes) && strlen($bytes) <= MAX_PHOTO_BYTES ? save_photo($bytes, $photos) : null;
            if (!$file) {
                fail('No pudimos traer tu foto de Google. Probá subir una desde tu teléfono.', 502);
            }
            replace_photo($db, $photos, $me, $file);
            respond(['user' => public_user(load_user($db, $me['id']))]);

        case 'POST photo_delete':
            $me = $requireUser();
            replace_photo($db, $photos, $me, '');
            respond(['user' => public_user(load_user($db, $me['id']))]);

        case 'POST admin_claim':
            if (!$user) {
                fail('Iniciá sesión.', 401);
            }
            if (!limit($db, 'claim:' . $ip, 10, 3600)) {
                fail('Demasiados intentos. Esperá una hora.', 429);
            }
            if ($db->query('SELECT 1 FROM users WHERE is_admin=1')->fetch()) {
                fail('La administración ya está activada. Pedile acceso a un administrador.', 409);
            }
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($input['code'] ?? '')));
            if ($code === '' || !hash_equals(strtolower($config['admin_code_sha256']), hash('sha256', $code))) {
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
            respond(['items' => query($db, 'SELECT r.id, r.stars, r.comment, r.updated,
                    w.id AS rater_id, w.name AS rater_name, w.email AS rater_email,
                    w.created AS rater_created, length(w.google_sub) > 0 AS rater_google,
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
                    u.blocked_until, u.blocked_reason, u.google_sub != '' AS google,
                    (SELECT COUNT(*) FROM trips t WHERE t.owner=u.id AND t.status!='deleted') AS trips,
                    (SELECT COUNT(*) FROM trips t WHERE t.owner=u.id) AS trips_total, u.photo, " . rating_sql('u.id', 'user') . "
                FROM users u ORDER BY u.created DESC LIMIT 1000")->fetchAll())]);

        case 'GET admin_settings':
            respond(['googleClientId' => setting($db, 'google_client_id')]);

        case 'POST admin_settings':
            $clientId = clean($input['googleClientId'] ?? '');
            if ($clientId !== '' && !preg_match(GOOGLE_CLIENT_ID, $clientId)) {
                fail('Ese no parece un ID de cliente de Google: tiene que terminar en .apps.googleusercontent.com.');
            }
            // Las cuentas de Google no tienen contraseña: si se apaga Google, no podrían volver a entrar.
            $googleAccounts = (int)$db->query("SELECT COUNT(*) FROM users WHERE google_sub != ''")->fetchColumn();
            if ($clientId === '' && $googleAccounts > 0) {
                fail("No se puede apagar: $googleAccounts " . ($googleAccounts === 1 ? 'cuenta entra' : 'cuentas entran')
                    . ' con Google y no podrían volver a entrar. Si cambiaste de proyecto en Google, pegá el ID nuevo.', 409);
            }
            query($db, 'INSERT INTO settings(key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',
                ['google_client_id', $clientId]);
            respond(['ok' => true]);

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
            if ($target['id'] === $user['id'] && in_array($do, ['block', 'remove_admin'], true)) {
                fail('No podés hacerte eso a vos mismo.');
            }
            if ($do === 'block' && (int)$target['is_admin'] === 1) {
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
