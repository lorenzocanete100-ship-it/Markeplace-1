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

// Base con el formato anterior, como la que ya puede haber en el hosting: cuentas sin las columnas
// nuevas, calificaciones sin rol, restos de la verificación con DNI que se quitó y cuentas de cuando
// se entraba con Google.
mkdir("$data/sesiones", 0700, true);
mkdir("$data/verificaciones", 0700);
file_put_contents("$data/verificaciones/" . str_repeat('ab', 16) . '.jpg', 'foto vieja de un DNI');
$legacy = new PDO("sqlite:$data/viajes.sqlite");
$legacy->exec("CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE, name TEXT NOT NULL, password TEXT NOT NULL, created INTEGER NOT NULL,
    google_sub TEXT NOT NULL DEFAULT '')");
$legacy->exec("CREATE UNIQUE INDEX idx_users_google ON users(google_sub) WHERE google_sub != ''");
$addOld = $legacy->prepare('INSERT INTO users(id,email,name,password,created) VALUES (?,?,?,?,?)');
foreach ([['cuenta-vieja', 'vieja@example.com', 'Cuenta vieja'], ['cuenta-gmail', 'lore.viejo@gmail.com', 'Lore viejo']] as [$id, $email, $name]) {
    $addOld->execute([$id, $email, $name, password_hash('una-clave-de-prueba', PASSWORD_DEFAULT), time()]);
}
// Las cuentas de Google guardaban una contraseña al azar que nadie conoce.
$addGoogle = $legacy->prepare('INSERT INTO users(id,email,name,password,created,google_sub) VALUES (?,?,?,?,?,?)');
foreach ([['cuenta-google', 'marta.g@gmail.com', 'Marta G', '1111'], ['cuenta-google2', 'pepe.g@gmail.com', 'Pepe G', '2222']] as [$id, $email, $name, $sub]) {
    $addGoogle->execute([$id, $email, $name, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), time(), $sub]);
}
// Calificaciones de la versión anterior, que no decían cómo viajaron (a Lore, que publicó un viaje, y a la cuenta vieja).
$legacy->exec("CREATE TABLE trips (id TEXT PRIMARY KEY, owner TEXT NOT NULL REFERENCES users(id), origin TEXT NOT NULL, destination TEXT NOT NULL,
    day TEXT NOT NULL, hour TEXT NOT NULL, seats INTEGER NOT NULL, driver TEXT NOT NULL, phone TEXT NOT NULL, phone_digits TEXT NOT NULL,
    vehicle TEXT NOT NULL, notes TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'active', created INTEGER NOT NULL)");
$legacy->prepare("INSERT INTO trips VALUES ('viaje-viejo', 'cuenta-gmail', 'Lanús', 'Mar del Plata', ?, '08:00', 2, 'Lore', '54 9 11 1111 2222', '5491111112222', '', '', 'active', ?)")
    ->execute([date('Y-m-d', strtotime('-30 days')), time() - 40 * 86400]);
$legacy->exec("CREATE TABLE ratings (id TEXT PRIMARY KEY, rater TEXT NOT NULL REFERENCES users(id), rated TEXT NOT NULL REFERENCES users(id),
    stars INTEGER NOT NULL, comment TEXT NOT NULL DEFAULT '', created INTEGER NOT NULL, updated INTEGER NOT NULL, UNIQUE(rater, rated))");
$legacy->exec("INSERT INTO ratings VALUES ('r1', 'cuenta-vieja', 'cuenta-gmail', 4, 'Maneja bien.', 1, 1), ('r2', 'cuenta-gmail', 'cuenta-vieja', 5, '', 1, 1)");
$legacy->exec("CREATE TABLE verifications (id TEXT PRIMARY KEY, user TEXT, status TEXT, reason TEXT, files TEXT, created INTEGER, decided INTEGER)");
$legacy->exec("INSERT INTO verifications VALUES ('v1', 'cuenta-vieja', 'pending', '', '[\"ab.jpg\"]', 1, 0)");
$legacy = null;
// Sesiones que quedaron abiertas en la versión anterior.
$oldSession = bin2hex(random_bytes(16));
$oldCsrf = bin2hex(random_bytes(32));
file_put_contents("$data/sesiones/sess_$oldSession", 'uid|s:12:"cuenta-gmail";csrf|s:64:"' . $oldCsrf . '";');
$googleSession = bin2hex(random_bytes(16));
$googleCsrf = bin2hex(random_bytes(32));
file_put_contents("$data/sesiones/sess_$googleSession", 'uid|s:13:"cuenta-google";csrf|s:64:"' . $googleCsrf . '";');

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
        $r = $this->request("api.php?a=$action", json_encode($body), $csrf ?? $this->csrf);
        // Entrar, salir y cambiar la contraseña renuevan el token.
        $this->csrf = is_string($r[1]['csrf'] ?? null) ? $r[1]['csrf'] : $this->csrf;
        return $r;
    }

    // Usa una sesión ya guardada en el servidor (por ejemplo, una de la versión anterior).
    public function useSession(string $id, string $csrf): void
    {
        file_put_contents($this->jar, "127.0.0.1\tFALSE\t/\tFALSE\t0\tasiento\t$id\n");
        $this->csrf = $csrf;
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

    public function register(string $login, string $name = 'Juan'): array
    {
        return $this->post('register', ['login' => $login, 'password' => 'una-clave-de-prueba', 'name' => $name]);
    }

    public function login(string $login, string $password = 'una-clave-de-prueba'): array
    {
        return $this->post('login', ['login' => $login, 'password' => $password]);
    }
}

