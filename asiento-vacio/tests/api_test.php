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
        'email' => 'Gabi.Gomez@gmail.com', 'email_verified' => true, 'name' => 'Gabriela Gómez',
        'iat' => time(), 'exp' => time() + 3600, ...$claims,
    ], fn($v) => $v !== null);
    $head = $b64u(json_encode(['alg' => 'RS256', 'kid' => 'clave-prueba', 'typ' => 'JWT', ...$header]));
    $body = $b64u(json_encode($claims));
    openssl_sign("$head.$body", $signature, $key ?? $googleKey, OPENSSL_ALGO_SHA256);
    return "$head.$body." . $b64u($signature);
};
// Otra foto de Google, para cuando alguien cambia la suya.
$otherPhoto = imagecreatetruecolor(500, 500);
imagefill($otherPhoto, 0, 0, imagecolorallocate($otherPhoto, 30, 60, 210));
imagejpeg($otherPhoto, "$pictures/otra=s400-c");
define('OTHER_PICTURE_URL', "http://127.0.0.1:$picturePort/otra=s96-c");

$env = [...getenv(), 'ASIENTO_DATA_DIR' => $data, 'ASIENTO_ADMIN_CODE_SHA256' => hash('sha256', 'TESTCODE1234'),
    'ASIENTO_GOOGLE_CERTS_URL' => $certs, 'ASIENTO_GOOGLE_PICTURE_PATTERN' => "#^http://127\\.0\\.0\\.1:$picturePort/#"];

// Base con el formato anterior, como la que ya puede haber en el hosting: cuentas con contraseña
// (de antes de que se entrara solo con Google) y restos de la verificación con DNI que se quitó.
mkdir("$data/sesiones", 0700, true);
mkdir("$data/verificaciones", 0700);
file_put_contents("$data/verificaciones/" . str_repeat('ab', 16) . '.jpg', 'foto vieja de un DNI');
$legacy = new PDO("sqlite:$data/viajes.sqlite");
$legacy->exec('CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE, name TEXT NOT NULL, password TEXT NOT NULL, created INTEGER NOT NULL)');
$addOld = $legacy->prepare('INSERT INTO users VALUES (?,?,?,?,?)');
foreach ([['cuenta-vieja', 'vieja@example.com', 'Cuenta vieja'], ['cuenta-gmail', 'lore.viejo@gmail.com', 'Lore viejo'],
    ['cuenta-antigua', 'antigua@hotmail.com', 'Antigua']] as [$id, $email, $name]) {
    $addOld->execute([$id, $email, $name, password_hash('una-clave-de-prueba', PASSWORD_DEFAULT), time()]);
}
$legacy->exec("CREATE TABLE verifications (id TEXT PRIMARY KEY, user TEXT, status TEXT, reason TEXT, files TEXT, created INTEGER, decided INTEGER)");
$legacy->exec("INSERT INTO verifications VALUES ('v1', 'cuenta-vieja', 'pending', '', '[\"ab.jpg\"]', 1, 0)");
$legacy = null;
// Una sesión que quedó abierta con contraseña en la versión anterior.
$oldSession = bin2hex(random_bytes(16));
$oldCsrf = bin2hex(random_bytes(32));
file_put_contents("$data/sesiones/sess_$oldSession", 'uid|s:14:"cuenta-antigua";csrf|s:64:"' . $oldCsrf . '";');

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

    // Usa una sesión ya guardada en el servidor (por ejemplo, una de la versión anterior).
    public function useSession(string $id, string $csrf): void
    {
        file_put_contents($this->jar, "127.0.0.1\tFALSE\t/\tFALSE\t0\tasiento\t$id\n");
        $this->csrf = $csrf;
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
}

$failures = 0;
function check(string $name, bool $ok, mixed $detail = null): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok ? '' : ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    $failures += $ok ? 0 : 1;
}

// Entrar con Google (con un comprobante firmado por la clave de prueba).
$signIn = function (Client $client, array $claims = []) use ($googleToken): array {
    $result = $client->post('google', ['credential' => $googleToken($claims)]);
    $client->csrf = $result[1]['csrf'] ?? $client->csrf;
    return $result;
};
$db = new PDO("sqlite:$data/viajes.sqlite");
// Todas las pruebas salen de la misma conexión: se vacían los límites entre partes para no chocar con ellos.
$resetLimits = fn() => $db->exec('DELETE FROM limits');

date_default_timezone_set('America/Argentina/Buenos_Aires');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$trip = [
    'origin' => 'González Catán', 'destination' => 'Córdoba', 'day' => $tomorrow, 'hour' => '07:30',
    'seats' => 3, 'driver' => 'Juan', 'phone' => '54 9 11 2345 6789', 'vehicle' => 'Fiat Cronos gris',
    'notes' => "Salgo desde la plaza.\nLlevo mate.", 'confirmPrivate' => true, 'confirmPhone' => true,
];
$find = fn(array $items, string $key, string $value) => array_values(array_filter($items, fn($i) => $i[$key] === $value))[0] ?? [];

