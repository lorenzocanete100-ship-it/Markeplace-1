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
$env = [...getenv(), 'ASIENTO_DATA_DIR' => $data, 'ASIENTO_ADMIN_CODE_SHA256' => hash('sha256', 'TESTCODE1234')];

// Base con el formato anterior (sin administración), como la que ya puede haber en el hosting.
mkdir("$data/sesiones", 0700, true);
$legacy = new PDO("sqlite:$data/viajes.sqlite");
$legacy->exec('CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE, name TEXT NOT NULL, password TEXT NOT NULL, created INTEGER NOT NULL)');
$legacy->prepare('INSERT INTO users VALUES (?,?,?,?,?)')->execute(['cuenta-vieja', 'vieja@example.com', 'Cuenta vieja', password_hash('una-clave-de-prueba', PASSWORD_DEFAULT), time()]);
$legacy = null;

$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
register_shutdown_function(function () use ($server, $data) {
    proc_terminate($server);
    exec('rm -rf ' . escapeshellarg($data));
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

echo "Verificación de perfiles\n";
$picture = function (int $width = 2400, int $height = 1500): string {
    $file = tempnam(sys_get_temp_dir(), 'foto') . '.jpg';
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));
    imagejpeg($image, $file, 95);
    return $file;
};
$fake = tempnam(sys_get_temp_dir(), 'falsa');
file_put_contents($fake, 'esto no es una foto');
$three = ['front' => $picture(), 'back' => $picture(), 'selfie' => $picture(900, 1200)];
$stored = fn() => array_values(array_diff(scandir("$data/verificaciones") ?: [], ['.', '..']));

$carla = new Client($base);
[, $body] = $carla->register('pasajera@example.com', 'Carla');
$carlaId = $body['user']['id'];
check('verificar pide cuenta', $anon->upload('verify_request', ['consent' => '1'], $three)[0] === 401);
[$code, $body] = $carla->get('verification');
check('al principio el perfil no está verificado', $code === 200 && $body['user']['verified'] === false && $body['request'] === null, $body);
check('pide aceptar que la administración vea las fotos', $carla->upload('verify_request', [], $three)[0] === 400);
check('pide las tres fotos', $carla->upload('verify_request', ['consent' => '1'], ['front' => $three['front'], 'back' => $three['back']])[0] === 400);
check('rechaza archivos que no son fotos', $carla->upload('verify_request', ['consent' => '1'], [...$three, 'back' => $fake])[0] === 400);
check('si falla no deja fotos guardadas', $stored() === [], $stored());
check('manda las fotos', $carla->upload('verify_request', ['consent' => '1'], $three)[0] === 201);
check('queda en revisión', ($carla->get('verification')[1]['request']['status'] ?? '') === 'pending');
check('no se manda dos veces mientras se revisa', $carla->upload('verify_request', ['consent' => '1'], $three)[0] === 409);
$pending = $find($admin->get('admin_verifications')[1]['items'], 'email', 'pasajera@example.com');
check('administración ve la solicitud con el nombre de la cuenta', $pending['name'] === 'Carla' && count($pending['files']) === 3, $pending);
check('las fotos quedan fuera de la carpeta pública', count($stored()) === 3 && !is_dir("$root/verificaciones"), $stored());
$photo = 'api.php?a=admin_photo&file=' . $pending['files'][0];
check('sin cuenta no se ven las fotos', $anon->bytes($photo)[0] === 401);
check('la misma persona no puede ver sus fotos', $carla->bytes($photo)[0] === 403);
[$code, $jpeg, $type] = $admin->bytes($photo);
$size = @getimagesizefromstring($jpeg);
check('administración ve la foto, achicada y recodificada', $code === 200 && $type === 'image/jpeg' && $size[0] === 1600 && $size[1] === 1000, [$code, $type, $size]);
check('no se puede pedir cualquier archivo', $admin->bytes('api.php?a=admin_photo&file=../viajes.sqlite')[0] === 404);
check('el resumen cuenta verificaciones pendientes', $admin->get('admin_overview')[1]['verifications'] === 1);
check('rechazar pide motivo', $admin->post('admin_verification', ['id' => $pending['id'], 'action' => 'reject'])[0] === 400);
check('rechazar con motivo', $admin->post('admin_verification', ['id' => $pending['id'], 'action' => 'reject', 'reason' => 'ilegible'])[0] === 200);
check('al rechazar se borran las fotos', $stored() === [] && $admin->bytes($photo)[0] === 404, $stored());
$request = $carla->get('verification')[1]['request'];
check('la persona ve el motivo', $request['status'] === 'rejected' && $request['reason'] === 'Las fotos no se ven bien.', $request);
check('puede volver a intentar', $carla->upload('verify_request', ['consent' => '1'], $three)[0] === 201);
$pending = $find($admin->get('admin_verifications')[1]['items'], 'email', 'pasajera@example.com');
check('aprobar', $admin->post('admin_verification', ['id' => $pending['id'], 'action' => 'approve'])[0] === 200);
check('al aprobar también se borran las fotos', $stored() === [], $stored());
check('el perfil queda verificado', $carla->get('session')[1]['user']['verified'] === true);
check('una verificación revisada no se revisa de nuevo', $admin->post('admin_verification', ['id' => $pending['id'], 'action' => 'approve'])[0] === 404);
[$code, $body] = $anon->get('profile', "&id=$carlaId");
check('perfil público con la insignia y sin correo', $code === 200 && $body['profile']['verified'] === true && $body['profile']['name'] === 'Carla' && !isset($body['profile']['email']), $body);
check('perfil inexistente', $anon->get('profile', '&id=nada')[0] === 404);
check('ya verificado no vuelve a pedir', $carla->upload('verify_request', ['consent' => '1'], $three)[0] === 409);

$driver->upload('verify_request', ['consent' => '1'], $three);
$pending = $find($admin->get('admin_verifications')[1]['items'], 'email', 'juan@example.com');
$admin->post('admin_verification', ['id' => $pending['id'], 'action' => 'approve']);
$listing = $anon->get('trips')[1]['items'];
$juanTrips = array_filter($listing, fn($t) => $t['driver_id'] === $juan['id']);
$othersVerified = array_filter($listing, fn($t) => $t['driver_id'] !== $juan['id'] && $t['driver_verified']);
check('los viajes de un conductor verificado muestran la insignia (y solo los suyos)', $juanTrips && !in_array(false, array_column($juanTrips, 'driver_verified'), true) && !$othersVerified, array_values($juanTrips));
[$code, $body] = $driver->post('trip_create', [...$trip, 'driver' => 'Otro Nombre']);
$newTrip = $find($anon->get('trips')[1]['items'], 'id', $body['id'] ?? '');
check('verificado, el viaje sale con el nombre de la cuenta', $code === 201 && $newTrip['driver'] === 'Juan', $newTrip);
check('administración puede quitar la verificación', $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'unverify'])[0] === 200
    && $driver->get('session')[1]['user']['verified'] === false
    && !$find($anon->get('trips')[1]['items'], 'id', $newTrip['id'])['driver_verified']);
$other->upload('verify_request', ['consent' => '1'], $three);
check('cancelar una solicitud borra las fotos', count($stored()) === 3 && $other->post('verify_cancel', [])[0] === 200 && $stored() === []
    && $other->get('verification')[1]['request']['status'] === 'cancelled');

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
