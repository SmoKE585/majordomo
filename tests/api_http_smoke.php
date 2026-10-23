<?php
/** Real loopback HTTP lifecycle; all data and configuration are disposable. */
// 2026-10-23 — полный цикл входа users, отзыв, профиль и CORS поддоменов.
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('DB_HOST='); putenv('DB_NAME='); putenv('USE_REDIS=false');
putenv('LOG_DIRECTORY=');
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
    $statement = $pdo->prepare('UPDATE users SET PASSWORD=?,LINKED_OBJECT=? WHERE ID=1');
    $statement->execute(array(hash('sha512', 'user-secret'), 'Lamp'));
    $statement = $pdo->prepare('INSERT INTO users (ID,NAME,USERNAME,PASSWORD,IS_ADMIN) VALUES (2,?,?,?,1)');
    $statement->execute(array('Admin', 'admin', hash('sha512', 'admin-secret')));
    check(getenv('APP_ENV') === 'testing' && getenv('DB_HOST') === '' && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Confirmed isolated SQLite');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message); if (!$socket) throw new RuntimeException('Cannot reserve loopback port');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address;
    $env = getenv(); $env['MJD_API_FIXTURE_ROOT'] = $root;
    $env['BASE_URL'] = 'https://md.example.com';
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
    $jsonHeaders = array('Content-Type' => 'application/json', 'peer' => '203.0.113.80');
    $credentials = '{"username":"test","password":"user-secret"}';
    [$status, $data] = request('POST', '/api/v1/auth/login', '{"username":"test","password":"wrong"}', $jsonHeaders);
    check($status === 401 && $data['error']['code'] === 'invalid_credentials', 'Wrong password rejected without exposing account existence');
    [$status, $data] = request('POST', '/api/v1/auth/login', '{"username":"absent","password":"wrong"}', $jsonHeaders);
    check($status === 401 && $data['error']['code'] === 'invalid_credentials', 'Missing account uses same error');
    [$status, $data, $headers, $raw] = request('POST', '/api/v1/auth/login', $credentials, $jsonHeaders + array('Cookie' => 'prj=stale-cookie'));
    $userToken = $data['data']['access_token'] ?? '';
    check($status === 200 && preg_match('/^mdmu_[a-f0-9]{64}$/D', $userToken) && $data['data']['token_type'] === 'Bearer', 'Ordinary user logs in externally without integration key despite stale cookie');
    check($data['data']['user'] === array('id' => 1, 'username' => 'test', 'name' => 'Tester', 'is_admin' => false, 'linked_object' => 'Lamp', 'object' => array('id' => 1, 'title' => 'Lamp', 'class' => array('id' => 1, 'title' => 'Fixture'))), 'Profile includes linked object and class');
    check(!isset($headers['set-cookie']) && !str_contains($raw, '"stamp"') && !str_contains($raw, 'user-secret') && !str_contains($raw, 'PASSWORD'), 'Login never creates admin cookie or discloses password/fingerprint');
    check($data['data']['idle_timeout'] === 3600 && $data['data']['expires_at'] <= time() + 3600 && $data['data']['absolute_expires_at'] <= time() + 43200, 'Both session deadlines returned');
    $userHeaders = array('Authorization' => 'Bearer ' . $userToken, 'peer' => '203.0.113.80');
    [$status, $data, , $raw] = request('GET', '/api/v1/auth/me?user_id=2', null, $userHeaders);
    check($status === 200 && $data['data']['user']['id'] === 1 && !str_contains($raw, $userToken), 'Token check returns own profile without reissuing the secret');
    [$status, $data] = request('GET', '/api/v1/data/' . rawurlencode($data['data']['user']['object']['title']), null, $userHeaders);
    check($status === 200 && array_key_exists('level', $data['data']), 'Linked object properties are readable through existing API');
    [$status] = request('POST', '/api/v1/data/Lamp.level', '{"data":5}', $userHeaders + array('Content-Type' => 'application/json'));
    check($status === 200, 'User token authorizes public API writes without admin CSRF');
    [$status, $data] = request('GET', '/api/v1/panel/dashboard', null, $userHeaders);
    check($status === 401 && $data['error']['code'] === 'admin_session_required', 'User token cannot open panel API');
    [$status, $data] = request('GET', '/api/v1/auth/me');
    check($status === 401 && $data['error']['code'] === 'user_token_required', 'LAN cannot supply user identity');
    [$status, $data] = request('GET', '/api/v1/auth/me', null, array('Authorization' => 'Bearer ' . $issued['token']));
    check($status === 401 && $data['error']['code'] === 'user_token_required', 'Integration key does not impersonate a user');
    [$status, $data, $headers] = request('GET', '/api/v1/auth/login');
    check($status === 405 && $headers['allow'] === 'POST', 'Login only accepts POST');
    [$status] = request('POST', '/api/v1/auth/login', 'username=test&password=user-secret', array('Content-Type' => 'application/x-www-form-urlencoded'));
    check($status === 415, 'Credentials require JSON body');
    [$status] = request('POST', '/api/v1/auth/login', '{"username":"test"}', $jsonHeaders);
    check($status === 422, 'Login fields are validated');
    [$status, $data] = request('POST', '/api/v1/auth/logout', '{"user_id":2}', $userHeaders + array('Content-Type' => 'application/json'));
    check($status === 422, 'Cannot select another user session for logout');
    [$status, $data] = request('POST', '/api/v1/auth/logout', '{}', $userHeaders + array('Content-Type' => 'application/json'));
    check($status === 200 && $data['data']['logged_out'], 'Logout revokes current token');
    [$status, $data] = request('GET', '/api/v1/data/Lamp.level', null, $userHeaders);
    check($status === 401 && $data['error']['code'] === 'token_revoked' && $data['error']['details']['reauthenticate'], 'Logout immediately revokes public API access');
    [$status, $data] = request('POST', '/api/v1/auth/login', $credentials, $jsonHeaders);
    $userToken = $data['data']['access_token']; $userHeaders['Authorization'] = 'Bearer ' . $userToken;
    $pdo->exec('UPDATE api_user_sessions SET LAST_USED_AT=' . (time() - 3600) . ' WHERE REVOKED_AT IS NULL');
    [$status, $data, $headers] = request('GET', '/api/v1/auth/me', null, $userHeaders);
    check($status === 401 && $data['error']['code'] === 'token_expired' && $data['error']['details']['reauthenticate'] && isset($headers['www-authenticate']), 'Idle timeout explicitly tells client to reauthenticate');
    [$status, $data] = request('GET', '/api/v1/objects', null, array('Authorization' => 'Bearer ' . $userToken));
    check($status === 401 && $data['error']['code'] === 'token_expired', 'Expired token cannot fall back to LAN');
    $origin = array('Origin' => 'https://ui.example.com', 'Sec-Fetch-Site' => 'same-site');
    [$status, , $headers] = request('OPTIONS', '/api/v1/auth/login', null, $origin + array('Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'content-type'));
    check($status === 200 && $headers['access-control-allow-origin'] === $origin['Origin'] && !isset($headers['access-control-allow-credentials']), 'Subdomain preflight works without cookies');
    [$status, $data, $headers] = request('POST', '/api/v1/auth/login', $credentials, $jsonHeaders + $origin);
    check($status === 200 && $headers['access-control-allow-origin'] === $origin['Origin'], 'Subdomain can log in directly');
    $crossHeaders = array('Authorization' => 'Bearer ' . $data['data']['access_token']) + $origin;
    [$status, $data, $headers] = request('GET', '/api/v1/auth/me', null, $crossHeaders);
    check($status === 200 && $headers['access-control-allow-origin'] === $origin['Origin'], 'Subdomain can check its token');
    [$status, $data, $headers] = request('GET', '/api/v1/objects', null, $origin);
    check($status === 401 && $data['error']['code'] === 'token_required' && isset($headers['access-control-allow-origin']), 'CORS never grants LAN or cookie bypass');
    foreach (array('https://evil.test', 'https://example.com.evil.test', 'https://notexample.com', 'http://ui.example.com', 'null') as $foreignOrigin) {
        [$status, , $headers] = request('POST', '/api/v1/auth/login', $credentials, $jsonHeaders + array('Origin' => $foreignOrigin));
        check($status === 403 && !isset($headers['access-control-allow-origin']), 'Foreign or downgraded origin denied: ' . $foreignOrigin);
    }
    [$status] = request('OPTIONS', '/api/v1/auth/me', null, $origin + array('Access-Control-Request-Method' => 'GET', 'Access-Control-Request-Headers' => 'cookie'));
    check($status === 403, 'Preflight refuses credentialed headers');
    [$status] = request('GET', '/api/objects', null, $crossHeaders);
    check($status === 403, 'Legacy cross-origin access remains closed');
    $crossSecret = substr($crossHeaders['Authorization'], 7);
    $expire = $pdo->prepare('UPDATE api_user_sessions SET EXPIRES_AT=? WHERE TOKEN_HASH=?');
    $expire->execute(array(time() - 1, hash('sha256', $crossSecret)));
    [$status, $data, $headers] = request('GET', '/api/v1/auth/me', null, $crossHeaders);
    check($status === 401 && $data['error']['code'] === 'token_expired' && isset($headers['access-control-allow-origin']), 'Subdomain can read expiry errors');
    [$status, $data] = request('POST', '/api/v1/auth/login', '{"username":"admin","password":"admin-secret"}', $jsonHeaders);
    check($status === 200 && $data['data']['user']['is_admin'] && $data['data']['user']['object'] === null, 'Administrator also uses user login without object');
    $blockedHeaders = array('Content-Type' => 'application/json', 'peer' => '203.0.113.81');
    for ($attempt = 0; $attempt < 3; ++$attempt) [$status, $data, $headers] = request('POST', '/api/v1/auth/login', '{"username":"test","password":"wrong"}', $blockedHeaders);
    check($status === 429 && $data['error']['code'] === 'login_throttled' && (int)$headers['retry-after'] > 0, 'Shared login protection blocks IP after three errors');
    [$status] = request('POST', '/api/v1/auth/login', $credentials, $blockedHeaders);
    check($status === 429, 'Valid password cannot bypass temporary IP ban');
    check(!str_contains(file_get_contents($root . '/http.log'), 'user-secret'), 'HTTP logs contain no password');
    $rotated = $store->rotate($issued['id']);
    [$status] = request('GET', '/api/v1/objects', null, array('peer' => '203.0.113.8', 'Authorization' => 'Bearer ' . $issued['token'])); check($status === 401, 'Old token rejected over HTTP after rotation');
    $store->revoke($issued['id']);
    [$status] = request('GET', '/api/v1/objects', null, array('peer' => '203.0.113.8', 'X-API-Key' => $rotated['token'])); check($status === 401, 'Revocation enforced over HTTP');
    if (in_array('--ui', $argv, true)) {
        $command = array('node', __DIR__ . '/api_ui_smoke.mjs', $base, $root);
        $ui = proc_open($command, array(0 => array('pipe', 'r'), 1 => STDOUT, 2 => STDERR), $uiPipes, dirname(__DIR__), $env, array('bypass_shell' => true));
        if (!is_resource($ui)) throw new RuntimeException('Cannot launch browser test'); fclose($uiPipes[0]);
        $uiCode = proc_close($ui);
        if ($uiCode !== 0) throw new RuntimeException('Browser UI smoke; fixture log: ' . substr(file_get_contents($root . '/http.log'), -5000));
        check(true, 'Browser UI smoke');
    }
    [$status, $data] = request('GET', '/api/v1/objects', null, array('X-Fixture-Unsetup' => '1'));
    check($status === 503 && $data['error']['code'] === 'install_required' && isset($data['error']['details']['install_url']), 'Setup gate uses v1 error envelope');
    echo 'API HTTP smoke: ' . $checks . " passed\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    unset($statement, $expire, $store, $pdo); gc_collect_cycles(); ApiFixture::remove($root);
}