echo "Asiento Vacío: pruebas\n";
$anon = new Client($base);
[$code, $body] = $anon->get('session');
check('la sesión entrega token y fecha', $code === 200 && strlen($body['csrf']) === 64 && $body['today'] === date('Y-m-d') && $body['user'] === null, $body);
[$code, $body] = $anon->post('google', [], 'malo');
check('sin token CSRF se rechaza', $code === 403 && $body['csrf'] === true, $body);
check('publicar sin cuenta pide iniciar sesión', $anon->post('trip_create', $trip)[0] === 401);
check('mis viajes sin cuenta pide iniciar sesión', $anon->get('trips', '&mine=1')[0] === 401);
[$code, $body] = $anon->post('login', ['email' => 'vieja@example.com', 'password' => 'una-clave-de-prueba']);
check('ya no se entra con contraseña', $code === 410 && str_contains($body['error'], 'Google'), $body);
check('ni se crean cuentas con contraseña', $anon->post('register', ['email' => 'x@example.com', 'password' => 'una-clave-de-prueba', 'name' => 'X'])[0] === 410);

echo "Primera configuración del ingreso con Google\n";
check('sin el ID de Google nadie puede entrar', $anon->get('session')[1]['googleClientId'] === ''
    && $anon->post('google', ['credential' => $googleToken()])[0] === 404);
check('configurarlo pide el código de administración', $anon->post('setup', ['code' => 'AAAA-BBBB-CCCC-DDDD', 'googleClientId' => CLIENT_ID])[0] === 403);
check('y un ID de cliente bien copiado', $anon->post('setup', ['code' => 'test-code-1234', 'googleClientId' => 'mi-id-de-google'])[0] === 400);
[$code, $body] = $anon->post('setup', ['code' => 'test-code-1234', 'googleClientId' => ' ' . CLIENT_ID . ' ']);
check('con el código se activa el ingreso con Google', $code === 200 && $body['googleClientId'] === CLIENT_ID
    && $anon->get('session')[1]['googleClientId'] === CLIENT_ID, $body);
check('ya configurado, no se cambia desde Entrar', $anon->post('setup', ['code' => 'test-code-1234',
    'googleClientId' => '98765432100-otroproyecto1.apps.googleusercontent.com'])[0] === 409);

echo "Cuentas y viajes\n";
$driver = new Client($base);
[$code, $body] = $signIn($driver, ['sub' => 'g-juan', 'email' => 'juan@gmail.com', 'name' => 'Juan']);
$juanId = $body['user']['id'] ?? '';
check('con Google se crea la cuenta sola', $code === 200 && $body['new'] === true && $body['user']['name'] === 'Juan'
    && $body['user']['google'] === true && $body['user']['admin'] === false && $body['user']['photo'] === null, $body);
[$code, $body] = $signIn(new Client($base), ['sub' => 'g-juan', 'email' => 'juan@gmail.com', 'name' => 'Otro Nombre']);
check('la segunda vez entra a la misma cuenta y conserva el nombre', $code === 200 && $body['new'] === false
    && $body['user']['id'] === $juanId && $body['user']['name'] === 'Juan', $body);
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
$signIn($busy, ['sub' => 'g-flota', 'email' => 'flota@gmail.com', 'name' => 'Flota']);
$codes = array_map(fn() => $busy->post('trip_create', $trip)[0], range(1, 11));
check('limita a 10 viajes publicados por hora', array_count_values($codes) === [201 => 10, 429 => 1], $codes);

$other = new Client($base);
$signIn($other, ['sub' => 'g-ana', 'email' => 'ana@gmail.com', 'name' => 'Ana']);
check('otro usuario no puede editar', $other->post('trip_update', ['id' => $tripId, 'status' => 'full'])[0] === 403);
check('otro usuario no puede borrar', $other->post('trip_delete', ['id' => $tripId])[0] === 403);

check('el dueño cambia los asientos', $driver->post('trip_update', ['id' => $tripId, 'seats' => 2])[0] === 200);
check('no acepta asientos fuera de rango', $driver->post('trip_update', ['id' => $tripId, 'seats' => 12])[0] === 400);
check('no acepta estados inventados', $driver->post('trip_update', ['id' => $tripId, 'status' => 'deleted'])[0] === 400);
check('el dueño lo marca completo', $driver->post('trip_update', ['id' => $tripId, 'status' => 'full'])[0] === 200);
$ids = array_column($anon->get('trips')[1]['items'], 'id');
check('un viaje completo no aparece en la lista', !in_array($tripId, $ids, true), $ids);
$mine = $driver->get('trips', '&mine=1')[1]['items'];
$own = $find($mine, 'id', $tripId);
check('sigue en mis viajes con 2 asientos', ($own['status'] ?? '') === 'full' && $own['seats'] === 2 && $own['mine'] === true, $own);
check('el dueño lo vuelve a publicar', $driver->post('trip_update', ['id' => $tripId, 'status' => 'active'])[0] === 200);

