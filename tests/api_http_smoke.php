<?php
/** Real loopback HTTP lifecycle; all data and configuration are disposable. */
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('DB_HOST='); putenv('DB_NAME='); putenv('USE_REDIS=false');
require_once dirname(__DIR__) . '/lib/core.class.php';
require_once __DIR__ . '/fixtures/api_runtime.php';

use MajorDoMo\Core\Api\TokenStore;

$checks = 0;
function check(bool $condition, string $message): void { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException($message); }
function request(string $method, string $path, ?string $body = null, array $headers = array()): array
{
    $client = curl_init($GLOBALS['base'] . $path);
    $received = array();
    $requestHeaders = array('X-Fixture-Peer: ' . ($headers['peer'] ?? '192.168.55.10'));
    unset($headers['peer']);
    foreach ($headers as $key => $value) $requestHeaders[] = $key . ': ' . $value;
    curl_setopt_array($client, array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($client, $header) use (&$received) { $pair = explode(':', $header, 2); if (count($pair) === 2) $received[strtolower(trim($pair[0]))] = trim($pair[1]); return strlen($header); }));
    if ($body !== null) curl_setopt($client, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($client); $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE); curl_close($client);
    if ($raw === false) throw new RuntimeException('HTTP fixture unavailable');
    $json = json_decode($raw, true);
    return array($status, $json, $received, $raw);
}