$failures = 0;
function check(string $name, bool $ok, mixed $detail = null): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok ? '' : ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    $failures += $ok ? 0 : 1;
}

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
check('la sesión entrega token y fecha', $code === 200 && strlen($body['csrf']) === 64 && $body['today'] === date('Y-m-d') && $body['user'] === null
    && !array_key_exists('googleClientId', $body) && $body['contact'] === '', $body);
[$code, $body] = $anon->post('register', [], 'malo');
check('sin token CSRF se rechaza', $code === 403 && $body['csrf'] === true, $body);
check('publicar sin cuenta pide iniciar sesión', $anon->post('trip_create', $trip)[0] === 401);
check('mis viajes sin cuenta pide iniciar sesión', $anon->get('trips', '&mine=1')[0] === 401);
check('el ingreso con Google ya no existe', $anon->post('google', ['credential' => 'x'])[0] === 404 && $anon->post('setup', [])[0] === 404
    && $anon->get('admin_settings')[0] === 401 && $anon->post('photo_google', [])[0] === 404);
check('la sesión no dice "sin contraseña" a quien no entró', !isset($anon->get('session')[1]['user']['noPassword']));

echo "Cuentas con correo o con celular\n";
$old = new Client($base);
[$code, $body] = $old->login('vieja@example.com');
check('una cuenta de la versión anterior sigue funcionando', $code === 200 && $body['user']['admin'] === false && $body['new'] === false, $body);
[$code, $body] = (new Client($base))->post('login', ['email' => 'VIEJA@example.com', 'password' => 'una-clave-de-prueba']);
check('también si la página vieja manda el campo "email"', $code === 200, $body);
$lore = new Client($base);
$lore->useSession($oldSession, $oldCsrf);
check('una sesión abierta antes de actualizar sigue andando', ($lore->get('session')[1]['user']['id'] ?? '') === 'cuenta-gmail');
$driver = new Client($base);
[$code, $body] = $driver->register('Juan@Example.com');
check('registro con correo', $code === 200 && $body['user']['name'] === 'Juan' && $body['user']['email'] === 'juan@example.com'
    && $body['user']['admin'] === false && $body['new'] === true && $body['user']['photo'] === null
    && !array_key_exists('google', $body['user']), $body);
check('no se repite el correo', (new Client($base))->register('juan@example.com')[0] === 409);
$cel = new Client($base);
[$code, $body] = $cel->register('11 2345 6789', 'Celeste');
check('registro con número de celular', $code === 200 && $body['user']['email'] === '+5491123456789' && $body['new'] === true, $body);
check('una cuenta nueva tiene contraseña', $body['user']['noPassword'] === false, $body);
foreach (['+54 9 11 2345 6789', '011 2345 6789', '54 11 2345-6789', '011 15 2345 6789', '(011) 15-2345-6789', '11 15 2345 6789',
    '+54 11 15 2345 6789', '0054 9 11 2345 6789', '9 11 2345 6789'] as $written) {
    check("entra con el celular escrito como \"$written\"", (new Client($base))->login($written)[0] === 200);
}
// En el interior la característica es de 3 o 4 cifras y el 15 va entre la característica y el número.
[$code, $body] = (new Client($base))->register('0351 15 555 1234', 'Cordobesa');
check('celular del interior con 0 y 15', $code === 200 && $body['user']['email'] === '+5493515551234', $body);
foreach (['351 555 1234', '+54 9 351 555-1234', '(0351) 15-555-1234', '+54 351 15 555 1234'] as $written) {
    check("entra con el celular escrito como \"$written\"", (new Client($base))->login($written)[0] === 200);
}
check('característica de 4 cifras con 15', (new Client($base))->register('03541 15 123456', 'Carlos Paz')[1]['user']['email'] === '+5493541123456');
check('con + es el código de otro país, no una característica',
    (new Client($base))->register('+354 611 2345', 'Islandesa')[1]['user']['email'] === '+3546112345'
    && (new Client($base))->register('3546 11 2345', 'Calamuchita')[1]['user']['email'] === '+5493546112345');