$db->prepare("UPDATE trips SET day=? WHERE id=?")->execute([date('Y-m-d', strtotime('-1 day')), $tripId]);
$ids = array_column($anon->get('trips')[1]['items'], 'id');
check('los viajes pasados desaparecen solos', !in_array($tripId, $ids, true), $ids);
$db->prepare("UPDATE trips SET day=? WHERE id=?")->execute([$tomorrow, $tripId]);
$publicIds = fn() => array_column($anon->get('trips')[1]['items'], 'id');

echo "Administración\n";
$admin = new Client($base);
$signIn($admin, ['sub' => 'g-duena', 'email' => 'duena@gmail.com', 'name' => 'Dueña']);
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
check('la denuncia llega a administración', ($report['trip'] ?? '') === $tripId && $report['details'] === 'Me pidió plata' && $report['owner_email'] === 'juan@gmail.com', $report);
$overview = $admin->get('admin_overview')[1];
check('el resumen cuenta denuncias y usuarios', $overview['reports'] === 1 && $overview['drivers'] === 7 && $overview['upcoming'] >= 1, $overview);
check('descartar una denuncia', $admin->post('admin_report', ['id' => $report['id'], 'action' => 'dismiss'])[0] === 200 && $admin->get('admin_reports')[1]['items'] === []);
$anon->post('report', ['trip' => $tripId, 'reason' => 'datos']);
$row = $find($admin->get('admin_trips')[1]['items'], 'id', $tripId);
check('la lista de viajes muestra denuncias y correo', $row['reports'] === 1 && $row['owner_email'] === 'juan@gmail.com', $row);

$users = $admin->get('admin_users')[1]['items'];
$juan = $find($users, 'email', 'juan@gmail.com');
$me = $find($users, 'email', 'duena@gmail.com');
$ana = $find($users, 'email', 'ana@gmail.com');
check('la lista de usuarios cuenta sus viajes', $juan['trips'] === 2 && $me['is_admin'] === 1, [$juan, $me]);
check('suspender pide plazo y motivo', $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'block'])[0] === 400
    && $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'block', 'days' => 3, 'reason' => 'precio'])[0] === 400
    && $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'block', 'days' => 7, 'reason' => 'inventado'])[0] === 400);
check('suspender 7 días por cobrar', $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'block', 'days' => 7, 'reason' => 'precio'])[0] === 200);
$juanRow = $find($admin->get('admin_users')[1]['items'], 'id', $juan['id']);
check('administración ve hasta cuándo y por qué', abs($juanRow['blocked_until'] - (time() + 7 * 86400)) < 60
    && $juanRow['blocked_reason'] === 'Cobró un precio por el viaje o lo usó como negocio.', $juanRow);
check('los viajes de un bloqueado no se ven', !in_array($tripId, $publicIds(), true));
check('un bloqueado pierde la sesión', $driver->get('session')[1]['user'] === null);
$juanClaims = ['sub' => 'g-juan', 'email' => 'juan@gmail.com', 'name' => 'Juan'];
[$code, $body] = $signIn($driver, $juanClaims);
check('al entrar con Google ve hasta cuándo y el motivo', $code === 403
    && $body['error'] === 'Tu cuenta está suspendida hasta el ' . date('d/m/Y \\a \\l\\a\\s H:i', $juanRow['blocked_until']) . '. Motivo: Cobró un precio por el viaje o lo usó como negocio.', $body);
check('levantar la suspensión', $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'unblock'])[0] === 200
    && $find($admin->get('admin_users')[1]['items'], 'id', $juan['id'])['blocked_until'] === 0);
