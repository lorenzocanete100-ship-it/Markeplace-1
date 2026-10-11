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
const REPORT_REASONS = ['precio', 'datos', 'ofensivo', 'otro'];
const VERIFY_PHOTOS = ['front', 'back', 'selfie'];
const MAX_PHOTO_BYTES = 10 * 1024 * 1024;
const SUSPEND_DAYS = [1, 7, 30, 0];  // 0: sin fecha, hasta que la administración la levante
const SUSPEND_REASONS = [
    'precio' => 'Cobró un precio por el viaje o lo usó como negocio.',
    'datos' => 'Publicó datos falsos.',
    'conducta' => 'Tuvo una conducta ofensiva o peligrosa.',
    'denuncias' => 'Recibió varias denuncias.',
    'otro' => 'No cumplió las reglas del sitio.',
];
const REJECT_REASONS = [
    'ilegible' => 'Las fotos no se ven bien.',
    'datos' => 'Los datos del DNI no coinciden con el nombre de tu cuenta.',
    'selfie' => 'En la selfie no se ve tu cara junto al DNI.',
    'otro' => 'No pudimos confirmar tu identidad.',
];

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
        CREATE TABLE IF NOT EXISTS verifications (
          id TEXT PRIMARY KEY, user TEXT NOT NULL REFERENCES users(id),
          status TEXT NOT NULL DEFAULT \'pending\', reason TEXT NOT NULL DEFAULT \'\',
          files TEXT NOT NULL DEFAULT \'[]\', created INTEGER NOT NULL, decided INTEGER NOT NULL DEFAULT 0);
        CREATE INDEX IF NOT EXISTS idx_verifications_user ON verifications(user, created);
        CREATE INDEX IF NOT EXISTS idx_verifications_status ON verifications(status, created);
        CREATE TABLE IF NOT EXISTS limits (key TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires INTEGER NOT NULL);
    ');
    // Bases creadas con versiones anteriores no tienen estas columnas.
    $columns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    $added = [
        'is_admin' => 'INTEGER NOT NULL DEFAULT 0', 'blocked' => 'INTEGER NOT NULL DEFAULT 0',
        'verified' => 'INTEGER NOT NULL DEFAULT 0', 'verified_at' => 'INTEGER NOT NULL DEFAULT 0',
        'blocked_until' => 'INTEGER NOT NULL DEFAULT 0', 'blocked_reason' => "TEXT NOT NULL DEFAULT ''",
    ];
    foreach ($added as $column => $type) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $column $type");
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

function load_user(PDO $db, string $id): ?array
{
    return query($db, 'SELECT id,email,name,is_admin,blocked,verified,verified_at,created FROM users WHERE id=?', [$id])->fetch() ?: null;
}

function public_user(?array $user): ?array
{
    return $user ? [
        'id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'created' => (int)$user['created'],
        'admin' => (int)$user['is_admin'] === 1, 'verified' => (int)$user['verified'] === 1, 'verifiedAt' => (int)$user['verified_at'],
    ] : null;
}

// Guarda una foto de verificación como JPEG nuevo: al recodificarla se pierden los
// datos ocultos de la cámara (como la ubicación GPS). Devuelve null si no es una foto válida.
function store_photo(array $file, string $folder): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size'] > MAX_PHOTO_BYTES) {
        return null;
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > 30_000_000) {
        return null;
    }
    $image = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
    if (!$image) {
        return null;
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $turn = [3 => 180, 6 => -90, 8 => 90][(int)(@exif_read_data($file['tmp_name'])['Orientation'] ?? 1)] ?? 0;
        if ($turn) {
            $image = imagerotate($image, $turn, 0);
        }
    }
    $scale = min(1, 1600 / max(imagesx($image), imagesy($image)));
    if ($scale < 1) {
        $image = imagescale($image, (int)round(imagesx($image) * $scale), (int)round(imagesy($image) * $scale));
    }
    $name = bin2hex(random_bytes(16)) . '.jpg';
    return imagejpeg($image, "$folder/$name", 82) ? $name : null;
}

function delete_photos(string $folder, string $json): void
{
    foreach (json_decode($json, true) ?: [] as $name) {
        if (is_string($name) && preg_match('/^[a-f0-9]{32}\.jpg$/', $name)) {
            @unlink("$folder/$name");
        }
    }
}