[$code, $body] = (new Client($base))->register('+54 9 11 2345 6789', 'Otra');
check('no se repite el celular aunque se escriba distinto', $code === 409 && str_contains($body['error'], 'celular'), $body);
check('acepta celulares de otros países', (new Client($base))->register('+595 981 123 456', 'Paraguaya')[1]['user']['email'] === '+595981123456');
$resetLimits();
foreach ([['1234', 'muy corto'], ['juan@', 'correo incompleto'], ['cualquier cosa', 'texto'], ['', 'vacío'],
    ['15 2345 6789', 'sin característica'], ['2345 6789', 'sin característica ni 15'], ['+54 9 11 2345 678', 'al que le falta un número'],
    ['0351 99 555 1234', 'con algo que no es 15']] as [$written, $label]) {
    [$code, $body] = (new Client($base))->register($written, 'Alguien');
    check("rechaza un correo o celular $label", $code === 400 && str_contains($body['error'], 'celular'), $body);
}
check('pide un nombre', (new Client($base))->post('register', ['login' => 'sin.nombre@example.com', 'password' => 'una-clave-de-prueba', 'name' => 'J'])[0] === 400);
check('pide una contraseña de 10 letras o más', (new Client($base))->post('register', ['login' => 'corta@example.com', 'password' => 'corta', 'name' => 'Corta'])[0] === 400);
[$code, $body] = (new Client($base))->login('juan@example.com', 'otra-clave-mala');
check('contraseña incorrecta con correo', $code === 401 && $body['error'] === 'Correo o contraseña incorrectos.', $body);
[$code, $body] = (new Client($base))->login('11 2345 6789', 'otra-clave-mala');
check('contraseña incorrecta con celular', $code === 401 && $body['error'] === 'Celular o contraseña incorrectos.', $body);
check('el correo no distingue mayúsculas', (new Client($base))->login('JUAN@Example.com')[0] === 200);
[$code, $body] = (new Client($base))->register('+juan@example.com', 'Más');
check('un correo que empieza con + sigue siendo correo', $code === 200 && $body['user']['email'] === '+juan@example.com'
    && (new Client($base))->login('+juan@example.com', 'otra-clave-mala')[1]['error'] === 'Correo o contraseña incorrectos.', $body);

echo "Cuentas de cuando se entraba con Google\n";
$resetLimits();
[$code, $body] = (new Client($base))->login('marta.g@gmail.com', 'cualquier-clave');
check('al entrar, una cuenta de Google avisa que pida una contraseña nueva', $code === 401
    && $body['error'] === 'Esta cuenta se creó con Google, que ya no se usa. Pedile a la administración una contraseña nueva.', $body);
$marta = new Client($base);
$marta->useSession($googleSession, $googleCsrf);
check('si tenía la sesión abierta, la página sabe que no tiene contraseña', $marta->get('session')[1]['user']['noPassword'] === true);
[$code, $body] = $marta->post('password_change', ['password' => 'mi-clave-nueva']);
check('y elige una sin escribir la actual', $code === 200 && $body['user']['noPassword'] === false && strlen($body['csrf']) === 64, $body);
check('sigue adentro', $marta->get('session')[1]['user']['id'] === 'cuenta-google');
$copy = new Client($base);
$copy->useSession($googleSession, $googleCsrf);
check('una copia de la sesión de antes deja de servir', $copy->get('session')[1]['user'] === null);
check('después entra con su correo y esa contraseña', (new Client($base))->login('marta.g@gmail.com', 'mi-clave-nueva')[0] === 200);
check('para cambiarla otra vez ya pide la actual', $marta->post('password_change', ['password' => 'otra-clave-mas'])[0] === 403);

echo "Viajes\n";
$resetLimits();
[$code, $body] = $driver->post('trip_create', $trip);
check('publica un viaje', $code === 201 && isset($body['id']), $body);
$tripId = $body['id'];

[$code, $body] = $anon->get('trips');
$item = $body['items'][0] ?? [];
check('el viaje aparece en la lista pública', $code === 200 && count($body['items']) === 1, $body);
check('el teléfono se ve en la lista pública', ($item['phone'] ?? '') === '54 9 11 2345 6789' && $item['phone_digits'] === '5491123456789', $item);
check('no hay precio ni dueño en los datos', !array_key_exists('price', $item) && !array_key_exists('owner', $item), array_keys($item));
check('no se muestra con qué correo o celular entra el conductor', !str_contains(json_encode($body), 'juan@example.com'));
check('respeta saltos de línea en detalles', $item['notes'] === "Salgo desde la plaza.\nLlevo mate.", $item['notes']);
check('quien entra con su celular lo puede usar como teléfono del viaje', $cel->post('trip_create', [...$trip, 'phone' => '+5491123456789'])[0] === 201);

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
$own = $find($mine, 'id', $tripId);
check('sigue en mis viajes con 2 asientos', ($own['status'] ?? '') === 'full' && $own['seats'] === 2 && $own['mine'] === true, $own);
check('el dueño lo vuelve a publicar', $driver->post('trip_update', ['id' => $tripId, 'status' => 'active'])[0] === 200);

$db->prepare("UPDATE trips SET day=? WHERE id=?")->execute([date('Y-m-d', strtotime('-1 day')), $tripId]);
$ids = array_column($anon->get('trips')[1]['items'], 'id');
check('los viajes pasados desaparecen solos', !in_array($tripId, $ids, true), $ids);
$db->prepare("UPDATE trips SET day=? WHERE id=?")->execute([$tomorrow, $tripId]);
$publicIds = fn() => array_column($anon->get('trips')[1]['items'], 'id');