check('desbloqueado, sus viajes vuelven', in_array($tripId, $publicIds(), true));
check('desbloqueado, puede entrar', $signIn($driver, $juanClaims)[0] === 200);
check('no podés suspenderte a vos mismo', $admin->post('admin_user', ['id' => $me['id'], 'action' => 'block', 'days' => 7, 'reason' => 'otro'])[0] === 400);
$flota = $find($users, 'email', 'flota@gmail.com');
$flotaClaims = ['sub' => 'g-flota', 'email' => 'flota@gmail.com', 'name' => 'Flota'];
$admin->post('admin_user', ['id' => $flota['id'], 'action' => 'block', 'days' => 0, 'reason' => 'denuncias']);
[$code, $body] = $signIn(new Client($base), $flotaClaims);
check('suspensión sin fecha', $code === 403 && $body['error'] === 'Tu cuenta está suspendida. Motivo: Recibió varias denuncias.', $body);
$admin->post('admin_user', ['id' => $flota['id'], 'action' => 'block', 'days' => 1, 'reason' => 'otro']);
$db->prepare('UPDATE users SET blocked_until=? WHERE id=?')->execute([time() - 5, $flota['id']]);
[$code, $body] = $signIn(new Client($base), $flotaClaims);
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
$tables = array_column($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
check('se borró la tabla de verificaciones', !in_array('verifications', $tables, true), $tables);
check('la verificación ya no existe', $driver->get('verification')[0] === 404 && $admin->get('admin_verifications')[0] === 404
    && $admin->bytes('api.php?a=admin_photo&file=' . str_repeat('ab', 16) . '.jpg')[0] === 404);

echo "Ingreso con Google\n";
$resetLimits();
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
[$code, $body] = $signIn($gabi, ['picture' => PICTURE_URL]);
$gabiId = $body['user']['id'] ?? '';
$gabiPhoto = $body['user']['photo'] ?? '';
check('la cuenta toma nombre y correo de Google', $code === 200 && $body['user']['name'] === 'Gabriela Gómez'
    && $body['user']['email'] === 'gabi.gomez@gmail.com' && $body['user']['google'] === true, $body);
[, $body] = $signIn(new Client($base), ['sub' => '888', 'email' => 'juan.perez.1985@gmail.com', 'name' => 'J']);
check('si Google no da un nombre, no se usa el correo', ($body['user']['name'] ?? '') === 'Usuario de Google', $body);
[, $body] = $signIn(new Client($base), ['sub' => '889', 'email' => 'pato.99@gmail.com', 'name' => null, 'given_name' => 'Pato']);
check('o se usa el nombre de pila', ($body['user']['name'] ?? '') === 'Pato', $body);
check('otra cuenta de Google con el mismo correo no entra', $signIn(new Client($base), ['sub' => '777', 'email' => 'juan@gmail.com'])[0] === 409);
$admin->post('admin_user', ['id' => $gabiId, 'action' => 'block', 'days' => 7, 'reason' => 'conducta']);
[$code, $body] = $signIn(new Client($base));
check('una cuenta suspendida no entra', $code === 403 && str_contains($body['error'], 'Motivo: Tuvo una conducta'), $body);
$admin->post('admin_user', ['id' => $gabiId, 'action' => 'unblock']);
$signIn($gabi, ['picture' => PICTURE_URL]);
$resetLimits();
$codes = array_map(fn() => (new Client($base))->post('google', ['credential' => 'no-es-un-comprobante'])[0], range(1, 31));
check('limita los intentos de entrar seguidos', end($codes) === 429 && count(array_filter($codes, fn($c) => $c === 401)) === 30, $codes);
$resetLimits();

echo "Ajustes\n";
check('solo administración cambia los ajustes', $other->post('admin_settings', ['googleClientId' => CLIENT_ID])[0] === 403
    && $anon->get('admin_settings')[0] === 401);
check('rechaza un ID de cliente mal copiado', $admin->post('admin_settings', ['googleClientId' => 'mi-id-de-google'])[0] === 400);
[$code, $body] = $admin->post('admin_settings', ['googleClientId' => '']);
check('no se puede borrar el ID (nadie podría entrar)', $code === 400 && str_contains($body['error'], 'nadie podría entrar')
    && $anon->get('session')[1]['googleClientId'] === CLIENT_ID, $body);
check('pero se puede cambiar por otro', $admin->post('admin_settings', ['googleClientId' => '98765432100-otroproyecto1.apps.googleusercontent.com'])[0] === 200
    && $admin->get('admin_settings')[1]['googleClientId'] === '98765432100-otroproyecto1.apps.googleusercontent.com'
    && $admin->post('admin_settings', ['googleClientId' => ' ' . CLIENT_ID . ' '])[0] === 200
    && $anon->get('session')[1]['googleClientId'] === CLIENT_ID);

echo "Cuentas de antes, con contraseña\n";
check('una cuenta vieja con Gmail pasa sola a Google', ($signIn(new Client($base), ['sub' => 'g-lore', 'email' => 'lore.viejo@gmail.com',
    'name' => 'Lorenzo'])[1]['user']['id'] ?? '') === 'cuenta-gmail');
$veraClaims = ['sub' => 'g-vera', 'email' => 'vieja@example.com', 'name' => 'Vera'];
[$code, $body] = $signIn(new Client($base), $veraClaims);
check('con otro correo, sin permiso no se une', $code === 409 && str_contains($body['error'], 'administración'), $body);
check('tampoco con un dominio de Google Workspace que no es el del correo', $signIn(new Client($base), [...$veraClaims, 'hd' => 'otro.com'])[0] === 409);
[$code, $body] = $signIn(new Client($base), [...$veraClaims, 'hd' => 'Example.com']);
check('si Google administra ese dominio, se une con el nombre de Google', $code === 200 && $body['user']['id'] === 'cuenta-vieja'
    && $body['user']['name'] === 'Vera' && $body['user']['google'] === true, $body);
$antigua = new Client($base);
$antigua->useSession($oldSession, $oldCsrf);
check('una sesión abierta con contraseña antes de actualizar sigue andando', ($antigua->get('session')[1]['user']['id'] ?? '') === 'cuenta-antigua');
$antiguaClaims = ['sub' => 'g-antigua', 'email' => 'antigua@hotmail.com', 'name' => 'Antonia'];
check('una cuenta vieja de Hotmail no se une sola', $signIn(new Client($base), $antiguaClaims)[0] === 409);
$antiguaRow = $find($admin->get('admin_users')[1]['items'], 'id', 'cuenta-antigua');
check('administración ve las cuentas viejas', $antiguaRow['google'] === 0 && $antiguaRow['google_link_ok'] === 0, $antiguaRow);
check('solo administración da el permiso', $other->post('admin_user', ['id' => 'cuenta-antigua', 'action' => 'allow_google'])[0] === 403);
check('administración la deja pasar a Google', $admin->post('admin_user', ['id' => 'cuenta-antigua', 'action' => 'allow_google'])[0] === 200
    && $find($admin->get('admin_users')[1]['items'], 'id', 'cuenta-antigua')['google_link_ok'] === 1);
check('no hace falta para una cuenta que ya es de Google', $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'allow_google'])[0] === 400);
[$code, $body] = $signIn(new Client($base), $antiguaClaims);
check('con el permiso se une a la cuenta vieja', $code === 200 && $body['user']['id'] === 'cuenta-antigua' && $body['user']['name'] === 'Antonia', $body);
check('al unirla se cierra la sesión que había quedado abierta', $antigua->get('session')[1]['user'] === null
    && $antigua->post('trip_create', $trip)[0] === 401);