$root = sys_get_temp_dir() . '/mjd-api-' . bin2hex(random_bytes(6)); mkdir($root);
$server = null; $pdo = null; $store = null;
try {
    ApiFixture::initialize($root, dirname(__DIR__)); mkdir($root . '/sessions');
    $pdo = ApiFixture::pdo($root); $store = new TokenStore($pdo); $issued = $store->issue('HTTP fixture');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message); if (!$socket) throw new RuntimeException('Cannot reserve loopback port');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address;
    $env = getenv(); $env['MJD_API_FIXTURE_ROOT'] = $root;
    $server = proc_open(array(PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/api_http_router.php'), array(0 => array('pipe', 'r'), 1 => array('file', $root . '/http.log', 'a'), 2 => array('file', $root . '/http.log', 'a')), $pipes, dirname(__DIR__), $env, array('bypass_shell' => true));
    if (!is_resource($server)) throw new RuntimeException('Cannot launch isolated HTTP fixture'); fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 60; $i++) { $client = @stream_socket_client('tcp://' . $address, $errno, $message, 0.1); if ($client) { fclose($client); $ready = true; break; } usleep(100000); }
    check($ready, 'Loopback server started');
    [$status, $data, $headers] = request('GET', '/api/v1/objects');
    check($status === 200 && $data['ok'] && count($data['data']) === 1, 'Local network bypass');
    check(str_contains($headers['content-type'], 'application/json') && $headers['cache-control'] === 'no-store' && isset($headers['x-request-id']) && !isset($headers['access-control-allow-origin']), 'JSON headers, no-store, correlation and closed CORS');
    [$status, $data] = request('GET', '/api/v1/objects', null, array('peer' => '203.0.113.8'));
    check($status === 401 && $data['error']['code'] === 'token_required', 'External request requires token');
    [$status, $data] = request('GET', '/api/objects?internal=1&token=ignored', null, array('peer' => '203.0.113.8'));
    check($status === 401 && $data['error_code'] === 'token_required', 'Legacy URLs cannot bypass authentication');
    [$status] = request('GET', '/api/v1/objects', null, array('peer' => '203.0.113.8', 'Authorization' => 'Bearer ' . $issued['token']));
    check($status === 200, 'External Bearer accepted');
    [$status] = request('GET', '/api/v1/objects', null, array('peer' => '203.0.113.8', 'X-API-Key' => $issued['token'], 'Authorization' => 'Basic fixture'));
    check($status === 200, 'External API key alongside proxy Basic');
    [$status] = request('GET', '/api/v1/objects', null, array('peer' => '127.0.0.2', 'X-Forwarded-For' => '192.168.55.4'));
    check($status === 200, 'Trusted proxy sees local client');
    foreach (array(array('peer' => '127.0.0.2'), array('peer' => '127.0.0.2', 'X-Forwarded-For' => '203.0.113.8'), array('peer' => '192.168.55.1', 'X-Forwarded-For' => '192.168.55.4')) as $testHeaders) {
        [$status] = request('GET', '/api/v1/objects', null, $testHeaders); check($status === 401, 'Proxy ambiguity cannot bypass auth');
    }
    foreach (array(array('Content-Type' => 'text/plain'), array('Content-Type' => 'application/x-www-form-urlencoded')) as $testHeaders) {
        [$status, $data] = request('POST', '/api/v1/data/Lamp.level', 'data=1', $testHeaders); check($status === 415 && $data['error']['code'] === 'unsupported_media_type', 'v1 rejects non-JSON bodies');
    }
    [$status, $data] = request('POST', '/api/v1/data/Lamp.level', '{', array('Content-Type' => 'application/json'));
    check($status === 400 && $data['error']['code'] === 'invalid_json', 'Malformed JSON');
    [$status, $data] = request('POST', '/api/v1/data/Lamp.level', '{"wrong":1}', array('Content-Type' => 'application/json'));
    check($status === 422 && $data['error']['details']['field'] === 'body.data', 'Structured field validation');
    [$status] = request('POST', '/api/v1/data/Lamp.level', '{"data":false}', array('Content-Type' => 'application/json')); check($status === 200, 'v1 write');
    [$status, $data] = request('GET', '/api/v1/data/Lamp.level'); check($status === 200 && $data['data'] === false, 'False remains JSON boolean');
    [$status, $data] = request('GET', '/api.php/data/Lamp.level'); check($status === 200 && $data['data'] === false && isset($data['passed'], $data['timestamp']), 'Legacy alias envelope');
    [$status] = request('POST', '/api/data/Lamp.level', 'data=0', array('Content-Type' => 'application/x-www-form-urlencoded')); check($status === 200, 'Legacy form write');
    [$status, $data] = request('GET', '/api/v1/method/Lamp.run'); check($status === 405 && $data['error']['code'] === 'method_not_allowed', 'Mutating v1 method rejects GET');
    [$status, $data] = request('POST', '/api/v1/method/Lamp.run', '{"value":9}', array('Content-Type' => 'application/json')); check($status === 200 && $data['data'] === 9, 'HTTP executes core method');
    [$status, $data] = request('GET', '/api/v1/missing'); check($status === 404, 'Unknown route');
    [$status, $data, $headers] = request('DELETE', '/api/v1/objects'); check($status === 405 && $headers['allow'] === 'GET', 'Allow header');
    [$status, $data] = request('GET', '/api/v1/objects', null, array('Sec-Fetch-Site' => 'cross-site')); check($status === 403, 'Cross-site legacy/new API access denied');
    [$status, $data] = request('GET', '/api/devices'); check($status === 410, 'Removed devices');
    [$status, $data] = request('POST', '/api/v1/data', str_repeat('x', 1048577), array('Content-Type' => 'application/json')); check($status === 413, 'Body size limit');
    [$status, $data, $headers, $raw] = request('POST', '/api/v1/module/fixtureapi', '{"mode":"echo"}', array('Content-Type' => 'application/json'));
    check($status === 200 && $data['ok'] && !str_contains($raw, '<p>'), 'Debug output cannot corrupt JSON');
    [$status, $data, $headers, $raw] = request('POST', '/api/v1/module/fixtureapi', '{"mode":"throw"}', array('Content-Type' => 'application/json'));
    check($status === 500 && $data['error']['code'] === 'internal_error' && !str_contains($raw, 'private implementation detail'), 'Exceptions do not leak implementation details');
    [$status, $data] = request('POST', '/api/v1/module/fixtureapi', '{"mode":"resource"}', array('Content-Type' => 'application/json')); check($status === 500 && $data['error']['code'] === 'internal_error', 'Unserializable module return remains structured JSON');
    [$status, $data, $headers, $raw] = request('POST', '/api/v1/module/fixtureapi', '{"mode":"exit"}', array('Content-Type' => 'application/json'));
    check($status === 503 && $data['error']['code'] === 'unavailable' && !str_contains($raw, '<h1>'), 'Legacy exit yields structured JSON');
    [$status, $data] = request('GET', '/api/v1/openapi.json'); check($status === 200 && $data['openapi'] === '3.0.3' && isset($data['paths']['/data/{target}']), 'Raw OpenAPI document');
    $rotated = $store->rotate($issued['id']);
    [$status] = request('GET', '/api/v1/objects', null, array('peer' => '203.0.113.8', 'Authorization' => 'Bearer ' . $issued['token'])); check($status === 401, 'Old token rejected over HTTP after rotation');
    $store->revoke($issued['id']);
    [$status] = request('GET', '/api/v1/objects', null, array('peer' => '203.0.113.8', 'X-API-Key' => $rotated['token'])); check($status === 401, 'Revocation enforced over HTTP');
    if (in_array('--ui', $argv, true)) {
        $command = array('node', __DIR__ . '/api_ui_smoke.mjs', $base, $root);
        $ui = proc_open($command, array(0 => array('pipe', 'r'), 1 => STDOUT, 2 => STDERR), $uiPipes, dirname(__DIR__), $env, array('bypass_shell' => true));
        if (!is_resource($ui)) throw new RuntimeException('Cannot launch browser test'); fclose($uiPipes[0]);
        check(proc_close($ui) === 0, 'Browser UI smoke');
    }
    [$status, $data] = request('GET', '/api/v1/objects', null, array('X-Fixture-Unsetup' => '1'));
    check($status === 503 && $data['error']['code'] === 'install_required' && isset($data['error']['details']['install_url']), 'Setup gate uses v1 error envelope');
    echo 'API HTTP smoke: ' . $checks . " passed\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    unset($store, $pdo); gc_collect_cycles(); ApiFixture::remove($root);
}