echo "Administración\n";
$resetLimits();
$admin = new Client($base);
$admin->register('duena@example.com', 'Dueña');
check('sin cuenta no se entra a administración', $anon->get('admin_users')[0] === 401);
check('sin activar, un usuario no es administrador', $admin->get('admin_overview')[0] === 403);
check('código incorrecto', $admin->post('admin_claim', ['code' => 'AAAA-BBBB-CCCC-DDDD'])[0] === 403);
[$code, $body] = $admin->post('admin_claim', ['code' => 'test-code-1234']);
check('el código activa la administración', $code === 200 && $body['user']['admin'] === true, $body);
// Si el dueño pierde el acceso a su cuenta de administración, crea otra y vuelve a poner el código.
$spare = new Client($base);
$spare->register('repuesto@example.com', 'Repuesto');
[$code, $body] = $spare->post('admin_claim', ['code' => 'TESTCODE1234']);
check('el código sirve de nuevo para recuperar la administración', $code === 200 && $body['user']['admin'] === true, $body);
check('y la otra administración se la puede quitar', $admin->post('admin_user', ['id' => $body['user']['id'], 'action' => 'remove_admin'])[0] === 200
    && $spare->get('admin_overview')[0] === 403);
check('los usuarios comunes no ven administración', $other->get('admin_trips')[0] === 403);
check('solo administración pone su WhatsApp de contacto', $other->post('admin_contact', ['phone' => '11 5555 4444'])[0] === 403);
check('el WhatsApp de contacto tiene que ser un celular', $admin->post('admin_contact', ['phone' => 'duena@example.com'])[0] === 400
    && $admin->post('admin_contact', ['phone' => '15 5555 4444'])[0] === 400);
[$code, $body] = $admin->post('admin_contact', ['phone' => '011 15 5555-4444']);
check('la administración pone su WhatsApp y todos lo ven en Entrar', $code === 200 && $body['contact'] === '+5491155554444'
    && $anon->get('session')[1]['contact'] === '+5491155554444', $body);
check('y lo puede sacar', $admin->post('admin_contact', ['phone' => ''])[1]['contact'] === '' && $anon->get('session')[1]['contact'] === '');
$admin->post('admin_contact', ['phone' => '11 5555 4444']);
[$code, $body] = $admin->get('admin_settings');
check('Ajustes ya no existe (la página vieja guardada en el navegador recibe Google sin configurar)', $code === 200 && $body === ['googleClientId' => '']
    && $admin->post('admin_settings', ['googleClientId' => 'x'])[0] === 404, $body);

check('denuncia sin motivo', $anon->post('report', ['trip' => $tripId, 'reason' => 'porque sí'])[0] === 400);
check('denuncia de un viaje que no existe', $anon->post('report', ['trip' => 'nada', 'reason' => 'precio'])[0] === 404);
check('cualquiera puede denunciar sin cuenta', $anon->post('report', ['trip' => $tripId, 'reason' => 'precio', 'details' => 'Me pidió plata'])[0] === 201);
$report = $admin->get('admin_reports')[1]['items'][0] ?? [];
check('la denuncia llega a administración', ($report['trip'] ?? '') === $tripId && $report['details'] === 'Me pidió plata' && $report['owner_email'] === 'juan@example.com', $report);
$overview = $admin->get('admin_overview')[1];
check('el resumen cuenta denuncias y usuarios', $overview['reports'] === 1 && $overview['drivers'] === 16 && $overview['upcoming'] >= 1, $overview);
check('descartar una denuncia', $admin->post('admin_report', ['id' => $report['id'], 'action' => 'dismiss'])[0] === 200 && $admin->get('admin_reports')[1]['items'] === []);
$anon->post('report', ['trip' => $tripId, 'reason' => 'datos']);
$row = $find($admin->get('admin_trips')[1]['items'], 'id', $tripId);
check('la lista de viajes muestra denuncias y correo', $row['reports'] === 1 && $row['owner_email'] === 'juan@example.com', $row);

$users = $admin->get('admin_users')[1]['items'];
$juan = $find($users, 'email', 'juan@example.com');
$me = $find($users, 'email', 'duena@example.com');
$ana = $find($users, 'email', 'otra@example.com');
$celeste = $find($users, 'email', '+5491123456789');
check('la lista de usuarios muestra con qué correo o celular entra cada uno', $celeste['name'] === 'Celeste' && !array_key_exists('google', $celeste), $celeste);
check('la lista marca las cuentas de Google que todavía no tienen contraseña', $find($users, 'id', 'cuenta-google2')['no_password'] === 1
    && $find($users, 'id', 'cuenta-google')['no_password'] === 0 && $celeste['no_password'] === 0);
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
[$code, $body] = $driver->login('juan@example.com');
check('al entrar ve hasta cuándo y el motivo', $code === 403
    && $body['error'] === 'Tu cuenta está suspendida hasta el ' . date('d/m/Y \\a \\l\\a\\s H:i', $juanRow['blocked_until']) . '. Motivo: Cobró un precio por el viaje o lo usó como negocio.', $body);