check('el permiso se usa una sola vez', $find($admin->get('admin_users')[1]['items'], 'id', 'cuenta-antigua')['google_link_ok'] === 0);
check('otra cuenta de Google con ese correo no entra', $signIn(new Client($base), [...$antiguaClaims, 'sub' => 'g-otra'])[0] === 409);

echo "Calificaciones\n";
$resetLimits();
$profileOf = fn(Client $c, string $id) => $c->get('profile', "&id=$id")[1];
$rate = fn(Client $c, array $body) => $c->post('rate', ['user' => $juan['id'], 'role' => 'driver', 'stars' => 5, ...$body])[0];
check('calificar pide cuenta', $rate($anon, [])  === 401);
check('no te podés calificar a vos mismo', $rate($driver, []) === 400);
foreach (['0' => 0, '6' => 6, 'texto' => '5', 'con decimales' => 4.5, 'vacío' => null] as $label => $stars) {
    check("rechaza estrellas: $label", $rate($gabi, ['stars' => $stars]) === 400);
}
check('pide decir si manejaba o si viajó como acompañante', $rate($gabi, ['role' => null]) === 400 && $rate($gabi, ['role' => 'pasajero']) === 400);
check('rechaza comentarios muy largos', $rate($gabi, ['comment' => str_repeat('a', 301)]) === 400);
check('no se califica a quien no existe', $rate($gabi, ['user' => 'nadie']) === 404);
check('la acompañante califica al conductor', $rate($gabi, ['comment' => 'Muy puntual.']) === 200);
check('otra persona califica', $rate($other, ['stars' => 3]) === 200);
check('calificar de nuevo reemplaza la anterior', $rate($gabi, ['stars' => 4, 'comment' => 'Puntual, buena charla.']) === 200);
$profile = $profileOf($gabi, $juan['id']);
check('el perfil muestra el promedio como conductor', $profile['profile']['asDriver'] === ['rating' => 3.5, 'count' => 2]
    && $profile['profile']['asRider'] === ['rating' => null, 'count' => 0] && $profile['profile']['ratings'] === 2, $profile['profile']);
$review = $find($profile['reviews'], 'rater_name', 'Gabriela Gómez');
check('el perfil lista las opiniones con nombre, estrellas, rol y comentario', count($profile['reviews']) === 2
    && $review['comment'] === 'Puntual, buena charla.' && $review['stars'] === 4 && $review['role'] === 'driver' && $review['rater_id'] === $gabiId, $profile['reviews']);
check('cada uno ve su propia calificación', $profile['mine'] === ['stars' => 4, 'comment' => 'Puntual, buena charla.', 'role' => 'driver']
    && $profileOf($anon, $juan['id'])['mine'] === null, $profile['mine']);
