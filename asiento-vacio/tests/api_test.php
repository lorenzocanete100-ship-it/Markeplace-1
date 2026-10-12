<?php
/*
 * Pruebas de Asiento Vacío. Levanta la app con el servidor integrado de PHP
 * sobre una carpeta de datos temporal y recorre el flujo completo.
 *
 *   php tests/api_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__) . '/public';
$data = sys_get_temp_dir() . '/asiento-test-' . bin2hex(random_bytes(4));
$port = random_int(20000, 40000);
$base = "http://127.0.0.1:$port";

// Clave de prueba que hace de "Google": firma los comprobantes y publica su clave pública.
$b64u = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$googleKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$rsa = openssl_pkey_get_details($googleKey)['rsa'];
$certs = "$data-certs.json";
file_put_contents($certs, json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'clave-prueba', 'alg' => 'RS256', 'use' => 'sig', 'n' => $b64u($rsa['n']), 'e' => $b64u($rsa['e'])]]]));
const CLIENT_ID = '1234567890-pruebaasiento.apps.googleusercontent.com';
// Otro servidor local hace de "fotos de Google" para probar "Usar mi foto de Google".
$picturePort = $port === 40000 ? 39999 : $port + 1;
$pictures = "$data-fotos-google";
mkdir($pictures);
$googlePhoto = imagecreatetruecolor(600, 400);
imagefill($googlePhoto, 0, 0, imagecolorallocate($googlePhoto, 200, 40, 40));
imagejpeg($googlePhoto, "$pictures/perfil=s400-c");
define('PICTURE_URL', "http://127.0.0.1:$picturePort/perfil=s96-c");
$googleToken = function (array $claims = [], array $header = [], $key = null) use ($googleKey, $b64u): string {
    $claims = array_filter([
        'iss' => 'https://accounts.google.com', 'aud' => CLIENT_ID, 'sub' => '1098765432101234567',
        'email' => 'Gabi.Gomez@gmail.com', 'email_verified' => true, 'name' => 'Gabriela Gómez', 'picture' => PICTURE_URL,
        'iat' => time(), 'exp' => time() + 3600, ...$claims,
    ], fn($v) => $v !== null);
    $head = $b64u(json_encode(['alg' => 'RS256', 'kid' => 'clave-prueba', 'typ' => 'JWT', ...$header]));
    $body = $b64u(json_encode($claims));
    openssl_sign("$head.$body", $signature, $key ?? $googleKey, OPENSSL_ALGO_SHA256);
    return "$head.$body." . $b64u($signature);
};

$env = [...getenv(), 'ASIENTO_DATA_DIR' => $data, 'ASIENTO_ADMIN_CODE_SHA256' => hash('sha256', 'TESTCODE1234'),
    'ASIENTO_GOOGLE_CERTS_URL' => $certs, 'ASIENTO_GOOGLE_PICTURE_PATTERN' => "#^http://127\\.0\\.0\\.1:$picturePort/#"];

// Base con el formato anterior (sin administración), como la que ya puede haber en el hosting,
// con restos de la verificación con DNI que se quitó.
mkdir("$data/sesiones", 0700, true);
mkdir("$data/verificaciones", 0700);
file_put_contents("$data/verificaciones/" . str_repeat('ab', 16) . '.jpg', 'foto vieja de un DNI');
$legacy = new PDO("sqlite:$data/viajes.sqlite");
$legacy->exec('CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE, name TEXT NOT NULL, password TEXT NOT NULL, created INTEGER NOT NULL)');
$legacy->prepare('INSERT INTO users VALUES (?,?,?,?,?)')->execute(['cuenta-vieja', 'vieja@example.com', 'Cuenta vieja', password_hash('una-clave-de-prueba', PASSWORD_DEFAULT), time()]);
$legacy->exec("CREATE TABLE verifications (id TEXT PRIMARY KEY, user TEXT, status TEXT, reason TEXT, files TEXT, created INTEGER, decided INTEGER)");
$legacy->exec("INSERT INTO verifications VALUES ('v1', 'cuenta-vieja', 'pending', '', '[\"ab.jpg\"]', 1, 0)");
$legacy = null;

$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
$pictureServer = proc_open([PHP_BINARY, '-S', "127.0.0.1:$picturePort", '-t', $pictures], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
register_shutdown_function(function () use ($server, $pictureServer, $data, $certs, $pictures) {
    proc_terminate($server);
    proc_terminate($pictureServer);
    exec('rm -rf ' . escapeshellarg($data) . ' ' . escapeshellarg($pictures));
    @unlink($certs);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

final class Client
{
    private string $jar;
    public string $csrf = '';

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
        $this->csrf = $this->get('session')[1]['csrf'];
    }

    public function get(string $action, string $query = ''): array
    {
        return $this->request("api.php?a=$action$query");
    }

    public function post(string $action, array $body, ?string $csrf = null): array
    {
        return $this->request("api.php?a=$action", json_encode($body), $csrf ?? $this->csrf);
    }

    public function upload(string $action, array $fields, array $files): array
    {
        foreach ($files as $field => $path) {
            $fields[$field] = new CURLFile($path, 'image/jpeg');
        }
        $ch = curl_init("$this->base/api.php?a=$action");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => ["X-CSRF-Token: $this->csrf"], CURLOPT_POSTFIELDS => $fields,
        ]);
        $response = curl_exec($ch);
        return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string)$response, true)];
    }

    // GET con la sesión de este cliente, devolviendo el contenido tal cual (para fotos).
    public function bytes(string $path): array
    {
        $ch = curl_init("$this->base/$path");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar]);
        $body = curl_exec($ch);
        return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body, curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
    }

    public function raw(string $path): array
    {
        $ch = curl_init("$this->base/$path");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
        $body = curl_exec($ch);
        return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
    }

    private function request(string $path, ?string $body = null, string $csrf = ''): array
    {
        $ch = curl_init("$this->base/$path");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $csrf"],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string)$response, true)];
    }

    public function register(string $email, string $name = 'Juan'): array
    {
        $r = $this->post('register', ['email' => $email, 'password' => 'una-clave-de-prueba', 'name' => $name]);
        $this->csrf = $r[1]['csrf'] ?? $this->csrf;
        return $r;
    }
}

$failures = 0;
function check(string $name, bool $ok, mixed $detail = null): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok ? '' : ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    $failures += $ok ? 0 : 1;
}

date_default_timezone_set('America/Argentina/Buenos_Aires');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$trip = [
    'origin' => 'González Catán', 'destination' => 'Córdoba', 'day' => $tomorrow, 'hour' => '07:30',
    'seats' => 3, 'driver' => 'Juan', 'phone' => '54 9 11 2345 6789', 'vehicle' => 'Fiat Cronos gris',
    'notes' => "Salgo desde la plaza.\nLlevo mate.", 'confirmPrivate' => true, 'confirmPhone' => true,
];

echo "Asiento Vacío: pruebas\n";
$anon = new Client($base);
[$code, $body] = $anon->get('session');
check('la sesión entrega token y fecha', $code === 200 && strlen($body['csrf']) === 64 && $body['today'] === date('Y-m-d') && $body['user'] === null, $body);
[$code, $body] = $anon->post('register', [], 'malo');
check('sin token CSRF se rechaza', $code === 403 && $body['csrf'] === true, $body);
check('publicar sin cuenta pide iniciar sesión', $anon->post('trip_create', $trip)[0] === 401);
check('mis viajes sin cuenta pide iniciar sesión', $anon->get('trips', '&mine=1')[0] === 401);

$old = new Client($base);
[$code, $body] = $old->post('login', ['email' => 'vieja@example.com', 'password' => 'una-clave-de-prueba']);
check('una cuenta de la versión anterior sigue funcionando', $code === 200 && $body['user']['admin'] === false, $body);

$driver = new Client($base);
[$code, $body] = $driver->register('juan@example.com');
check('registro de conductor', $code === 200 && $body['user']['name'] === 'Juan' && $body['user']['admin'] === false, $body);
check('no se repite el correo', (new Client($base))->register('juan@example.com')[0] === 409);
[$code, $body] = $driver->post('trip_create', $trip);
check('publica un viaje', $code === 201 && isset($body['id']), $body);
$tripId = $body['id'];

[$code, $body] = $anon->get('trips');
$item = $body['items'][0] ?? [];
check('el viaje aparece en la lista pública', $code === 200 && count($body['items']) === 1, $body);
check('el teléfono se ve en la lista pública', ($item['phone'] ?? '') === '54 9 11 2345 6789' && $item['phone_digits'] === '5491123456789', $item);
check('no hay precio ni dueño en los datos', !array_key_exists('price', $item) && !array_key_exists('owner', $item), array_keys($item));
check('respeta saltos de línea en detalles', $item['notes'] === "Salgo desde la plaza.\nLlevo mate.", $item['notes']);

$bad = fn(array $changes) => $driver->post('trip_create', [...$trip, ...$changes]);
check('rechaza montos con $', $bad(['notes' => 'Son $5000 por persona'])[0] === 400);
check('rechaza la palabra precio', $bad(['notes' => 'Precio a convenir'])[0] === 400);
check('rechaza la palabra tarifa en el vehículo', $bad(['vehicle' => 'Tarifa fija'])[0] === 400);
check('acepta “combustible y comida”', $bad(['notes' => 'Compartimos combustible y alguna comida'])[0] === 201);
check('rechaza fechas pasadas', $bad(['day' => date('Y-m-d', strtotime('-1 day'))])[0] === 400);
check('rechaza fechas a más de 6 meses', $bad(['day' => date('Y-m-d', strtotime('+200 days'))])[0] === 400);
check('rechaza fechas inválidas', $bad(['day' => '2026-02-31'])[0] === 400);
check('rechaza horas inválidas', $bad(['hour' => '25:00'])[0] === 400);
check('rechaza 0 asientos', $bad(['seats' => 0])[0] === 400);
check('rechaza 9 asientos', $bad(['seats' => 9])[0] === 400);
check('rechaza teléfonos cortos', $bad(['phone' => '1234'])[0] === 400);
check('exige aceptar mostrar el teléfono', $bad(['confirmPhone' => false])[0] === 400);
check('exige confirmar que es particular', $bad(['confirmPrivate' => null])[0] === 400);

$busy = new Client($base);
$busy->register('flota@example.com', 'Flota');
$codes = array_map(fn() => $busy->post('trip_create', $trip)[0], range(1, 11));
check('limita a 10 viajes publicados por hora', array_count_values($codes) === [201 => 10, 429 => 1], $codes);

$other = new Client($base);
$other->register('otra@example.com', 'Ana');
check('otro usuario no puede editar', $other->post('trip_update', ['id' => $tripId, 'status' => 'full'])[0] === 403);
check('otro usuario no puede borrar', $other->post('trip_delete', ['id' => $tripId])[0] === 403);

check('el dueño cambia los asientos', $driver->post('trip_update', ['id' => $tripId, 'seats' => 2])[0] === 200);
check('no acepta asientos fuera de rango', $driver->post('trip_update', ['id' => $tripId, 'seats' => 12])[0] === 400);
check('no acepta estados inventados', $driver->post('trip_update', ['id' => $tripId, 'status' => 'deleted'])[0] === 400);
check('el dueño lo marca completo', $driver->post('trip_update', ['id' => $tripId, 'status' => 'full'])[0] === 200);
$ids = array_column($anon->get('trips')[1]['items'], 'id');
check('un viaje completo no aparece en la lista', !in_array($tripId, $ids, true), $ids);
$mine = $driver->get('trips', '&mine=1')[1]['items'];
$own = array_values(array_filter($mine, fn($t) => $t['id'] === $tripId))[0] ?? [];
check('sigue en mis viajes con 2 asientos', ($own['status'] ?? '') === 'full' && $own['seats'] === 2 && $own['mine'] === true, $own);
check('el dueño lo vuelve a publicar', $driver->post('trip_update', ['id' => $tripId, 'status' => 'active'])[0] === 200);

$db = new PDO("sqlite:$data/viajes.sqlite");
$db->prepare("UPDATE trips SET day=? WHERE id=?")->execute([date('Y-m-d', strtotime('-1 day')), $tripId]);
$ids = array_column($anon->get('trips')[1]['items'], 'id');
check('los viajes pasados desaparecen solos', !in_array($tripId, $ids, true), $ids);
$db->prepare("UPDATE trips SET day=? WHERE id=?")->execute([$tomorrow, $tripId]);

$find = fn(array $items, string $key, string $value) => array_values(array_filter($items, fn($i) => $i[$key] === $value))[0] ?? [];
$publicIds = fn() => array_column($anon->get('trips')[1]['items'], 'id');

echo "Administración\n";
$admin = new Client($base);
$admin->register('duena@example.com', 'Dueña');
check('sin cuenta no se entra a administración', $anon->get('admin_users')[0] === 401);
check('sin activar, un usuario no es administrador', $admin->get('admin_overview')[0] === 403);
check('código incorrecto', $admin->post('admin_claim', ['code' => 'AAAA-BBBB-CCCC-DDDD'])[0] === 403);
[$code, $body] = $admin->post('admin_claim', ['code' => 'test-code-1234']);
check('el código activa la administración', $code === 200 && $body['user']['admin'] === true, $body);
check('el código no sirve una segunda vez', $other->post('admin_claim', ['code' => 'TESTCODE1234'])[0] === 409);
check('los usuarios comunes no ven administración', $other->get('admin_trips')[0] === 403);

check('denuncia sin motivo', $anon->post('report', ['trip' => $tripId, 'reason' => 'porque sí'])[0] === 400);
check('denuncia de un viaje que no existe', $anon->post('report', ['trip' => 'nada', 'reason' => 'precio'])[0] === 404);
check('cualquiera puede denunciar sin cuenta', $anon->post('report', ['trip' => $tripId, 'reason' => 'precio', 'details' => 'Me pidió plata'])[0] === 201);
$report = $admin->get('admin_reports')[1]['items'][0] ?? [];
check('la denuncia llega a administración', ($report['trip'] ?? '') === $tripId && $report['details'] === 'Me pidió plata' && $report['owner_email'] === 'juan@example.com', $report);
$overview = $admin->get('admin_overview')[1];
check('el resumen cuenta denuncias y conductores', $overview['reports'] === 1 && $overview['drivers'] === 5 && $overview['upcoming'] >= 1, $overview);
check('descartar una denuncia', $admin->post('admin_report', ['id' => $report['id'], 'action' => 'dismiss'])[0] === 200 && $admin->get('admin_reports')[1]['items'] === []);
$anon->post('report', ['trip' => $tripId, 'reason' => 'datos']);
$row = $find($admin->get('admin_trips')[1]['items'], 'id', $tripId);
check('la lista de viajes muestra denuncias y correo', $row['reports'] === 1 && $row['owner_email'] === 'juan@example.com', $row);

$users = $admin->get('admin_users')[1]['items'];
$juan = $find($users, 'email', 'juan@example.com');
$me = $find($users, 'email', 'duena@example.com');
$ana = $find($users, 'email', 'otra@example.com');
check('la lista de conductores cuenta sus viajes', $juan['trips'] === 2 && $me['is_admin'] === 1, [$juan, $me]);
check('suspender pide plazo y motivo', $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'block'])[0] === 400
    && $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'block', 'days' => 3, 'reason' => 'precio'])[0] === 400
    && $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'block', 'days' => 7, 'reason' => 'inventado'])[0] === 400);
check('suspender 7 días por cobrar', $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'block', 'days' => 7, 'reason' => 'precio'])[0] === 200);
$juanRow = $find($admin->get('admin_users')[1]['items'], 'id', $juan['id']);
check('administración ve hasta cuándo y por qué', abs($juanRow['blocked_until'] - (time() + 7 * 86400)) < 60
    && $juanRow['blocked_reason'] === 'Cobró un precio por el viaje o lo usó como negocio.', $juanRow);
check('los viajes de un bloqueado no se ven', !in_array($tripId, $publicIds(), true));
check('un bloqueado pierde la sesión', $driver->get('session')[1]['user'] === null);
[$code, $body] = $driver->post('login', ['email' => 'juan@example.com', 'password' => 'una-clave-de-prueba']);
check('al entrar ve hasta cuándo y el motivo', $code === 403
    && $body['error'] === 'Tu cuenta está suspendida hasta el ' . date('d/m/Y \\a \\l\\a\\s H:i', $juanRow['blocked_until']) . '. Motivo: Cobró un precio por el viaje o lo usó como negocio.', $body);
check('levantar la suspensión', $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'unblock'])[0] === 200
    && $find($admin->get('admin_users')[1]['items'], 'id', $juan['id'])['blocked_until'] === 0);
check('desbloqueado, sus viajes vuelven', in_array($tripId, $publicIds(), true));
[$code, $body] = $driver->post('login', ['email' => 'juan@example.com', 'password' => 'una-clave-de-prueba']);
$driver->csrf = $body['csrf'] ?? $driver->csrf;
check('desbloqueado, puede entrar', $code === 200, $body);
check('no podés suspenderte a vos mismo', $admin->post('admin_user', ['id' => $me['id'], 'action' => 'block', 'days' => 7, 'reason' => 'otro'])[0] === 400);
$flota = $find($users, 'email', 'flota@example.com');
$admin->post('admin_user', ['id' => $flota['id'], 'action' => 'block', 'days' => 0, 'reason' => 'denuncias']);
[$code, $body] = (new Client($base))->post('login', ['email' => 'flota@example.com', 'password' => 'una-clave-de-prueba']);
check('suspensión sin fecha', $code === 403 && $body['error'] === 'Tu cuenta está suspendida. Motivo: Recibió varias denuncias.', $body);
$admin->post('admin_user', ['id' => $flota['id'], 'action' => 'block', 'days' => 1, 'reason' => 'otro']);
$db->prepare('UPDATE users SET blocked_until=? WHERE id=?')->execute([time() - 5, $flota['id']]);
[$code, $body] = (new Client($base))->post('login', ['email' => 'flota@example.com', 'password' => 'una-clave-de-prueba']);
$flotaRow = $find($admin->get('admin_users')[1]['items'], 'id', $flota['id']);
check('cuando vence el plazo, la suspensión se levanta sola', $code === 200 && $flotaRow['blocked'] === 0 && $flotaRow['blocked_reason'] === '', [$body, $flotaRow]);
check('la lista distingue conductores de acompañantes', $flotaRow['trips_total'] === 10 && $find($users, 'email', 'vieja@example.com')['trips_total'] === 0);
check('no podés quitarte la administración', $admin->post('admin_user', ['id' => $me['id'], 'action' => 'remove_admin'])[0] === 400);
check('nombrar otro administrador', $admin->post('admin_user', ['id' => $ana['id'], 'action' => 'make_admin'])[0] === 200 && $other->get('session')[1]['user']['admin'] === true);
check('no se suspende a un administrador', $admin->post('admin_user', ['id' => $ana['id'], 'action' => 'block', 'days' => 7, 'reason' => 'otro'])[0] === 400);
check('quitar la administración a otro', $admin->post('admin_user', ['id' => $ana['id'], 'action' => 'remove_admin'])[0] === 200 && $other->get('session')[1]['user']['admin'] === false);
check('acción de usuario inventada', $admin->post('admin_user', ['id' => $ana['id'], 'action' => 'borrar'])[0] === 400);

$listed = $find($admin->get('trips')[1]['items'], 'id', $tripId);
check('administración puede borrar viajes ajenos', $listed['canDelete'] ?? false, $listed);
check('administración no puede editar viajes ajenos', $admin->post('trip_update', ['id' => $tripId, 'seats' => 1])[0] === 403);
check('administración borra el viaje', $admin->post('admin_trip', ['id' => $tripId, 'action' => 'delete'])[0] === 200);
check('borrar el viaje cierra sus denuncias', $admin->get('admin_reports')[1]['items'] === []);
$ids = array_column($driver->get('trips', '&mine=1')[1]['items'], 'id');
check('un viaje borrado no aparece en ningún lado', !in_array($tripId, $ids, true) && !in_array($tripId, $publicIds(), true), $ids);
check('no se puede denunciar un viaje borrado', $anon->post('report', ['trip' => $tripId, 'reason' => 'otro'])[0] === 404);
check('restaurar un viaje borrado', $admin->post('admin_trip', ['id' => $tripId, 'action' => 'restore'])[0] === 200 && in_array($tripId, $publicIds(), true));
check('borrar desde la lista pública (moderación)', $admin->post('trip_delete', ['id' => $tripId])[0] === 200 && !in_array($tripId, $publicIds(), true));

echo "Restos de la verificación con DNI\n";
check('se borraron las fotos de DNI que hubiera en el servidor', !is_dir("$data/verificaciones"));
$tables = array_column((new PDO("sqlite:$data/viajes.sqlite"))->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
check('se borró la tabla de verificaciones', !in_array('verifications', $tables, true), $tables);
check('la verificación ya no existe', $driver->get('verification')[0] === 404 && $admin->get('admin_verifications')[0] === 404
    && $admin->bytes('api.php?a=admin_photo&file=' . str_repeat('ab', 16) . '.jpg')[0] === 404);

echo "Ingreso con Google\n";
[$code, $body] = $anon->get('session');
check('sin configurar, el ingreso con Google está apagado', $body['googleClientId'] === '' && $anon->post('google', ['credential' => $googleToken()])[0] === 404);
check('solo administración cambia los ajustes', $other->post('admin_settings', ['googleClientId' => CLIENT_ID])[0] === 403
    && $anon->get('admin_settings')[0] === 401);
check('rechaza un ID de cliente mal copiado', $admin->post('admin_settings', ['googleClientId' => 'mi-id-de-google'])[0] === 400);
check('guardar el ID de cliente', $admin->post('admin_settings', ['googleClientId' => " " . CLIENT_ID . " "])[0] === 200
    && $admin->get('admin_settings')[1]['googleClientId'] === CLIENT_ID && $anon->get('session')[1]['googleClientId'] === CLIENT_ID);
$otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$bad = [
    'un texto cualquiera' => 'no-es-un-comprobante',
    'firmado por otra clave' => $googleToken([], [], $otherKey),
    'para otra página (aud)' => $googleToken(['aud' => '999-otra.apps.googleusercontent.com']),
    'vencido' => $googleToken(['exp' => time() - 3600, 'iat' => time() - 7200]),
    'de otro emisor' => $googleToken(['iss' => 'https://evil.example.com']),
    'con correo sin confirmar' => $googleToken(['email_verified' => false]),
    'sin correo' => $googleToken(['email' => null]),
    'con clave desconocida' => $googleToken([], ['kid' => 'otra-clave']),
    'con algoritmo cambiado' => $googleToken([], ['alg' => 'HS256']),
    'con el contenido alterado' => (function () use ($googleToken, $b64u) {
        [$h, , $sig] = explode('.', $googleToken());
        return "$h." . $b64u(json_encode(['iss' => 'https://accounts.google.com', 'aud' => CLIENT_ID, 'sub' => '1', 'email' => 'x@gmail.com',
            'email_verified' => true, 'iat' => time(), 'exp' => time() + 3600])) . ".$sig";
    })(),
];
foreach ($bad as $label => $token) {
    check("rechaza un comprobante $label", (new Client($base))->post('google', ['credential' => $token])[0] === 401);
}
$gabi = new Client($base);
[$code, $body] = $gabi->post('google', ['credential' => $googleToken()]);
$gabi->csrf = $body['csrf'] ?? $gabi->csrf;
$gabiId = $body['user']['id'] ?? '';
check('con Google se crea la cuenta sola', $code === 200 && $body['user']['name'] === 'Gabriela Gómez'
    && $body['user']['email'] === 'gabi.gomez@gmail.com' && $body['user']['google'] === true, $body);
[$code, $body] = (new Client($base))->post('google', ['credential' => $googleToken(['name' => 'Otro Nombre'])]);
check('la segunda vez entra a la misma cuenta', $code === 200 && $body['user']['id'] === $gabiId && $body['user']['name'] === 'Gabriela Gómez', $body);
$users = $admin->get('admin_users')[1]['items'];
check('administración ve que la cuenta es de Google', $find($users, 'id', $gabiId)['google'] === 1 && $find($users, 'email', 'juan@example.com')['google'] === 0);
[$code, $body] = (new Client($base))->post('google', ['credential' => $googleToken(['sub' => '555000111', 'email' => 'otra@example.com', 'name' => 'Ana G'])]);
check('si ya tenía cuenta con ese correo, se une a esa cuenta', $code === 200 && $body['user']['id'] === $ana['id'] && $body['user']['name'] === 'Ana', $body);
[$code, $body] = (new Client($base))->post('login', ['email' => 'otra@example.com', 'password' => 'una-clave-de-prueba']);
check('después entra con Google y la contraseña vieja deja de servir', $code === 401 && str_contains($body['error'], 'Continuar con Google'), $body);
check('otra cuenta de Google con el mismo correo no entra', (new Client($base))->post('google', ['credential' => $googleToken(['sub' => '777', 'email' => 'otra@example.com'])])[0] === 409);
$admin->post('admin_user', ['id' => $gabiId, 'action' => 'block', 'days' => 7, 'reason' => 'conducta']);
[$code, $body] = (new Client($base))->post('google', ['credential' => $googleToken()]);
check('suspendida, tampoco entra con Google', $code === 403 && str_contains($body['error'], 'Motivo: Tuvo una conducta'), $body);
$admin->post('admin_user', ['id' => $gabiId, 'action' => 'unblock']);
[, $body] = $gabi->post('google', ['credential' => $googleToken()]);
$gabi->csrf = $body['csrf'] ?? $gabi->csrf;

echo "Calificaciones\n";
$profileOf = fn(Client $c, string $id) => $c->get('profile', "&id=$id")[1];
check('calificar pide cuenta', $anon->post('rate', ['user' => $juan['id'], 'stars' => 5])[0] === 401);
check('no te podés calificar a vos mismo', $driver->post('rate', ['user' => $juan['id'], 'stars' => 5])[0] === 400);
foreach (['0' => 0, '6' => 6, 'texto' => '5', 'con decimales' => 4.5, 'vacío' => null] as $label => $stars) {
    check("rechaza estrellas: $label", $gabi->post('rate', ['user' => $juan['id'], 'stars' => $stars])[0] === 400);
}
check('rechaza comentarios muy largos', $gabi->post('rate', ['user' => $juan['id'], 'stars' => 5, 'comment' => str_repeat('a', 301)])[0] === 400);
check('no se califica a quien no existe', $gabi->post('rate', ['user' => 'nadie', 'stars' => 5])[0] === 404);
check('calificar con estrellas y comentario', $gabi->post('rate', ['user' => $juan['id'], 'stars' => 5, 'comment' => 'Muy puntual.'])[0] === 200);
check('otra persona califica', $other->post('rate', ['user' => $juan['id'], 'stars' => 3])[0] === 200);
check('calificar de nuevo reemplaza la anterior', $gabi->post('rate', ['user' => $juan['id'], 'stars' => 4, 'comment' => 'Puntual, buena charla.'])[0] === 200);
$profile = $profileOf($gabi, $juan['id']);
check('el perfil muestra el promedio y la cantidad', $profile['profile']['rating'] === 3.5 && $profile['profile']['ratings'] === 2, $profile['profile']);
$review = $find($profile['reviews'], 'rater_name', 'Gabriela Gómez');
check('el perfil lista las opiniones con nombre, estrellas y comentario', count($profile['reviews']) === 2
    && $review['comment'] === 'Puntual, buena charla.' && $review['stars'] === 4 && $review['rater_id'] === $gabiId, $profile['reviews']);
check('cada uno ve su propia calificación', $profile['mine'] === ['stars' => 4, 'comment' => 'Puntual, buena charla.'] && $profileOf($anon, $juan['id'])['mine'] === null, $profile['mine']);
check('el perfil no muestra correos', !str_contains(json_encode($profile), '@'), $profile);
$listing = $anon->get('trips')[1]['items'];
$juanTrip = $find($listing, 'driver_id', $juan['id']);
$othersRated = array_filter($listing, fn($t) => $t['driver_id'] !== $juan['id'] && $t['driver_ratings'] > 0);
check('los viajes muestran las estrellas del conductor (y solo las suyas)', $juanTrip['driver_rating'] === 3.5 && $juanTrip['driver_ratings'] === 2 && !$othersRated, $juanTrip);
$admin->post('admin_user', ['id' => $ana['id'], 'action' => 'block', 'days' => 1, 'reason' => 'otro']);
check('las calificaciones de una cuenta suspendida no cuentan', $profileOf($anon, $juan['id'])['profile']['ratings'] === 1);
$admin->post('admin_user', ['id' => $ana['id'], 'action' => 'unblock']);
check('al levantar la suspensión vuelven a contar', $profileOf($anon, $juan['id'])['profile']['ratings'] === 2);
$code = $other->post('rate_delete', ['user' => $juan['id']])[0];
$after = $profileOf($anon, $juan['id'])['profile'];
check('borrar la propia calificación', $code === 200 && $after['rating'] == 4 && $after['ratings'] === 1, $after);
check('los usuarios comunes no ven todas las calificaciones', $gabi->get('admin_ratings')[0] === 403);
$rating = $find($admin->get('admin_ratings')[1]['items'], 'rater_id', $gabiId);
check('administración ve quién calificó a quién', $rating['rated_id'] === $juan['id'] && $rating['rater_email'] === 'gabi.gomez@gmail.com' && $rating['stars'] === 4, $rating);
check('administración borra una calificación', $admin->post('admin_rating', ['id' => $rating['id'], 'action' => 'delete'])[0] === 200
    && $admin->post('admin_rating', ['id' => $rating['id'], 'action' => 'delete'])[0] === 404);
$profile = $profileOf($anon, $juan['id']);
check('sin calificaciones el promedio queda vacío', $profile['profile']['rating'] === null && $profile['profile']['ratings'] === 0 && $profile['reviews'] === [], $profile);
check('administración ve el promedio de cada usuario', array_key_exists('user_rating', $find($admin->get('admin_users')[1]['items'], 'id', $juan['id'])));
[$code, $body] = $driver->post('trip_create', [...$trip, 'driver' => 'Otro Nombre']);
check('el viaje sale con el nombre de la cuenta', $code === 201 && $find($anon->get('trips')[1]['items'], 'id', $body['id'] ?? '')['driver'] === 'Juan');
$admin->post('admin_user', ['id' => $juan['id'], 'action' => 'block', 'days' => 7, 'reason' => 'otro']);
check('no se califica a una cuenta suspendida', $gabi->post('rate', ['user' => $juan['id'], 'stars' => 1])[0] === 404
    && $anon->get('profile', '&id=' . $juan['id'])[0] === 404);
$admin->post('admin_user', ['id' => $juan['id'], 'action' => 'unblock']);
[, $body] = $driver->post('login', ['email' => 'juan@example.com', 'password' => 'una-clave-de-prueba']);
$driver->csrf = $body['csrf'] ?? $driver->csrf;

echo "Fotos de perfil\n";
$png = tempnam(sys_get_temp_dir(), 'foto') . '.png';
$image = imagecreatetruecolor(900, 600);
imagesavealpha($image, true);
imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
imagefilledellipse($image, 450, 300, 300, 300, imagecolorallocate($image, 20, 80, 200));
imagepng($image, $png);
$notPhoto = tempnam(sys_get_temp_dir(), 'falsa');
file_put_contents($notPhoto, 'esto no es una foto');
$storedPhotos = fn() => array_values(array_diff(scandir("$data/fotos") ?: [], ['.', '..']));
$headersOf = function (string $path) use ($base): string {
    $ch = curl_init("$base/$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false]);
    $response = (string)curl_exec($ch);
    return substr($response, 0, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
};
check('subir foto pide cuenta', $anon->upload('photo_upload', [], ['photo' => $png])[0] === 401);
check('rechaza archivos que no son fotos', $gabi->upload('photo_upload', [], ['photo' => $notPhoto])[0] === 400);
check('rechaza el pedido sin foto', $gabi->upload('photo_upload', [], [])[0] === 400);
[$code, $body] = $gabi->upload('photo_upload', [], ['photo' => $png]);
$gabiPhoto = $body['user']['photo'] ?? '';
check('subir una foto', $code === 200 && preg_match('#^api\.php\?a=photo&f=[a-f0-9]{32}\.jpg$#', $gabiPhoto), $body);
[$code, $jpeg, $type] = $anon->bytes($gabiPhoto);
$size = @getimagesizefromstring((string)$jpeg);
check('la foto se ve sin cuenta, cuadrada y achicada', $code === 200 && $type === 'image/jpeg' && $size[0] === 320 && $size[1] === 320, [$code, $type, $size]);
$corner = imagecolorat(imagecreatefromstring($jpeg), 2, 2);
check('el fondo transparente queda blanco', (($corner >> 16) & 0xFF) > 240 && ($corner & 0xFF) > 240, dechex($corner));
$headers = $headersOf($gabiPhoto);
check('la foto no abre sesión y se guarda en el navegador', !stripos($headers, 'set-cookie') && stripos($headers, 'max-age=31536000'), $headers);
check('las fotos quedan fuera de la carpeta pública', count($storedPhotos()) === 1 && !is_dir("$root/fotos"), $storedPhotos());
check('no se puede pedir cualquier archivo', $anon->bytes('api.php?a=photo&f=../viajes.sqlite')[0] === 404
    && $anon->bytes('api.php?a=photo&f=' . str_repeat('0', 32) . '.jpg')[0] === 404);
check('la sesión trae la foto', $gabi->get('session')[1]['user']['photo'] === $gabiPhoto);
$gabi->post('rate', ['user' => $juan['id'], 'stars' => 5]);
$review = $find($profileOf($anon, $juan['id'])['reviews'], 'rater_id', $gabiId);
check('las opiniones muestran la foto de quien opina', ($review['rater_photo'] ?? null) === $gabiPhoto, $review);
check('el perfil público muestra la foto', $profileOf($anon, $gabiId)['profile']['photo'] === $gabiPhoto);
[, $body] = $driver->upload('photo_upload', [], ['photo' => $png]);
$juanPhoto = $body['user']['photo'];
$juanTrip = $find($anon->get('trips')[1]['items'], 'driver_id', $juan['id']);
check('los viajes muestran la foto del conductor', $juanTrip['driver_photo'] === $juanPhoto && $juanPhoto !== $gabiPhoto, $juanTrip);
[, $body] = $driver->upload('photo_upload', [], ['photo' => $png]);
check('cambiar la foto borra la anterior', $body['user']['photo'] !== $juanPhoto && $anon->bytes($juanPhoto)[0] === 404 && count($storedPhotos()) === 2, $storedPhotos());
$juanPhoto = $body['user']['photo'];
$admin->post('admin_user', ['id' => $juan['id'], 'action' => 'block', 'days' => 1, 'reason' => 'otro']);
check('la foto de una cuenta suspendida no se muestra', $anon->bytes($juanPhoto)[0] === 404);
$admin->post('admin_user', ['id' => $juan['id'], 'action' => 'unblock']);
[, $body] = $driver->post('login', ['email' => 'juan@example.com', 'password' => 'una-clave-de-prueba']);
$driver->csrf = $body['csrf'] ?? $driver->csrf;
check('administración ve la foto de cada usuario', $find($admin->get('admin_users')[1]['items'], 'id', $juan['id'])['photo'] === $juanPhoto);
check('administración quita una foto', $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'remove_photo'])[0] === 200
    && $driver->get('session')[1]['user']['photo'] === null && $anon->bytes($juanPhoto)[0] === 404 && count($storedPhotos()) === 1);
check('quitar la propia foto', $gabi->post('photo_delete', [])[0] === 200 && $gabi->get('session')[1]['user']['photo'] === null && $storedPhotos() === []);
check('la cuenta de Google tiene foto de Google para usar', $gabi->get('session')[1]['user']['googlePicture'] === true
    && $driver->get('session')[1]['user']['googlePicture'] === false);
[$code, $body] = $gabi->post('photo_google', []);
$size = @getimagesizefromstring((string)$anon->bytes($body['user']['photo'] ?? '')[1]);
check('usar la foto de Google (pide la grande y la recorta)', $code === 200 && $size[0] === 320 && $size[1] === 320, [$code, $body, $size]);
check('sin foto de Google no se puede usar ese botón', $driver->post('photo_google', [])[0] === 404);
[, $body] = (new Client($base))->post('google', ['credential' => $googleToken(['sub' => '424242', 'email' => 'otro.google@gmail.com', 'picture' => 'https://evil.example.com/x.jpg'])]);
check('no se acepta una foto de Google de otro sitio', ($body['user']['googlePicture'] ?? null) === false, $body);

[$code, $body] = $driver->post('logout', []);
$driver->csrf = $body['csrf'];
check('cerrar sesión', $code === 200 && $driver->get('session')[1]['user'] === null);
check('limita denuncias seguidas', count(array_filter(array_map(fn() => $anon->post('report', ['trip' => 'x', 'reason' => 'otro'])[0], range(1, 12)), fn($c) => $c === 429)) >= 1);
check('contraseña incorrecta', $driver->post('login', ['email' => 'juan@example.com', 'password' => 'otra-clave-mala'])[0] === 401);
[$code, $body] = $driver->post('login', ['email' => 'JUAN@example.com', 'password' => 'una-clave-de-prueba']);
check('entrar de nuevo', $code === 200 && $body['user']['email'] === 'juan@example.com', $body);

check('estado del servidor', $anon->get('health')[1]['ok'] === true);
check('acción desconocida', $anon->get('nada')[0] === 404);
[$code, $html] = $anon->raw('');
check('la página principal carga', $code === 200 && str_contains($html, 'Asiento Vacío'));
preg_match_all('/(?:src|href)="([^"#]+)"/', $html, $assets);
foreach (array_unique($assets[1]) as $asset) {
    check("existe $asset", $anon->raw(strtok($asset, '?'))[0] === 200);
}
check('los datos quedan fuera de la carpeta pública', is_file("$data/viajes.sqlite") && !is_file("$root/viajes.sqlite"));

echo $failures ? "\n$failures prueba(s) fallaron.\n" : "\nTodas las pruebas pasaron.\n";
exit($failures ? 1 : 0);