check('levantar la suspensión', $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'unblock'])[0] === 200
    && $find($admin->get('admin_users')[1]['items'], 'id', $juan['id'])['blocked_until'] === 0);
check('desbloqueado, sus viajes vuelven', in_array($tripId, $publicIds(), true));
check('desbloqueado, puede entrar', $driver->login('juan@example.com')[0] === 200);
check('no podés suspenderte a vos mismo', $admin->post('admin_user', ['id' => $me['id'], 'action' => 'block', 'days' => 7, 'reason' => 'otro'])[0] === 400);
$flota = $find($users, 'email', 'flota@example.com');
$admin->post('admin_user', ['id' => $flota['id'], 'action' => 'block', 'days' => 0, 'reason' => 'denuncias']);
[$code, $body] = (new Client($base))->login('flota@example.com');
check('suspensión sin fecha', $code === 403 && $body['error'] === 'Tu cuenta está suspendida. Motivo: Recibió varias denuncias.', $body);
$admin->post('admin_user', ['id' => $flota['id'], 'action' => 'block', 'days' => 1, 'reason' => 'otro']);
$db->prepare('UPDATE users SET blocked_until=? WHERE id=?')->execute([time() - 5, $flota['id']]);
[$code, $body] = $busy->login('flota@example.com');
$flotaRow = $find($admin->get('admin_users')[1]['items'], 'id', $flota['id']);
check('cuando vence el plazo, la suspensión se levanta sola', $code === 200 && $flotaRow['blocked'] === 0 && $flotaRow['blocked_reason'] === '', [$body, $flotaRow]);
check('la lista distingue conductores de acompañantes', $flotaRow['trips_total'] === 10 && $find($users, 'email', 'vieja@example.com')['trips_total'] === 0);
check('no podés quitarte la administración', $admin->post('admin_user', ['id' => $me['id'], 'action' => 'remove_admin'])[0] === 400);
check('nombrar otro administrador', $admin->post('admin_user', ['id' => $ana['id'], 'action' => 'make_admin'])[0] === 200 && $other->get('session')[1]['user']['admin'] === true);
check('no se suspende a un administrador', $admin->post('admin_user', ['id' => $ana['id'], 'action' => 'block', 'days' => 7, 'reason' => 'otro'])[0] === 400);
[$code, $body] = $admin->post('admin_user', ['id' => $ana['id'], 'action' => 'reset_password']);
check('a otro administrador no se le da una contraseña nueva', $code === 400 && $body['error'] === 'Primero quitale la administración.', $body);
check('quitar la administración a otro', $admin->post('admin_user', ['id' => $ana['id'], 'action' => 'remove_admin'])[0] === 200 && $other->get('session')[1]['user']['admin'] === false);
check('acción de usuario inventada', $admin->post('admin_user', ['id' => $ana['id'], 'action' => 'borrar'])[0] === 400
    && $admin->post('admin_user', ['id' => $ana['id'], 'action' => 'allow_google'])[0] === 400);

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

echo "Contraseñas\n";
$resetLimits();
$celSession = new Client($base);
$celSession->login('11 2345 6789');
check('cambiar la contraseña pide cuenta', $anon->post('password_change', ['current' => 'una-clave-de-prueba', 'password' => 'otra-clave-nueva'])[0] === 401);
check('pide la contraseña actual correcta', $cel->post('password_change', ['current' => 'no-es-esta-clave', 'password' => 'otra-clave-nueva'])[0] === 403);
check('pide una contraseña nueva de 10 letras o más', $cel->post('password_change', ['current' => 'una-clave-de-prueba', 'password' => 'corta'])[0] === 400);
check('cambiar la contraseña', $cel->post('password_change', ['current' => 'una-clave-de-prueba', 'password' => 'otra-clave-nueva'])[0] === 200
    && $cel->get('session')[1]['user']['email'] === '+5491123456789');
check('la sesión donde se cambió sigue abierta, las otras se cierran', $cel->post('trip_update', ['id' => 'x'])[0] === 404
    && $celSession->get('session')[1]['user'] === null);
check('entra con la nueva y no con la vieja', (new Client($base))->login('11 2345 6789', 'otra-clave-nueva')[0] === 200
    && (new Client($base))->login('11 2345 6789')[0] === 401);
$celeste = $find($admin->get('admin_users')[1]['items'], 'email', '+5491123456789');
check('solo administración da contraseñas nuevas', $other->post('admin_user', ['id' => $celeste['id'], 'action' => 'reset_password'])[0] === 403);
check('la administración cambia la suya en Mi perfil', $admin->post('admin_user', ['id' => $me['id'], 'action' => 'reset_password'])[0] === 400);
[$code, $body] = $admin->post('admin_user', ['id' => $celeste['id'], 'action' => 'reset_password']);
$temporary = $body['password'] ?? '';
check('si alguien se olvidó la contraseña, administración le da una nueva', $code === 200 && preg_match('/^[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}$/', $temporary) === 1, $body);
check('con la nueva entra y la anterior deja de servir', (new Client($base))->login('+54 9 11 2345 6789', $temporary)[0] === 200
    && (new Client($base))->login('11 2345 6789', 'otra-clave-nueva')[0] === 401);