check('el perfil no muestra correos', !str_contains(json_encode($profile), '@'), $profile);
check('el conductor califica a la acompañante', $driver->post('rate', ['user' => $gabiId, 'role' => 'rider', 'stars' => 5, 'comment' => 'Buena compañera de viaje.'])[0] === 200);
$gabiProfile = $profileOf($anon, $gabiId)['profile'];
check('la acompañante suma calificaciones como acompañante', $gabiProfile['asRider'] == ['rating' => 5, 'count' => 1]
    && $gabiProfile['asDriver']['count'] === 0, $gabiProfile);
check('alguien que viajó con Juan de acompañante también lo califica', $rate($busy, ['role' => 'rider', 'stars' => 1]) === 200);
$listing = $anon->get('trips')[1]['items'];
$juanTrip = $find($listing, 'driver_id', $juan['id']);
$othersRated = array_filter($listing, fn($t) => $t['driver_id'] !== $juan['id'] && $t['driver_ratings'] > 0);
check('los viajes muestran solo las estrellas como conductor', $juanTrip['driver_rating'] === 3.5 && $juanTrip['driver_ratings'] === 2 && !$othersRated, $juanTrip);
$profile = $profileOf($anon, $juan['id'])['profile'];
check('el perfil separa conductor y acompañante', $profile['asDriver']['count'] === 2 && $profile['asRider'] == ['rating' => 1, 'count' => 1]
    && $profile['ratings'] === 3, $profile);
$admin->post('admin_user', ['id' => $ana['id'], 'action' => 'block', 'days' => 1, 'reason' => 'otro']);
check('las calificaciones de una cuenta suspendida no cuentan', $profileOf($anon, $juan['id'])['profile']['asDriver']['count'] === 1);
$admin->post('admin_user', ['id' => $ana['id'], 'action' => 'unblock']);
$signIn($other, ['sub' => 'g-ana', 'email' => 'ana@gmail.com', 'name' => 'Ana']);
check('al levantar la suspensión vuelven a contar', $profileOf($anon, $juan['id'])['profile']['asDriver']['count'] === 2);
$code = $other->post('rate_delete', ['user' => $juan['id']])[0];
$after = $profileOf($anon, $juan['id'])['profile'];
check('borrar la propia calificación', $code === 200 && $after['asDriver'] == ['rating' => 4, 'count' => 1], $after);
check('los usuarios comunes no ven todas las calificaciones', $gabi->get('admin_ratings')[0] === 403);
$rating = $find($admin->get('admin_ratings')[1]['items'], 'rater_id', $gabiId);
check('administración ve quién calificó a quién y cómo viajaron', $rating['rated_id'] === $juan['id'] && $rating['rater_email'] === 'gabi.gomez@gmail.com'
    && $rating['stars'] === 4 && $rating['role'] === 'driver', $rating);
check('y si quien calificó entra con Google y desde cuándo tiene la cuenta', $rating['rater_google'] === 1 && $rating['rater_created'] > time() - 600, $rating);
check('administración borra una calificación', $admin->post('admin_rating', ['id' => $rating['id'], 'action' => 'delete'])[0] === 200
    && $admin->post('admin_rating', ['id' => $rating['id'], 'action' => 'delete'])[0] === 404);
$profile = $profileOf($anon, $juan['id']);
check('sin calificaciones como conductor el promedio queda vacío', $profile['profile']['asDriver'] === ['rating' => null, 'count' => 0]
    && count($profile['reviews']) === 1 && $profile['reviews'][0]['role'] === 'rider', $profile);
check('administración ve el promedio de cada usuario', array_key_exists('user_rating', $find($admin->get('admin_users')[1]['items'], 'id', $juan['id'])));
[$code, $body] = $driver->post('trip_create', [...$trip, 'driver' => 'Otro Nombre']);
$newTrip = $body['id'] ?? '';
check('el viaje sale con el nombre de la cuenta', $code === 201 && $find($anon->get('trips')[1]['items'], 'id', $newTrip)['driver'] === 'Juan');
$db->prepare('UPDATE trips SET driver=? WHERE id=?')->execute(['Nombre Escrito a Mano', $newTrip]);
check('los viajes viejos con otro nombre muestran el de la cuenta', $find($anon->get('trips')[1]['items'], 'id', $newTrip)['driver'] === 'Juan'
    && $find($admin->get('admin_trips')[1]['items'], 'id', $newTrip)['driver'] === 'Juan');
