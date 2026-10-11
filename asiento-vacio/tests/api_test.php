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
$env = [...getenv(), 'ASIENTO_DATA_DIR' => $data, 'ASIENTO_ADMIN_EMAIL' => 'admin@example.com'];
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
check('sin token CSRF se rechaza', $anon->post('register', [], 'malo')[0] === 403);
check('publicar sin cuenta pide iniciar sesión', $anon->post('trip_create', $trip)[0] === 401);
check('mis viajes sin cuenta pide iniciar sesión', $anon->get('trips', '&mine=1')[0] === 401);

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

$admin = new Client($base);
[$code, $body] = $admin->register('admin@example.com', 'Admin');
check('la cuenta de administración se reconoce', $code === 200 && $body['user']['admin'] === true, $body);
$listed = array_values(array_filter($admin->get('trips')[1]['items'], fn($t) => $t['id'] === $tripId))[0] ?? [];
check('administración puede borrar viajes ajenos', $listed['canDelete'] ?? false, $listed);
check('administración no puede editar viajes ajenos', $admin->post('trip_update', ['id' => $tripId, 'seats' => 1])[0] === 403);
check('administración borra el viaje', $admin->post('trip_delete', ['id' => $tripId])[0] === 200);
$ids = array_column($driver->get('trips', '&mine=1')[1]['items'], 'id');
check('un viaje borrado no aparece en ningún lado', !in_array($tripId, $ids, true), $ids);

[$code, $body] = $driver->post('logout', []);
$driver->csrf = $body['csrf'];
check('cerrar sesión', $code === 200 && $driver->get('session')[1]['user'] === null);
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