try {
    $dir = data_dir($config);
    $db = connect($dir);
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
    if ($user && (int)$user['blocked'] === 1) {
        // Una cuenta suspendida pierde la sesión en el siguiente pedido.
        unset($_SESSION['uid']);
        $user = null;
    }
    $isAdmin = $user && (int)$user['is_admin'] === 1;
    $photos = "$dir/verificaciones";
    $serialize = function (array $row) use ($user, $isAdmin): array {
        $mine = $user && $row['owner'] === $user['id'];
        $row['driver_id'] = $row['owner'];
        $row['driver_verified'] = (int)($row['driver_verified'] ?? 0) === 1;
        unset($row['owner']);
        return [...$row, 'mine' => $mine, 'canDelete' => $mine || $isAdmin];
    };
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
            respond(['csrf' => $_SESSION['csrf'], 'today' => today(), 'user' => public_user($user)]);

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
            } else {
                $row = query($db, 'SELECT id,password,blocked,blocked_until,blocked_reason FROM users WHERE email=?', [$email])->fetch();
                if (!$row || !password_verify($password, $row['password'])) {
                    fail('Correo o contraseña incorrectos.', 401);
                }
                if ((int)$row['blocked'] === 1) {
                    $until = (int)$row['blocked_until'];
                    fail('Tu cuenta está suspendida' . ($until ? date(' \h\a\s\t\a \e\l d/m/Y \a \l\a\s H:i', $until) : '') . '.'
                        . ($row['blocked_reason'] !== '' ? ' Motivo: ' . $row['blocked_reason'] : ''), 403);
                }
                $uid = $row['id'];
            }
            session_regenerate_id(true);
            $_SESSION = ['uid' => $uid, 'csrf' => bin2hex(random_bytes(32))];
            respond(['csrf' => $_SESSION['csrf'], 'user' => public_user(load_user($db, $uid))]);

        case 'GET trips':
            if (($_GET['mine'] ?? '') === '1') {
                if (!$user) {
                    fail('Iniciá sesión.', 401);
                }
                // Primero los próximos viajes, al final los que ya pasaron.
                $stmt = query($db, "SELECT t.*, u.verified AS driver_verified FROM trips t JOIN users u ON u.id=t.owner
                    WHERE t.owner=? AND t.status!='deleted' ORDER BY t.day < ?, t.day, t.hour LIMIT 300", [$user['id'], today()]);
            } else {
                // Los viajes pasados desaparecen solos; los completos quedan ocultos hasta que el conductor los reabra.
                $stmt = query($db, "SELECT t.*, u.verified AS driver_verified FROM trips t JOIN users u ON u.id=t.owner
                    WHERE t.status='active' AND t.day>=? AND u.blocked=0 ORDER BY t.day, t.hour LIMIT 300", [today()]);
            }
            respond(['items' => array_map($serialize, $stmt->fetchAll())]);

        case 'POST trip_create':
            if (!$user) {
                fail('Iniciá sesión para publicar un viaje.', 401);
            }
            $v = [];
            foreach (['origin', 'destination', 'day', 'hour', 'driver', 'phone', 'vehicle'] as $k) {
                $v[$k] = clean($input[$k] ?? '');
            }
            $v['notes'] = multiline($input['notes'] ?? '');
            if ((int)$user['verified'] === 1) {
                // Con el perfil verificado, el viaje va con el nombre que se verificó.
                $v['driver'] = $user['name'];
            }
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
            if (!length_between($v['driver'], 2, 60)) {
                fail('Ingresá tu nombre como conductor.');
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
            $row = query($db, 'SELECT id,name,verified,verified_at,created,blocked FROM users WHERE id=?', [(string)($_GET['id'] ?? '')])->fetch();
            if (!$row || (int)$row['blocked'] === 1) {
                fail('Perfil no encontrado.', 404);
            }
            $trips = query($db, "SELECT t.*, u.verified AS driver_verified FROM trips t JOIN users u ON u.id=t.owner
                WHERE t.owner=? AND t.status='active' AND t.day>=? ORDER BY t.day, t.hour LIMIT 50", [$row['id'], today()])->fetchAll();
            respond([
                'profile' => ['id' => $row['id'], 'name' => $row['name'], 'created' => (int)$row['created'],
                    'verified' => (int)$row['verified'] === 1, 'verifiedAt' => (int)$row['verified_at']],
                'trips' => array_map($serialize, $trips),
            ]);

        case 'GET verification':
            $me = $requireUser();
            $request = query($db, 'SELECT status,reason,created,decided FROM verifications WHERE user=? ORDER BY created DESC LIMIT 1', [$me['id']])->fetch();
            respond(['user' => public_user($me), 'request' => $request ?: null]);

        case 'POST verify_request':
            $me = $requireUser();
            if ((int)$me['verified'] === 1) {
                fail('Tu perfil ya está verificado.', 409);
            }
            if (query($db, "SELECT 1 FROM verifications WHERE user=? AND status='pending'", [$me['id']])->fetch()) {
                fail('Ya mandaste tus fotos: están en revisión.', 409);
            }
            if (($_POST['consent'] ?? '') !== '1') {
                fail('Aceptá que la administración vea tus fotos para verificar tu perfil.');
            }
            if (!limit($db, 'verify:' . $me['id'], 5, 86400)) {
                fail('Hiciste muchos intentos hoy. Probá mañana.', 429);
            }
            if (!is_dir($photos) && !@mkdir($photos, 0700, true)) {
                fail('El servidor no puede guardar las fotos.', 503);
            }
            $saved = [];
            foreach (VERIFY_PHOTOS as $field) {
                $name = isset($_FILES[$field]) && is_array($_FILES[$field]) ? store_photo($_FILES[$field], $photos) : null;
                if ($name === null) {
                    delete_photos($photos, json_encode($saved));
                    fail('Subí las tres fotos (DNI de frente, DNI de dorso y selfie con el DNI) en JPG, PNG o WebP, de hasta 10 MB cada una.');
                }
                $saved[] = $name;
            }
            query($db, 'INSERT INTO verifications(id,user,files,created) VALUES (?,?,?,?)',
                [bin2hex(random_bytes(16)), $me['id'], json_encode($saved), time()]);
            respond(['ok' => true], 201);

        case 'POST verify_cancel':
            $me = $requireUser();
            $request = query($db, "SELECT id,files FROM verifications WHERE user=? AND status='pending'", [$me['id']])->fetch();
            if ($request) {
                delete_photos($photos, $request['files']);
                query($db, "UPDATE verifications SET status='cancelled', files='[]', decided=? WHERE id=?", [time(), $request['id']]);
            }
            respond(['ok' => true]);

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
                'verifications' => $count("SELECT COUNT(*) FROM verifications WHERE status='pending'"),
            ]);

        case 'GET admin_verifications':
            $rows = query($db, "SELECT v.id, v.created, v.files, u.id AS user, u.name, u.email, u.created AS member_since
                FROM verifications v JOIN users u ON u.id=v.user WHERE v.status='pending' ORDER BY v.created LIMIT 200")->fetchAll();
            respond(['items' => array_map(fn($r) => [...$r, 'files' => json_decode($r['files'], true) ?: []], $rows)]);

        case 'GET admin_photo':
            // Las fotos solo existen mientras la verificación está pendiente y solo las ve la administración.
            $name = (string)($_GET['file'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}\.jpg$/', $name) || !is_file("$photos/$name")
                || !query($db, "SELECT 1 FROM verifications WHERE status='pending' AND files LIKE ?", ['%"' . $name . '"%'])->fetch()) {
                fail('Foto no encontrada.', 404);
            }
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . filesize("$photos/$name"));
            readfile("$photos/$name");
            exit;

        case 'POST admin_verification':
            $request = query($db, "SELECT id,user,files FROM verifications WHERE id=? AND status='pending'", [(string)($input['id'] ?? '')])->fetch();
            if (!$request) {
                fail('Esa verificación ya fue revisada.', 404);
            }
            $do = $input['action'] ?? '';
            $reason = REJECT_REASONS[$input['reason'] ?? ''] ?? null;
            if ($do !== 'approve' && !($do === 'reject' && $reason)) {
                fail('Elegí aprobar o rechazar con un motivo.');
            }
            delete_photos($photos, $request['files']);
            query($db, "UPDATE verifications SET status=?, reason=?, files='[]', decided=? WHERE id=?",
                [$do === 'approve' ? 'approved' : 'rejected', $reason ?? '', time(), $request['id']]);
            if ($do === 'approve') {
                query($db, 'UPDATE users SET verified=1, verified_at=? WHERE id=?', [time(), $request['user']]);
            }
            respond(['ok' => true]);

        case 'GET admin_reports':
            respond(['items' => query($db, "SELECT r.id, r.reason, r.details, r.created,
                    t.id AS trip, t.origin, t.destination, t.day, t.hour, t.driver, t.phone, t.status AS trip_status,
                    u.email AS owner_email
                FROM reports r JOIN trips t ON t.id=r.trip JOIN users u ON u.id=t.owner
                WHERE r.status='open' ORDER BY r.created DESC LIMIT 300")->fetchAll()]);

        case 'GET admin_trips':
            respond(['items' => query($db, "SELECT t.*, u.email AS owner_email, u.blocked AS owner_blocked,
                    (SELECT COUNT(*) FROM reports r WHERE r.trip=t.id AND r.status='open') AS reports
                FROM trips t JOIN users u ON u.id=t.owner ORDER BY t.created DESC LIMIT 500")->fetchAll()]);

        case 'GET admin_users':
            respond(['items' => query($db, "SELECT u.id, u.email, u.name, u.created, u.is_admin, u.blocked, u.verified,
                    u.blocked_until, u.blocked_reason,
                    (SELECT COUNT(*) FROM trips t WHERE t.owner=u.id AND t.status!='deleted') AS trips,
                    (SELECT COUNT(*) FROM trips t WHERE t.owner=u.id) AS trips_total
                FROM users u ORDER BY u.created DESC LIMIT 1000")->fetchAll()]);

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
            $changes = ['unblock' => "blocked=0, blocked_until=0, blocked_reason=''", 'make_admin' => 'is_admin=1',
                'remove_admin' => 'is_admin=0', 'unverify' => 'verified=0, verified_at=0'];
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