check('cambiar el nombre pide cuenta', $anon->post('profile_name', ['name' => 'Alguien'])[0] === 401);
check('rechaza un nombre muy corto', $driver->post('profile_name', ['name' => ' J '])[0] === 400);
[$code, $body] = $driver->post('profile_name', ['name' => '  Juan   Carlos ']);
check('cambiar el nombre', $code === 200 && $body['user']['name'] === 'Juan Carlos' && $profileOf($anon, $juan['id'])['profile']['name'] === 'Juan Carlos', $body);
check('los viajes salen con el nombre nuevo', $find($anon->get('trips')[1]['items'], 'driver_id', $juan['id'])['driver'] === 'Juan Carlos');
check('el nombre elegido no lo pisa Google al volver a entrar', ($signIn($driver, $juanClaims)[1]['user']['name'] ?? '') === 'Juan Carlos');
$driver->post('profile_name', ['name' => 'Juan']);
$admin->post('admin_user', ['id' => $juan['id'], 'action' => 'block', 'days' => 7, 'reason' => 'otro']);
check('no se califica a una cuenta suspendida', $rate($gabi, ['stars' => 1]) === 404 && $anon->get('profile', '&id=' . $juan['id'])[0] === 404);
$admin->post('admin_user', ['id' => $juan['id'], 'action' => 'unblock']);
$signIn($driver, $juanClaims);

