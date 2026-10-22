<?php
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('DB_HOST='); putenv('DB_NAME='); putenv('USE_REDIS=false');
putenv('LOG_DIRECTORY=');
require_once dirname(__DIR__) . '/lib/core.class.php';
require_once __DIR__ . '/fixtures/api_runtime.php';

$checks = 0;
function check(bool $condition, string $label): void { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException($label); }
function request(string $method, string $path, string $body = '', array $headers = array(), bool $cookie = true): array
{
    $client = curl_init($GLOBALS['base'] . $path); $received = array();
    $requestHeaders = array(); foreach ($headers as $key => $value) $requestHeaders[] = $key . ': ' . $value;
    curl_setopt_array($client, array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($client, $line) use (&$received) { $parts = explode(':', $line, 2); if (count($parts) === 2) $received[strtolower(trim($parts[0]))] = trim($parts[1]); return strlen($line); }));
    if ($cookie) { curl_setopt($client, CURLOPT_COOKIEFILE, $GLOBALS['root'] . '/cookies'); curl_setopt($client, CURLOPT_COOKIEJAR, $GLOBALS['root'] . '/cookies'); }
    if ($body !== '') curl_setopt($client, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($client); $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE); curl_close($client);
    if ($raw === false) throw new RuntimeException('HTTP fixture failed');
    return array($status, $raw, $received, json_decode($raw, true));
}
function csrf(string $html): string { preg_match('/(?:name="_md_csrf" value="|name="md-csrf-token" content=")([a-f0-9]{64})/', $html, $match); return $match[1] ?? ''; }
function login(string $password = 'admin-secret'): array
{
    [, $html] = request('GET', '/admin.php?auth=login');
    return request('POST', '/admin.php?auth=login', http_build_query(array('username' => 'admin', 'password' => $password, '_md_csrf' => csrf($html))), array('Origin' => $GLOBALS['base']));
}