check('y se cierran las sesiones que tenía abiertas', $cel->get('session')[1]['user'] === null);
[$code, $body] = $admin->post('admin_user', ['id' => 'cuenta-google2', 'action' => 'reset_password']);
check('una cuenta de Google entra con la contraseña que le da la administración', $code === 200
    && (new Client($base))->login('pepe.g@gmail.com', $body['password'])[0] === 200
    && $find($admin->get('admin_users')[1]['items'], 'id', 'cuenta-google2')['no_password'] === 0, $body);
$cel->login('11 2345 6789', $temporary);
$cel->post('password_change', ['current' => $temporary, 'password' => 'una-clave-de-prueba']);
$resetLimits();
$codes = array_map(fn() => (new Client($base))->login('juan@example.com', 'otra-clave-mala')[0], range(1, 26));
check('limita los intentos de contraseña seguidos', end($codes) === 429, $codes);
$resetLimits();
$codes = array_map(fn() => (new Client($base))->login('+54 9 11 2345 6789', 'otra-clave-mala')[0], range(1, 10));
$db->exec("DELETE FROM limits WHERE key LIKE 'auth:%'");  // como si probara desde otra conexión
[$code, $body] = (new Client($base))->login('11 2345 6789');
check('limita los intentos contra una misma cuenta aunque cambie la conexión', $codes === array_fill(0, 10, 401) && $code === 429
    && $body['error'] === 'Demasiados intentos con esta cuenta. Esperá 15 minutos.', [$codes, $body]);
check('las otras cuentas siguen entrando', (new Client($base))->login('juan@example.com')[0] === 200);
$resetLimits();