echo "Fotos de perfil (las de Google)\n";
$resetLimits();
$storedPhotos = fn() => array_values(array_diff(scandir("$data/fotos") ?: [], ['.', '..']));
$headersOf = function (string $path) use ($base): string {
    $ch = curl_init("$base/$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false]);
    $response = (string)curl_exec($ch);
    return substr($response, 0, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
};
$colorOf = function (string $path) use ($anon): array {
    $image = @imagecreatefromstring((string)$anon->bytes($path)[1]);
    $pixel = $image ? imagecolorat($image, 160, 160) : 0;
    return [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF];
};
check('al entrar con Google la foto se pone sola', preg_match('#^api\.php\?a=photo&f=[a-f0-9]{32}\.jpg$#', $gabiPhoto) === 1
    && $gabi->get('session')[1]['user']['photo'] === $gabiPhoto, $gabiPhoto);
[$code, $jpeg, $type] = $anon->bytes($gabiPhoto);
$size = @getimagesizefromstring((string)$jpeg);
check('se ve sin cuenta, cuadrada y achicada (pide la grande a Google)', $code === 200 && $type === 'image/jpeg' && $size[0] === 320 && $size[1] === 320, [$code, $type, $size]);
check('es la foto de Google', $colorOf($gabiPhoto)[0] > 150, $colorOf($gabiPhoto));
$headers = $headersOf($gabiPhoto);
check('la foto no abre sesión y se guarda en el navegador', !stripos($headers, 'set-cookie') && stripos($headers, 'max-age=31536000'), $headers);
check('las fotos quedan fuera de la carpeta pública', count($storedPhotos()) === 1 && !is_dir("$root/fotos"), $storedPhotos());
check('no se puede pedir cualquier archivo', $anon->bytes('api.php?a=photo&f=../viajes.sqlite')[0] === 404
    && $anon->bytes('api.php?a=photo&f=' . str_repeat('0', 32) . '.jpg')[0] === 404);
check('ya no se suben fotos desde el teléfono', $gabi->post('photo_upload', [])[0] === 404);
check('sin foto en Google queda la inicial', $driver->get('session')[1]['user']['photo'] === null
    && $driver->get('session')[1]['user']['googlePicture'] === false && $driver->post('photo_google', [])[0] === 404);
$rate($gabi, ['stars' => 5]);
$review = $find($profileOf($anon, $juan['id'])['reviews'], 'rater_id', $gabiId);
check('las opiniones muestran la foto de quien opina', ($review['rater_photo'] ?? null) === $gabiPhoto, $review);
check('el perfil público muestra la foto', $profileOf($anon, $gabiId)['profile']['photo'] === $gabiPhoto);
check('administración ve la foto de cada usuario', $find($admin->get('admin_users')[1]['items'], 'id', $gabiId)['photo'] === $gabiPhoto);
check('quitar la propia foto', $gabi->post('photo_delete', [])[0] === 200 && $gabi->get('session')[1]['user']['photo'] === null
    && $anon->bytes($gabiPhoto)[0] === 404 && $storedPhotos() === []);
$photoAfterSignIn = fn(array $claims) => array_key_exists('photo', $user = $signIn($gabi, $claims)[1]['user'] ?? []) ? $user['photo'] : 'sin respuesta';
check('quitada, no vuelve sola al entrar con Google', $photoAfterSignIn(['picture' => PICTURE_URL]) === null && $storedPhotos() === []);
[$code, $body] = $gabi->post('photo_google', []);
$gabiPhoto = $body['user']['photo'] ?? '';
check('"Mostrar mi foto de Google" la vuelve a poner', $code === 200 && $gabiPhoto !== '' && $anon->bytes($gabiPhoto)[0] === 200 && count($storedPhotos()) === 1, $body);
$admin->post('admin_user', ['id' => $gabiId, 'action' => 'block', 'days' => 1, 'reason' => 'otro']);
check('la foto de una cuenta suspendida no se muestra', $anon->bytes($gabiPhoto)[0] === 404);
$admin->post('admin_user', ['id' => $gabiId, 'action' => 'unblock']);
[, $body] = $signIn($gabi, ['picture' => OTHER_PICTURE_URL]);
$newPhoto = $body['user']['photo'] ?? '';
check('si cambia la foto en Google, se actualiza al entrar', $newPhoto !== $gabiPhoto && $anon->bytes($gabiPhoto)[0] === 404
    && $colorOf($newPhoto)[2] > 150 && count($storedPhotos()) === 1, [$newPhoto, $colorOf($newPhoto)]);
check('administración quita una foto', $admin->post('admin_user', ['id' => $gabiId, 'action' => 'remove_photo'])[0] === 200
    && $gabi->get('session')[1]['user']['photo'] === null && $anon->bytes($newPhoto)[0] === 404 && $storedPhotos() === []);
check('la que quitó la administración tampoco vuelve sola', $photoAfterSignIn(['picture' => PICTURE_URL]) === null && $storedPhotos() === []);
[$code, $body] = $signIn(new Client($base), ['sub' => '424242', 'email' => 'otro.google@gmail.com', 'picture' => 'https://evil.example.com/x.jpg']);
check('no se acepta una foto de Google de otro sitio', $code === 200 && $body['user']['googlePicture'] === false && $body['user']['photo'] === null, $body);
[$code, $body] = $signIn(new Client($base), ['sub' => '424243', 'email' => 'sin.foto@gmail.com', 'picture' => "http://127.0.0.1:$picturePort/no-existe=s96-c"]);
check('si no se puede traer la foto, igual entra', $code === 200 && $body['user']['photo'] === null && $body['user']['googlePicture'] === true, $body);

$resetLimits();
[$code, $body] = $driver->post('logout', []);
$driver->csrf = $body['csrf'];
check('cerrar sesión', $code === 200 && $driver->get('session')[1]['user'] === null);
check('limita denuncias seguidas', count(array_filter(array_map(fn() => $anon->post('report', ['trip' => 'x', 'reason' => 'otro'])[0], range(1, 12)), fn($c) => $c === 429)) >= 1);
check('estado del servidor', $anon->get('health')[1]['ok'] === true);
check('acción desconocida', $anon->get('nada')[0] === 404);
[$code, $html] = $anon->raw('');
check('la página principal carga', $code === 200 && str_contains($html, 'Asiento Vacío'));
preg_match_all('/(?:src|href)="([^"#]+)"/', $html, $assets);
foreach (array_unique($assets[1]) as $asset) {
    check("existe $asset", $anon->raw(strtok($asset, '?'))[0] === 200);
}
check('los datos quedan fuera de la carpeta pública', is_file("$data/viajes.sqlite") && !is_file("$root/viajes.sqlite"));

echo "Actualización con varias visitas a la vez\n";
// Justo después de subir la versión nueva, varios pedidos pueden encontrar la base vieja al mismo tiempo:
// 10 procesos abren a la vez una base del formato anterior y todos tienen que poder usarla (20 veces).
$worker = <<<'PHP'
[, $api, $dir, $at] = $argv;
$src = file_get_contents($api);
$start = strpos($src, 'function connect(');
eval(substr($src, $start, strpos($src, 'function start_session(') - $start));
time_sleep_until((float)$at);
try {
    connect($dir);
    echo 'ok';
} catch (Throwable $error) {
    echo $error->getMessage();
}
PHP;
$errors = [];
for ($round = 0; $round < 20; $round++) {
    $oldData = "$data-vieja-$round";
    mkdir($oldData);
    $old = new PDO("sqlite:$oldData/viajes.sqlite");
    $old->exec('CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE, name TEXT NOT NULL, password TEXT NOT NULL, created INTEGER NOT NULL)');
    $old->exec('CREATE TABLE verifications (id TEXT PRIMARY KEY)');
    $old = null;
    $at = (string)(microtime(true) + 0.3);
    $procs = [];
    for ($i = 0; $i < 10; $i++) {
        $procs[] = proc_open([PHP_BINARY, '-r', $worker, '--', "$root/api.php", $oldData, $at], [1 => ['pipe', 'w']], $out);
        $outs[] = $out[1];
    }
    foreach ($outs as $out) {
        $result = stream_get_contents($out);
        if ($result !== 'ok') {
            $errors[] = $result;
        }
    }
    array_map('proc_close', $procs);
    $outs = [];
    exec('rm -rf ' . escapeshellarg($oldData));
}
check('10 visitas a la vez actualizan la base sin errores', $errors === [], $errors);

echo $failures ? "\n$failures prueba(s) fallaron.\n" : "\nTodas las pruebas pasaron.\n";
exit($failures ? 1 : 0);
