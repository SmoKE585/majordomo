<?php
/** Loopback HTTP lifecycle for /api/hooks/<secret> and the admin tab. */
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('DB_HOST='); putenv('DB_NAME='); putenv('USE_REDIS=false');
require_once dirname(__DIR__) . '/lib/core.class.php';
require_once __DIR__ . '/fixtures/api_runtime.php';

use MajorDoMo\Core\Api\HookStore;

$checks = 0;
function check(bool $condition, string $message): void { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException($message); }
function request(string $method, string $path, ?string $body = null, array $headers = array(), ?array $multipart = null): array
{
    $client = curl_init($GLOBALS['base'] . $path);
    $received = array();
    $requestHeaders = array();
    foreach ($headers as $key => $value) $requestHeaders[] = $key . ': ' . $value;
    curl_setopt_array($client, array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEFILE => $GLOBALS['root'] . '/cookies',
        CURLOPT_COOKIEJAR => $GLOBALS['root'] . '/cookies',
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($client, $line) use (&$received) {
            $pair = explode(':', $line, 2);
            if (count($pair) === 2) $received[strtolower(trim($pair[0]))] = trim($pair[1]);
            return strlen($line);
        },
    ));
    if ($multipart !== null) curl_setopt($client, CURLOPT_POSTFIELDS, $multipart);
    elseif ($body !== null) curl_setopt($client, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($client);
    $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE);
    curl_close($client);
    if ($raw === false) throw new RuntimeException('HTTP fixture unavailable');
    $json = json_decode($raw, true);
    return array($status, $raw, $received, $json);
}
function startServer(string $root, string $address, array $extraEnv = array()): array
{
    $env = getenv();
    $env['MJD_API_FIXTURE_ROOT'] = $root;
    foreach ($extraEnv as $key => $value) $env[$key] = (string)$value;
    $process = proc_open(
        array(PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/api_http_router.php'),
        array(0 => array('pipe', 'r'), 1 => array('file', $root . '/http.log', 'a'), 2 => array('file', $root . '/http.log', 'a')),
        $pipes,
        dirname(__DIR__),
        $env,
        array('bypass_shell' => true)
    );
    if (!is_resource($process)) throw new RuntimeException('Cannot launch isolated HTTP fixture');
    fclose($pipes[0]);
    for ($i = 0; $i < 60; $i++) {
        $client = @stream_socket_client('tcp://' . $address, $errno, $message, 0.1);
        if ($client) { fclose($client); return array($process, $address); }
        usleep(100000);
    }
    proc_terminate($process); proc_close($process);
    throw new RuntimeException('Loopback server did not start');
}

$root = sys_get_temp_dir() . '/mjd-api-' . bin2hex(random_bytes(6)); mkdir($root);
$GLOBALS['root'] = $root;
$servers = array();
try {
    ApiFixture::initialize($root, dirname(__DIR__));
    $pdo = ApiFixture::pdo($root);
    $store = new HookStore($pdo, $root);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address;
    [$server] = startServer($root, $address); $servers[] = $server;

    // Admin tab: CSRF and the real module template.
    [$status, $html] = request('GET', '/panel/api.html?tab=hooks');
    check($status === 200 && str_contains($html, 'Входящие webhook') && str_contains($html, 'data-api-hook-list'), 'Admin hooks tab renders');
    preg_match('/name="_token" value="([a-f0-9]{64})"/', $html, $tokenMatch);
    $csrf = $tokenMatch[1] ?? '';
    check($csrf !== '', 'Admin page exposes its CSRF token');
    [$status, $raw, , $json] = request('POST', '/panel/api.html', http_build_query(array(
        'operation' => 'hook_create',
        'name' => 'Admin fixture',
        'module' => 'fixturehook',
        'handler' => 'webhook.php',
        'methods' => array('POST'),
        'header_name' => 'X-Fixture-Secret',
    )));
    check($status === 403 && ($json['ok'] ?? true) === false, 'Admin create without CSRF rejected');
    [$status, $raw, , $json] = request('POST', '/panel/api.html', http_build_query(array(
        '_token' => $csrf,
        'operation' => 'hook_create',
        'name' => 'Admin fixture',
        'module' => 'fixturehook',
        'handler' => 'webhook.php',
        'methods' => array('POST'),
        'header_name' => 'X-Fixture-Secret',
    )));
    check($status === 200 && ($json['ok'] ?? false) && str_contains((string)($json['hook_secret_html'] ?? ''), '/api/hooks/'), 'Admin creates a webhook');
    check(!str_contains((string)($json['hook_list_html'] ?? ''), '/api/hooks/'), 'Admin list never contains the public link');
    check(str_contains((string)($json['hook_list_html'] ?? ''), 'Admin fixture'), 'Admin list updates');
    $adminHook = $store->all()[0];
    check((int)$store->find((int)$adminHook['ID'])['ENABLED'] === 1, 'Admin-created hook is enabled');
    [$status, , , $json] = request('POST', '/panel/api.html', http_build_query(array(
        '_token' => $csrf,
        'operation' => 'hook_update',
        'id' => (int)$adminHook['ID'],
        'name' => 'Admin fixture renamed',
        'module' => 'fixturehook',
        'handler' => 'webhook.php',
        'methods' => array('POST', 'GET'),
        'header_name' => 'X-Fixture-Secret',
    )));
    check($status === 200 && ($json['ok'] ?? false) && str_contains((string)($json['hook_list_html'] ?? ''), 'Admin fixture renamed'), 'Admin edits a webhook');
    [$status, , , $json] = request('POST', '/panel/api.html', http_build_query(array(
        '_token' => $csrf,
        'operation' => 'hook_toggle',
        'id' => (int)$adminHook['ID'],
        'enabled' => '0',
    )));
    check($status === 200 && ($json['ok'] ?? false) && str_contains((string)($json['hook_list_html'] ?? ''), 'Отключён'), 'Admin disables a webhook');
    [$status, , , $json] = request('POST', '/panel/api.html', http_build_query(array(
        '_token' => $csrf,
        'operation' => 'hook_rotate',
        'id' => (int)$adminHook['ID'],
    )));
    check($status === 200 && ($json['ok'] ?? false) && str_contains((string)($json['hook_secret_html'] ?? ''), '/api/hooks/'), 'Admin rotates a webhook link');
    [$status, , , $json] = request('POST', '/panel/api.html', http_build_query(array(
        '_token' => $csrf,
        'operation' => 'hook_delete',
        'id' => (int)$adminHook['ID'],
    )));
    check($status === 200 && ($json['ok'] ?? false) && str_contains((string)($json['hook_list_html'] ?? ''), 'Входящих webhook пока нет'), 'Admin deletes a webhook');

    // Functional fixture with known secrets.
    $hook = $store->create('HTTP fixture', 'fixturehook', 'webhook.php', array('POST', 'GET'), 'X-Fixture-Secret');
    $secret = $hook['secret'];
    $header = $hook['header_secret'];
    $path = '/api/hooks/' . $secret;
    [$status, $raw, $headers, $json] = request('POST', $path . '?mode=echo&a=1', '{"x":1}', array('Content-Type' => 'application/json', 'X-Fixture-Secret' => $header));
    check($status === 200 && $json['method'] === 'POST' && $json['query']['a'] === '1' && $json['raw'] === '{"x":1}' && $json['header'] === $header, 'JSON body, query and header reach the handler unchanged');
    check($json['script'] === '/modules/fixturehook/webhook.php', 'Handler sees a normal module script name');
    check(str_contains($json['uri'], '[redacted]') && !str_contains($json['uri'], $secret), 'REQUEST_URI hides the secret from legacy logs');
    [$status, $raw, $headers, $json] = request('GET', $path . '?mode=echo&b=2', null, array('X-Fixture-Secret' => $header));
    check($status === 200 && $json['method'] === 'GET' && $json['query']['b'] === '2' && $json['raw'] === '', 'GET request reaches the handler');
    [$status, $raw, $headers, $json] = request('POST', $path . '?mode=echo', 'foo=bar', array('Content-Type' => 'application/x-www-form-urlencoded', 'X-Fixture-Secret' => $header));
    check($status === 200 && $json['post']['foo'] === 'bar' && $json['raw'] === 'foo=bar', 'Form data and raw body are preserved');
    $upload = tempnam(sys_get_temp_dir(), 'mjd-hook-');
    file_put_contents($upload, 'fixture-file');
    [$status, $raw, $headers, $json] = request('POST', $path . '?mode=echo', null, array('X-Fixture-Secret' => $header), array(
        'note' => 'hello',
        'upload' => new CURLFile($upload, 'text/plain', 'fixture.txt'),
    ));
    unlink($upload);
    check($status === 200 && $json['post']['note'] === 'hello' && in_array('upload', $json['files'], true), 'Multipart form and files are preserved');

    [$status, $raw, $headers, $json] = request('POST', $path . '?mode=exit', 'exit-body', array('Content-Type' => 'text/plain', 'X-Fixture-Secret' => $header));
    check($status === 201 && ($headers['content-type'] ?? '') === 'application/json' && $json['exit'] === true && $json['raw'] === 'exit-body', 'Handler exit preserves status, headers and body');
    check((int)$store->find($hook['id'])['LAST_STATUS'] === 201, 'Exit status is stored in diagnostics');
    [$status, $raw, $headers] = request('POST', $path . '?mode=status', '', array('X-Fixture-Secret' => $header));
    check($status === 204 && ($headers['x-fixture-handler'] ?? '') === 'status' && $raw === '', 'Handler status and headers are preserved');
    [$status, $raw, $headers] = request('GET', $path . '?mode=loader', null, array('X-Fixture-Secret' => $header));
    check($status === 200 && $raw === 'loader-ok', 'Handler includes the normal loader from the project root');

    [$status, , , $json] = request('POST', '/api/hooks/' . str_repeat('a', 64), '{}', array('Content-Type' => 'application/json', 'X-Fixture-Secret' => $header));
    check($status === 404 && ($json['error']['code'] ?? '') === 'not_found', 'Unknown secret rejected');
    [$status] = request('POST', '/api/hooks/' . str_repeat('b', 64), '{}', array('Content-Type' => 'application/json', 'Authorization' => 'Bearer mdm_' . str_repeat('a', 64), 'X-API-Key' => 'mdm_' . str_repeat('b', 64)));
    check($status === 404, 'API token cannot replace a webhook secret');
    [$status] = request('POST', $path, '{}', array('Content-Type' => 'application/json'));
    check($status === 401, 'Missing required header secret rejected');
    [$status] = request('POST', $path, '{}', array('Content-Type' => 'application/json', 'X-Fixture-Secret' => 'wrong'));
    check($status === 401, 'Wrong header secret rejected');
    [$status, , $headers, $json] = request('DELETE', $path, null, array('X-Fixture-Secret' => $header));
    check($status === 405 && str_contains((string)($headers['allow'] ?? ''), 'POST') && ($json['error']['code'] ?? '') === 'method_not_allowed', 'Method limit enforced');
    [$status, , , $json] = request('POST', $path . '?mode=echo', str_repeat('x', 1048577), array('Content-Type' => 'text/plain', 'X-Fixture-Secret' => $header));
    check($status === 413 && ($json['error']['code'] ?? '') === 'payload_too_large', 'Body size limit enforced');

    $store->setEnabled($hook['id'], false);
    [$status] = request('POST', $path, '{}', array('Content-Type' => 'application/json', 'X-Fixture-Secret' => $header));
    check($status === 404, 'Disabled secret rejected immediately');
    $store->setEnabled($hook['id'], true);
    $rotated = $store->rotate($hook['id']);
    [$status] = request('POST', $path, '{}', array('Content-Type' => 'application/json', 'X-Fixture-Secret' => $header));
    check($status === 404, 'Rotated secret stops working immediately');
    [$status] = request('POST', '/api/hooks/' . $rotated['secret'], '{}', array('Content-Type' => 'text/plain', 'X-Fixture-Secret' => $header));
    check($status === 200, 'New secret works after rotation');
    $secret = $rotated['secret']; $path = '/api/hooks/' . $secret;

    // Destination cannot be changed by a request parameter; only the stored row matters.
    $pdo->exec('UPDATE api_hooks SET HANDLER="../../config.php" WHERE ID=' . (int)$hook['id']);
    [$status, , , $json] = request('POST', $path, '{}', array('Content-Type' => 'text/plain', 'X-Fixture-Secret' => $header));
    check($status === 404 && ($json['error']['code'] ?? '') === 'not_found', 'Stored traversal path is rejected at execution');
    $pdo->exec('UPDATE api_hooks SET HANDLER="webhook.php" WHERE ID=' . (int)$hook['id']);
    $pdo->exec('DELETE FROM api_hooks WHERE ID=' . (int)$hook['id']);
    [$status] = request('POST', $path, '{}', array('Content-Type' => 'text/plain', 'X-Fixture-Secret' => $header));
    check($status === 404, 'Deleted entry rejected immediately');

    // Rate limit on a second isolated server with a small bucket.
    $rateHook = $store->create('Rate fixture', 'fixturehook', 'webhook.php', array('POST'));
    if (!is_dir($root . '/var/security')) mkdir($root . '/var/security', 0777, true);
    file_put_contents($root . '/var/security/api-hooks.json', '{}');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
    $rateAddress = stream_socket_get_name($socket, false); fclose($socket);
    $rateBase = 'http://' . $rateAddress;
    [$rateServer] = startServer($root, $rateAddress, array('MJD_API_HOOK_LIMIT' => 2)); $servers[] = $rateServer;
    $savedBase = $GLOBALS['base']; $GLOBALS['base'] = $rateBase;
    for ($i = 0; $i < 2; $i++) {
        [$status] = request('POST', '/api/hooks/' . $rateHook['secret'], '{}', array('Content-Type' => 'text/plain'));
        check($status === 200, 'Rate bucket allows request ' . ($i + 1));
    }
    [$status, , $headers, $json] = request('POST', '/api/hooks/' . $rateHook['secret'], '{}', array('Content-Type' => 'text/plain'));
    check($status === 429 && (int)($headers['retry-after'] ?? 0) > 0 && ($json['error']['code'] ?? '') === 'rate_limited', 'Rate limit returns 429 and Retry-After');
    $GLOBALS['base'] = $savedBase;

    $log = (string)@file_get_contents($root . '/http.log');
    check(!str_contains($log, 'Fatal error') && !str_contains($log, 'PHP Warning'), 'No runtime fatal errors or warnings');
    echo 'API hooks HTTP smoke: ' . $checks . " passed\n";
} catch (Throwable $error) {
    throw new RuntimeException($error->getMessage() . '; fixture log: ' . substr((string)@file_get_contents($root . '/http.log'), -5000), 0, $error);
} finally {
    foreach ($servers as $server) {
        if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    }
    unset($pdo, $store);
    gc_collect_cycles();
    ApiFixture::remove($root);
}