echo "Restos de la verificación con DNI\n";
check('se borraron las fotos de DNI que hubiera en el servidor', !is_dir("$data/verificaciones"));
$tables = array_column($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
check('se borró la tabla de verificaciones', !in_array('verifications', $tables, true), $tables);
check('la verificación ya no existe', $driver->get('verification')[0] === 404 && $admin->get('admin_verifications')[0] === 404
    && $admin->bytes('api.php?a=admin_photo&file=' . str_repeat('ab', 16) . '.jpg')[0] === 404);

echo "Calificaciones\n";
$profileOf = fn(Client $c, string $id) => $c->get('profile', "&id=$id")[1];
check('las calificaciones de antes cuentan como de conductor si la persona publicó viajes', $profileOf($anon, 'cuenta-gmail')['profile']['asDriver'] == ['rating' => 4, 'count' => 1]
    && $profileOf($anon, 'cuenta-vieja')['profile']['asRider'] == ['rating' => 5, 'count' => 1] && $profileOf($anon, 'cuenta-gmail')['reviews'][0]['role'] === 'driver');
$gabi = new Client($base);
[, $body] = $gabi->register('gabi.gomez@gmail.com', 'Gabriela Gómez');
$gabiId = $body['user']['id'];
$rate = fn(Client $c, array $body) => $c->post('rate', ['user' => $juan['id'], 'role' => 'driver', 'stars' => 5, ...$body])[0];
check('calificar pide cuenta', $rate($anon, []) === 401);
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
check('el perfil muestra el promedio como conductor', $profile['profile']['asDriver'] == ['rating' => 3.5, 'count' => 2]
    && $profile['profile']['asRider'] === ['rating' => null, 'count' => 0] && $profile['profile']['ratings'] === 2, $profile['profile']);
$review = $find($profile['reviews'], 'rater_name', 'Gabriela Gómez');
check('el perfil lista las opiniones con nombre, estrellas, rol y comentario', count($profile['reviews']) === 2
    && $review['comment'] === 'Puntual, buena charla.' && $review['stars'] === 4 && $review['role'] === 'driver' && $review['rater_id'] === $gabiId, $profile['reviews']);
check('cada uno ve su propia calificación', $profile['mine'] === ['stars' => 4, 'comment' => 'Puntual, buena charla.', 'role' => 'driver']
    && $profileOf($anon, $juan['id'])['mine'] === null, $profile['mine']);
check('el perfil no muestra correos ni celulares de las cuentas', !str_contains(json_encode($profile), '@')
    && !str_contains(json_encode([$profileOf($anon, $celeste['id'])['profile'], $profileOf($anon, $celeste['id'])['reviews']]), '+549'), $profile);
check('el conductor califica a la acompañante', $driver->post('rate', ['user' => $gabiId, 'role' => 'rider', 'stars' => 5, 'comment' => 'Buena compañera de viaje.'])[0] === 200);
$gabiProfile = $profileOf($anon, $gabiId)['profile'];
check('la acompañante suma calificaciones como acompañante', $gabiProfile['asRider'] == ['rating' => 5, 'count' => 1]
    && $gabiProfile['asDriver']['count'] === 0, $gabiProfile);
check('alguien que viajó con Juan de acompañante también lo califica', $rate($busy, ['role' => 'rider', 'stars' => 1]) === 200);
[$code, $body] = $driver->post('trip_create', $trip);
$juanTripId = $body['id'] ?? '';
$listing = $anon->get('trips')[1]['items'];
$juanTrip = $find($listing, 'id', $juanTripId);
check('los viajes muestran solo las estrellas como conductor', $juanTrip['driver_rating'] === 3.5 && $juanTrip['driver_ratings'] === 2, $juanTrip);
$profile = $profileOf($anon, $juan['id'])['profile'];
check('el perfil separa conductor y acompañante', $profile['asDriver']['count'] === 2 && $profile['asRider'] == ['rating' => 1, 'count' => 1]
    && $profile['ratings'] === 3, $profile);
$admin->post('admin_user', ['id' => $ana['id'], 'action' => 'block', 'days' => 1, 'reason' => 'otro']);
check('las calificaciones de una cuenta suspendida no cuentan', $profileOf($anon, $juan['id'])['profile']['asDriver']['count'] === 1);
$admin->post('admin_user', ['id' => $ana['id'], 'action' => 'unblock']);
$other->login('otra@example.com');
check('al levantar la suspensión vuelven a contar', $profileOf($anon, $juan['id'])['profile']['asDriver']['count'] === 2);
$code = $other->post('rate_delete', ['user' => $juan['id']])[0];
$after = $profileOf($anon, $juan['id'])['profile'];
check('borrar la propia calificación', $code === 200 && $after['asDriver'] == ['rating' => 4, 'count' => 1], $after);
check('los usuarios comunes no ven todas las calificaciones', $gabi->get('admin_ratings')[0] === 403);
$rating = $find($admin->get('admin_ratings')[1]['items'], 'rater_id', $gabiId);
check('administración ve quién calificó a quién y cómo viajaron', $rating['rated_id'] === $juan['id'] && $rating['rater_email'] === 'gabi.gomez@gmail.com'
    && $rating['stars'] === 4 && $rating['role'] === 'driver' && $rating['rater_created'] > time() - 600, $rating);
check('administración borra una calificación', $admin->post('admin_rating', ['id' => $rating['id'], 'action' => 'delete'])[0] === 200
    && $admin->post('admin_rating', ['id' => $rating['id'], 'action' => 'delete'])[0] === 404);
$profile = $profileOf($anon, $juan['id']);
check('sin calificaciones como conductor el promedio queda vacío', $profile['profile']['asDriver'] === ['rating' => null, 'count' => 0]
    && count($profile['reviews']) === 1 && $profile['reviews'][0]['role'] === 'rider', $profile);
$juanRow = $find($admin->get('admin_users')[1]['items'], 'id', $juan['id']);
check('administración ve los promedios de cada usuario como conductor y como acompañante', $juanRow['driver_ratings'] === 0
    && $juanRow['rider_rating'] == 1 && $juanRow['rider_ratings'] === 1, $juanRow);
$db->prepare('UPDATE trips SET driver=? WHERE id=?')->execute(['Nombre Escrito a Mano', $juanTripId]);
check('los viajes viejos con otro nombre muestran el de la cuenta', $find($anon->get('trips')[1]['items'], 'id', $juanTripId)['driver'] === 'Juan'
    && $find($admin->get('admin_trips')[1]['items'], 'id', $juanTripId)['driver'] === 'Juan');
check('cambiar el nombre pide cuenta', $anon->post('profile_name', ['name' => 'Alguien'])[0] === 401);
check('rechaza un nombre muy corto', $driver->post('profile_name', ['name' => ' J '])[0] === 400);
[$code, $body] = $driver->post('profile_name', ['name' => '  Juan   Carlos ']);
check('cambiar el nombre', $code === 200 && $body['user']['name'] === 'Juan Carlos' && $profileOf($anon, $juan['id'])['profile']['name'] === 'Juan Carlos', $body);
check('los viajes salen con el nombre nuevo', $find($anon->get('trips')[1]['items'], 'id', $juanTripId)['driver'] === 'Juan Carlos');
$driver->post('profile_name', ['name' => 'Juan']);
$admin->post('admin_user', ['id' => $juan['id'], 'action' => 'block', 'days' => 7, 'reason' => 'otro']);
check('no se califica a una cuenta suspendida', $rate($gabi, ['stars' => 1]) === 404 && $anon->get('profile', '&id=' . $juan['id'])[0] === 404);
$admin->post('admin_user', ['id' => $juan['id'], 'action' => 'unblock']);
$driver->login('juan@example.com');

echo "Fotos de perfil\n";
$resetLimits();
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
// Un archivo liviano pero de muchísimos píxeles ocuparía cientos de MB de memoria al abrirlo.
$huge = tempnam(sys_get_temp_dir(), 'enorme') . '.png';
$hugeImage = imagecreate(4200, 4200);
imagecolorallocate($hugeImage, 30, 90, 200);
imagepng($hugeImage, $huge, 9);
check('rechaza fotos de demasiados píxeles', getimagesize($huge)[0] === 4200 && filesize($huge) < 200_000
    && $gabi->upload('photo_upload', [], ['photo' => $huge])[0] === 400, filesize($huge));
[$code, $body] = $gabi->upload('photo_upload', [], ['photo' => $png]);
$gabiPhoto = $body['user']['photo'] ?? '';
check('subir una foto', $code === 200 && preg_match('#^api\.php\?a=photo&f=[a-f0-9]{32}\.jpg$#', $gabiPhoto) === 1, $body);
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
$rate($gabi, ['stars' => 5]);
$review = $find($profileOf($anon, $juan['id'])['reviews'], 'rater_id', $gabiId);
check('las opiniones muestran la foto de quien opina', ($review['rater_photo'] ?? null) === $gabiPhoto, $review);
check('el perfil público muestra la foto', $profileOf($anon, $gabiId)['profile']['photo'] === $gabiPhoto);
[, $body] = $driver->upload('photo_upload', [], ['photo' => $png]);
$juanPhoto = $body['user']['photo'];
$juanTrip = $find($anon->get('trips')[1]['items'], 'id', $juanTripId);
check('los viajes muestran la foto del conductor', $juanTrip['driver_photo'] === $juanPhoto && $juanPhoto !== $gabiPhoto, $juanTrip);
[, $body] = $driver->upload('photo_upload', [], ['photo' => $png]);
check('cambiar la foto borra la anterior', $body['user']['photo'] !== $juanPhoto && $anon->bytes($juanPhoto)[0] === 404 && count($storedPhotos()) === 2, $storedPhotos());
$juanPhoto = $body['user']['photo'];
$admin->post('admin_user', ['id' => $juan['id'], 'action' => 'block', 'days' => 1, 'reason' => 'otro']);
check('la foto de una cuenta suspendida no se muestra', $anon->bytes($juanPhoto)[0] === 404);
$admin->post('admin_user', ['id' => $juan['id'], 'action' => 'unblock']);
$driver->login('juan@example.com');
check('administración ve la foto de cada usuario', $find($admin->get('admin_users')[1]['items'], 'id', $juan['id'])['photo'] === $juanPhoto);
check('administración quita una foto', $admin->post('admin_user', ['id' => $juan['id'], 'action' => 'remove_photo'])[0] === 200
    && $driver->get('session')[1]['user']['photo'] === null && $anon->bytes($juanPhoto)[0] === 404 && count($storedPhotos()) === 1);
check('quitar la propia foto', $gabi->post('photo_delete', [])[0] === 200 && $gabi->get('session')[1]['user']['photo'] === null && $storedPhotos() === []);
if (function_exists('exif_read_data')) {
    // Foto acostada (rojo a la izquierda, azul a la derecha) con la marca EXIF "girar 90°" que ponen los
    // teléfonos: se tiene que ver parada, con el rojo arriba.
    $turned = imagecreatetruecolor(600, 300);
    imagefilledrectangle($turned, 0, 0, 299, 299, imagecolorallocate($turned, 220, 0, 0));
    imagefilledrectangle($turned, 300, 0, 599, 299, imagecolorallocate($turned, 0, 0, 220));
    ob_start();
    imagejpeg($turned, null, 95);
    $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0);
    $turnedFile = tempnam(sys_get_temp_dir(), 'girada') . '.jpg';
    file_put_contents($turnedFile, "\xFF\xD8\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\0\0" . $tiff . substr((string)ob_get_clean(), 2));
    [, $body] = $gabi->upload('photo_upload', [], ['photo' => $turnedFile]);
    $shown = imagecreatefromstring((string)$anon->bytes($body['user']['photo'])[1]);
    $red = fn(int $x, int $y) => (imagecolorat($shown, $x, $y) >> 16 & 0xFF) > 150 && (imagecolorat($shown, $x, $y) & 0xFF) < 100;
    check('endereza las fotos que el teléfono guardó giradas', $red(160, 40) && !$red(160, 280));
    $gabi->post('photo_delete', []);
}

$resetLimits();
[$code, $body] = $driver->post('logout', []);
check('cerrar sesión', $code === 200 && $driver->csrf === $body['csrf'] && $driver->get('session')[1]['user'] === null);
check('limita denuncias seguidas', count(array_filter(array_map(fn() => $anon->post('report', ['trip' => 'x', 'reason' => 'otro'])[0], range(1, 12)), fn($c) => $c === 429)) >= 1);
[$code, $body] = $driver->login('JUAN@example.com');
check('entrar de nuevo', $code === 200 && $body['user']['email'] === 'juan@example.com', $body);
check('estado del servidor', $anon->get('health')[1]['ok'] === true);
check('acción desconocida', $anon->get('nada')[0] === 404);
[$code, $html] = $anon->raw('');
check('la página principal carga, sin nada de Google', $code === 200 && str_contains($html, 'Asiento Vacío') && !str_contains($html, 'accounts.google'));
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
