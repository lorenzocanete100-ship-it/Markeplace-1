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
        CREATE TABLE IF NOT EXISTS limits (key TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires INTEGER NOT NULL);
    ');
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

try {
    $dir = data_dir($config);
    $db = connect($dir);
    start_session($dir);

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['a'] ?? '';
    $adminEmail = strtolower(trim($config['admin_email']));

    $input = [];
    if ($method === 'POST') {
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            fail('La sesión venció. Recargá la página e intentá nuevamente.', 403);
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

    $user = null;
    if (!empty($_SESSION['uid'])) {
        $stmt = $db->prepare('SELECT id,email,name FROM users WHERE id=?');
        $stmt->execute([$_SESSION['uid']]);
        $user = $stmt->fetch() ?: null;
    }
    $isAdmin = $user && $adminEmail !== '' && $user['email'] === $adminEmail;
    $publicUser = fn() => $user ? [...$user, 'admin' => $isAdmin] : null;
    $serialize = function (array $row) use ($user, $isAdmin): array {
        $mine = $user && $row['owner'] === $user['id'];
        unset($row['owner']);
        return [...$row, 'mine' => $mine, 'canDelete' => $mine || $isAdmin];
    };
    $ownTrip = function () use ($db, $input, $user, $isAdmin, $action): array {
        if (!$user) {
            fail('Iniciá sesión.', 401);
        }
        $stmt = $db->prepare("SELECT * FROM trips WHERE id=? AND status!='deleted'");
        $stmt->execute([(string)($input['id'] ?? '')]);
        $row = $stmt->fetch();
        if (!$row) {
            fail('Viaje no encontrado.', 404);
        }
        if ($row['owner'] !== $user['id'] && !($action === 'trip_delete' && $isAdmin)) {
            fail('No podés modificar este viaje.', 403);
        }
        return $row;
    };

    switch ("$method $action") {
        case 'GET health':
            $db->query('SELECT 1')->fetch();
            respond(['ok' => true]);

        case 'GET session':
            respond(['csrf' => $_SESSION['csrf'], 'today' => today(), 'user' => $publicUser()]);

        case 'POST logout':
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            respond(['ok' => true, 'csrf' => $_SESSION['csrf']]);

        case 'POST login':
        case 'POST register':
            if (!limit($db, 'auth:' . ($_SERVER['REMOTE_ADDR'] ?? '?'), 25, 900)) {
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
                    $db->prepare('INSERT INTO users VALUES (?,?,?,?,?)')
                        ->execute([$uid, $email, $name, password_hash($password, PASSWORD_DEFAULT), time()]);
                } catch (PDOException) {
                    fail('Ya existe una cuenta con ese correo. Probá entrar.', 409);
                }
            } else {
                $stmt = $db->prepare('SELECT id,password FROM users WHERE email=?');
                $stmt->execute([$email]);
                $row = $stmt->fetch();
                if (!$row || !password_verify($password, $row['password'])) {
                    fail('Correo o contraseña incorrectos.', 401);
                }
                $uid = $row['id'];
            }
            session_regenerate_id(true);
            $_SESSION = ['uid' => $uid, 'csrf' => bin2hex(random_bytes(32))];
            $stmt = $db->prepare('SELECT id,email,name FROM users WHERE id=?');
            $stmt->execute([$uid]);
            $user = $stmt->fetch();
            $isAdmin = $adminEmail !== '' && $user['email'] === $adminEmail;
            respond(['csrf' => $_SESSION['csrf'], 'user' => [...$user, 'admin' => $isAdmin]]);

        case 'GET trips':
            if (($_GET['mine'] ?? '') === '1') {
                if (!$user) {
                    fail('Iniciá sesión.', 401);
                }
                // Primero los próximos viajes, al final los que ya pasaron.
                $stmt = $db->prepare("SELECT * FROM trips WHERE owner=? AND status!='deleted' ORDER BY day < ?, day, hour LIMIT 300");
                $stmt->execute([$user['id'], today()]);
            } else {
                // Los viajes pasados desaparecen solos; los completos quedan ocultos hasta que el conductor los reabra.
                $stmt = $db->prepare("SELECT * FROM trips WHERE status='active' AND day>=? ORDER BY day, hour LIMIT 300");
                $stmt->execute([today()]);
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
            $notes = $input['notes'] ?? '';
            $v['notes'] = is_string($notes) && mb_check_encoding($notes, 'UTF-8') ? trim(preg_replace('/[ \t]+/u', ' ', $notes)) : '';
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
            $db->prepare('INSERT INTO trips VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
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
            $db->prepare('UPDATE trips SET status=?, seats=? WHERE id=?')->execute([$status, $seats, $row['id']]);
            respond(['ok' => true]);

        case 'POST trip_delete':
            $row = $ownTrip();
            $db->prepare("UPDATE trips SET status='deleted' WHERE id=?")->execute([$row['id']]);
            respond(['ok' => true]);

        default:
            fail('Acción no válida.', 404);
    }
} catch (Throwable $e) {
    error_log('Asiento Vacío: ' . $e);
    fail('No se pudo completar la operación. Intentá nuevamente.', 503);
}