$root = sys_get_temp_dir() . '/mjd-auth-http-' . bin2hex(random_bytes(6)); mkdir($root);
$server = null;
try {
    $project = dirname(__DIR__);
    ApiFixture::initialize($root, $project);
    $pdo = ApiFixture::pdo($root);
    $pdo->exec("ALTER TABLE users ADD COLUMN PASSWORD TEXT DEFAULT ''; ALTER TABLE users ADD COLUMN IS_ADMIN INTEGER DEFAULT 0; ALTER TABLE users ADD COLUMN IS_DEFAULT INTEGER DEFAULT 0;");
    $statement = $pdo->prepare('INSERT INTO users (ID,USERNAME,NAME,PASSWORD,IS_ADMIN) VALUES (2,?,?,?,1)');
    $statement->execute(array('admin', 'Admin', hash('sha512', 'admin-secret')));
    check($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && getenv('APP_ENV') === 'testing' && getenv('DB_HOST') === '', 'Confirmed isolated SQLite');
    foreach (array('public', 'templates/auth', 'lib/ui') as $directory) mkdir($root . '/' . $directory, 0700, true);
    foreach (array('admin', 'api', 'nf', 'index') as $entry) file_put_contents($root . '/public/' . $entry . '.php', '<?php');
    file_put_contents($root . '/lib/ui/MDJUI.php', '<?php require ' . var_export($project . '/lib/ui/MDJUI.php', true) . ';');
    file_put_contents($root . '/templates/auth/login.php', '<?php require ' . var_export($project . '/templates/auth/login.php', true) . ';');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message); $address = stream_socket_get_name($socket, false); fclose($socket); $base = 'http://' . $address;
    $env = getenv(); $env['MJD_AUTH_FIXTURE_ROOT'] = $root;
    $server = proc_open(array(PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/auth_http_router.php'), array(0 => array('pipe', 'r'), 1 => array('file', $root . '/http.log', 'a'), 2 => array('file', $root . '/http.log', 'a')), $pipes, $project, $env, array('bypass_shell' => true));
    if (!is_resource($server)) throw new RuntimeException('Cannot start auth fixture'); fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 50; $i++) { $client = @stream_socket_client('tcp://' . $address, $errno, $message, .1); if ($client) { fclose($client); $ready = true; break; } usleep(100000); }
    check($ready, 'Loopback fixture started');
    [$status, , $headers] = request('GET', '/panel/');
    check($status === 302 && $headers['location'] === '/admin.php?auth=login', 'Panel requires login');
    [$status] = request('GET', '/modules/blockly_code/autosave.php?auth=login', '', array(), false);
    check($status === 302, 'Direct module cannot use login parameter to skip auth');
    [$status] = request('GET', '/index.php?action=ajaxsetglobal&var=Lamp.level&value=2', '', array(), false);
    check($status === 302, 'Index AJAX mutations require admin');
    [$status] = request('GET', '/objects/', '', array(), false); check($status === 404, 'Old object HTTP endpoint is removed');
    [$status] = request('GET', '/scripts/core_worker.php', '', array('X-Fixture-Peer' => '192.168.55.3'), false); check($status === 404, 'System scripts are not public even on LAN');
    [$status, , , $data] = request('GET', '/panel/?part_load=1');
    check($status === 401 && $data['NEED_RELOAD'] === 1 && $data['login_url'] === '/admin.php?auth=login', 'Expired partLoad returns structured 401');
    [$status, $html, $headers] = request('GET', '/admin.php?auth=login&prj=attacker&no_session=1', '', array(), false);
    check($status === 200 && csrf($html) !== '' && !str_contains($html, 'attacker') && str_contains($headers['set-cookie'], 'HttpOnly') && str_contains($headers['set-cookie'], 'SameSite=Strict'), 'Login ignores URL fixation and emits secure cookie attributes');
    [$status, $html] = request('GET', '/admin.php?auth=login');
    check(str_contains($html, 'autocomplete="current-password"') && str_contains($html, 'name="username"') && !str_contains($html, 'password_hash'), 'Real accessible login form');
    [$status] = request('POST', '/admin.php?auth=login', 'username=admin&password=admin-secret');
    check($status === 403, 'Login CSRF required');
    [$status] = request('POST', '/admin.php?auth=login', http_build_query(array('username' => 'admin', 'password' => 'admin-secret', '_md_csrf' => csrf($html))), array('Origin' => 'http://evil.test'));
    check($status === 403, 'Cross-origin login denied');
    [$status, , $headers] = login();
    check($status === 303 && $headers['location'] === '/panel/', 'Administrator login succeeds');
    [$status, $html] = request('GET', '/panel/'); $token = csrf($html);
    check($status === 200 && $token !== '', 'Protected shell exposes only CSRF');
    [$status] = request('GET', '/panel/users/2.html', '', array('Sec-Fetch-Site' => 'none'));
    check($status === 200, 'Admin deep-link bookmark works');
    [$status] = request('GET', '/panel/users/2/delete.html', '', array('Sec-Fetch-Site' => 'cross-site'));
    check($status === 403, 'Legacy GET action rejects cross-site');
    [$status] = request('GET', '/panel/?no_session=1', '', array('Origin' => $base)); check($status === 200, 'no_session cannot disable enforcement');
    [$status, , , $data] = request('GET', '/api/v1/objects');
    check($status === 200 && $data['ok'], 'External API accepts admin cookie');
    [$status, , , $data] = request('POST', '/api/v1/data/Lamp.level', '{"data":18}', array('Content-Type' => 'application/json', 'Origin' => $base));
    check($status === 403 && $data['error']['code'] === 'csrf_failed', 'Cookie API writes require CSRF');
    [$status] = request('POST', '/api/v1/data/Lamp.level', '{"data":18}', array('Content-Type' => 'application/json', 'Origin' => $base, 'X-CSRF-Token' => $token));
    check($status === 200, 'Session API write with CSRF');
    [$status, , , $data] = request('GET', '/api/v1/data/Lamp.level'); check($status === 200 && $data['data'] === 18, 'API write persisted to SQLite');
    [$status] = request('GET', '/api/v1/objects', '', array('Origin' => 'http://evil.test')); check($status === 403, 'API session rejects foreign origin');
    [$status] = request('GET', '/api/v1/objects', '', array(), false); check($status === 401, 'External API without cookie still requires token');
    [$status] = request('GET', '/api/v1/objects', '', array('X-Fixture-Peer' => '192.168.55.3'), false); check($status === 200, 'LAN API policy retained');
    [$status] = request('GET', '/admin.php?auth=logout', '', array('Origin' => $base)); check($status === 403, 'GET cannot log out');
    [$status] = request('POST', '/admin.php?auth=logout', http_build_query(array('_md_csrf' => $token)), array('Origin' => $base)); check($status === 303, 'POST logout works');
    [$status, , , $data] = request('GET', '/api/v1/objects'); check($status === 401 && $data['error']['code'] === 'session_expired', 'Logout ends session API access');
    login();
    $statement = $pdo->prepare('UPDATE users SET PASSWORD=? WHERE ID=2'); $statement->execute(array(\MajorDoMo\Core\Users\Passwords::hash('new-secret')));
    [$status] = request('GET', '/panel/'); check($status === 302, 'Password change revokes panel session');
    login('new-secret'); $pdo->exec('UPDATE users SET IS_ADMIN=0 WHERE ID=2');
    [$status] = request('GET', '/api/v1/objects'); check($status === 401, 'Rights removal revokes API session');
    $pdo->exec('UPDATE users SET IS_ADMIN=1 WHERE ID=2'); login('new-secret');
    request('GET', '/fixture/expire', '', array('Origin' => $base));
    [$status] = request('GET', '/api/v1/objects'); check($status === 401, 'Idle expiration ends API session');
    for ($i = 0; $i < 3; $i++) [$status] = login('wrong');
    check($status === 429, 'Third wrong password locks IP');
    [$status, , $headers] = login('new-secret'); check($status === 429 && (int)$headers['retry-after'] > 0, 'Correct password cannot bypass active IP ban');
    $pending = glob($root . '/var/events/*.json');
    $queued = count($pending) === 1 ? json_decode(file_get_contents($pending[0]), true) : null;
    check($queued !== null && $queued['name'] === 'AUTH_BRUTE_FORCE' && $queued['payload']['attempts'] === 3 && $queued['payload']['ip'] === '203.0.113.7', 'HTTP brute force queues one event without credentials');
    if (in_array('--ui', $argv, true)) {
        \MajorDoMo\Core\Config::boot($root);
        (new \MajorDoMo\Core\Auth\LoginAttempts($root))->unblock('203.0.113.7');
        $ui = proc_open(array('node', __DIR__ . '/auth_ui_smoke.mjs', $base, $root), array(0 => array('pipe', 'r'), 1 => STDOUT, 2 => STDERR), $uiPipes, $project, $env, array('bypass_shell' => true));
        if (!is_resource($ui)) throw new RuntimeException('Cannot start browser'); fclose($uiPipes[0]);
        if (proc_close($ui) !== 0) throw new RuntimeException('Auth browser check failed');
    }
    check(!str_contains(file_get_contents($root . '/http.log'), 'Fatal error'), 'No runtime fatal errors');
    echo 'Auth HTTP smoke: ' . $checks . " passed.\n";
} catch (Throwable $e) {
    throw new RuntimeException($e->getMessage() . '; fixture log: ' . substr((string)@file_get_contents($root . '/http.log'), -3500), 0, $e);
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    unset($pdo, $statement); gc_collect_cycles(); ApiFixture::remove($root);
}
