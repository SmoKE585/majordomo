<?php
/** Core API, tokens, compatibility and UI on a disposable SQLite database. */
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('DB_HOST='); putenv('DB_NAME='); putenv('USE_REDIS=false');
require_once dirname(__DIR__) . '/lib/core.class.php';
require_once __DIR__ . '/fixtures/api_runtime.php';

use MajorDoMo\Core\{Config, Core, NetworkAccess};
use MajorDoMo\Core\Api\{AccessPolicy, ApiException, Contract, Dispatcher, Request, Response, TokenStore};
use MajorDoMo\Core\Console\Application as ConsoleApplication;

$checks = 0;
function check(bool $condition, string $message): void { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException($message); }
function rejects(callable $operation, int $status, string $code): void
{
    try { $operation(); } catch (ApiException $error) { check($error->status === $status && $error->errorCode === $code, 'Expected error ' . $code); return; }
    throw new RuntimeException('Expected rejection: ' . $code);
}
function invalid(callable $operation): void
{
    try { $operation(); } catch (InvalidArgumentException $error) { check(true, 'Invalid input rejected'); return; }
    throw new RuntimeException('Expected validation exception');
}

$root = sys_get_temp_dir() . '/mjd-api-' . bin2hex(random_bytes(6)); mkdir($root);
define('ROOT', str_replace('\\', '/', dirname(__DIR__)) . '/');
define('DIR_MODULES', $root . '/modules/');
try {
    ApiFixture::initialize($root, dirname(__DIR__)); ApiFixture::boot($root);
    $pdo = $GLOBALS['db']->dbh; $tokens = Core::apiTokens();
    check(Config::string('APP_ENV') === 'testing' && Config::string('DB_HOST') === '' && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Confirmed isolation');
    $network = new NetworkAccess(); $policy = new AccessPolicy($network);
    foreach (array('127.0.0.1', '::1', '192.168.0.5', '10.20.30.40', '172.16.0.1', '172.31.255.255', '169.254.4.1', 'fc00::1', 'fd12::4', 'fe80::1', '::ffff:192.168.1.5') as $ip) check($policy->isLocal(array('REMOTE_ADDR' => $ip)), 'Local: ' . $ip);
    foreach (array('8.8.8.8', '172.32.0.1', '172.15.255.255', '100.64.0.1', '2001:4860::1', '', 'bad') as $ip) check(!$policy->isLocal(array('REMOTE_ADDR' => $ip)), 'External/invalid: ' . $ip);
    check(!$policy->isLocal(array('REMOTE_ADDR' => '192.168.1.2', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1')), 'Untrusted forwarding cannot prove local');
    $proxy = new AccessPolicy($network, '192.168.55.0/24', '127.0.0.2/32,10.0.0.1/32');
    check($proxy->isLocal(array('REMOTE_ADDR' => '127.0.0.2', 'HTTP_X_FORWARDED_FOR' => '192.168.55.10')), 'Trusted proxy LAN');
    check(!$proxy->isLocal(array('REMOTE_ADDR' => '127.0.0.2', 'HTTP_X_FORWARDED_FOR' => '192.168.55.10,8.8.8.8')), 'Forged leftmost XFF cannot bypass external hop');
    check($proxy->isLocal(array('REMOTE_ADDR' => '127.0.0.2', 'HTTP_X_FORWARDED_FOR' => '192.168.55.10,10.0.0.1')), 'Multiple trusted hops');
    foreach (array('', '127.0.0.2', 'garbage', '192.168.55.1,garbage') as $xff) check(!$proxy->isLocal(array('REMOTE_ADDR' => '127.0.0.2', 'HTTP_X_FORWARDED_FOR' => $xff)), 'Missing/ambiguous proxy identity');
    check(!$proxy->isLocal(array('REMOTE_ADDR' => '10.2.3.4')), 'Explicit HOME_NETWORK limits bypass');
    rejects(fn() => $policy->authorize(array('REMOTE_ADDR' => '8.8.8.8', 'internal' => 1), static fn() => true), 401, 'token_required');
    rejects(fn() => $policy->authorize(array('REMOTE_ADDR' => '192.168.1.2', 'HTTP_SEC_FETCH_SITE' => 'cross-site'), static fn() => true), 403, 'cross_origin_denied');
    rejects(fn() => $policy->authorize(array('REMOTE_ADDR' => '192.168.1.2', 'HTTP_ORIGIN' => 'https://evil.example', 'HTTP_HOST' => 'home.example'), static fn() => true), 403, 'cross_origin_denied');
    $issued = $tokens->issue('Fixture', time() + 3600); $secret = $issued['token'];
    check($tokens->verify($secret) && !$tokens->verify('bad'), 'Token validation');
    check(!str_contains(json_encode($tokens->all()), $secret) && !array_key_exists('TOKEN_HASH', $tokens->all()[0]), 'Listing exposes neither token nor hash');
    check($pdo->query('SELECT TOKEN_HASH FROM api_tokens')->fetchColumn() === hash('sha256', $secret), 'Only hash persisted');
    check($policy->authorize(array('REMOTE_ADDR' => '8.8.8.8', 'HTTP_AUTHORIZATION' => 'Bearer ' . $secret), fn($token) => $tokens->verify($token)) === 'token', 'External bearer');
    check($policy->authorize(array('REMOTE_ADDR' => '8.8.8.8', 'HTTP_AUTHORIZATION' => 'Basic fixture', 'HTTP_X_API_KEY' => $secret), fn($token) => $tokens->verify($token)) === 'token', 'API key can coexist with Basic Auth');
    rejects(fn() => $policy->authorize(array('REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_API_KEY' => 'bad'), fn($token) => $tokens->verify($token)), 401, 'invalid_token');
    $rotated = $tokens->rotate($issued['id']);
    check(!$tokens->verify($secret) && $tokens->verify($rotated['token']), 'Rotation immediately invalidates old secret');
    $tokens->revoke($issued['id']); $tokens->revoke($issued['id']);
    check(!$tokens->verify($rotated['token']), 'Idempotent revocation');
    invalid(fn() => $tokens->rotate($issued['id'])); invalid(fn() => $tokens->issue('')); invalid(fn() => $tokens->issue('Past', time() - 1)); invalid(fn() => $tokens->revoke(999));
    $expired = $tokens->issue('Expires', time() + 10);
    $pdo->exec('UPDATE api_tokens SET EXPIRES_AT=1 WHERE ID=' . $expired['id']);
    check(!$tokens->verify($expired['token']), 'Expiry enforced'); invalid(fn() => $tokens->rotate($expired['id']));

    check(Request::decode('{"data":null}', 'application/json', false) === array('data' => null), 'Null is preserved');
    check(Request::decode('', 'application/x-www-form-urlencoded', true, array('data' => '0')) === array('data' => '0'), 'Legacy forms');
    rejects(fn() => Request::decode('{', 'application/json', false), 400, 'invalid_json');
    rejects(fn() => Request::decode('[]', 'application/json', false), 422, 'validation_failed');
    rejects(fn() => Request::decode('{}', 'text/plain', false), 415, 'unsupported_media_type');
    rejects(fn() => Request::decode(str_repeat('x', 1048577), 'application/json', false), 413, 'payload_too_large');
    rejects(fn() => new Request('GET', '/api/v1/data/%xx'), 400, 'invalid_path');
    check((new Request('GET', '/home/api.php/v1/data/Lamp.level', array(), array(), '/home/'))->segments === array('data', 'Lamp.level'), 'Subdirectory entry point');
    rejects(fn() => Contract::match(new Request('GET', '/api/v1/rooms/order')), 405, 'method_not_allowed');
    rejects(fn() => Contract::match(new Request('GET', '/api/v1/method/Lamp.run')), 405, 'method_not_allowed');
    rejects(fn() => Contract::match(new Request('GET', '/api/v1/unknown')), 404, 'not_found');
    foreach (Contract::operations() as $operation) {
        $path = preg_replace('/\{\w+\}/', 'example', $operation['path']);
        [$matched] = Contract::match(new Request($operation['method'], '/api/v1' . $path));
        check($matched['action'] === $operation['action'], 'Catalog route maps ' . $path);
        if ($operation['body'] !== null) Contract::validate((array)$operation['example'], $operation['body']);
    }
    $spec = Contract::openApi('/home/');
    check($spec['servers'][0]['url'] === '/home/api/v1' && count($spec['paths']) >= 15 && isset($spec['components']['securitySchemes']['bearerAuth']), 'OpenAPI catalogue complete');
    $dispatcher = Core::apiDispatcher();
    $dispatch = static fn($method, $path, $body = array(), $query = array()) => $dispatcher->dispatch(new Request($method, $path, $query, $body));
    check(count($dispatch('GET', '/api/v1/objects')) === 1, 'Object list');
    check($dispatch('GET', '/api/v1/data/Lamp.level') === 0, 'Scalar property');
    $dispatch('POST', '/api/v1/data/Lamp.level', array('data' => false));
    check($dispatch('GET', '/api/v1/data/Lamp.level') === false, 'False is preserved');
    $dispatch('POST', '/api/v1/data/Lamp.level', array('data' => null));
    check($dispatch('GET', '/api/v1/data/Lamp.level') === null, 'Null is preserved in storage');
    check(array_key_exists('Lamp.level', $dispatch('POST', '/api/v1/data', array('properties' => array('Lamp')))), 'Bulk object expansion');
    rejects(fn() => $dispatch('POST', '/api/v1/data/Lamp.level', array('wrong' => 1)), 422, 'validation_failed');
    rejects(fn() => $dispatch('GET', '/api/v1/data/Lamp.missing'), 404, 'not_found');
    check($dispatch('POST', '/api/v1/method/Lamp.run', array('value' => 12)) === 12, 'Method reaches real core executor');
    check($dispatch('POST', '/api/v1/script/Test%20script', array('input' => 3))['params']['input'] === 3, 'Script parameters');
    check($dispatch('POST', '/api/v1/events/TEST', array('value' => 4))['event_id'] === 41 && $GLOBALS['fixture_event'][1]['value'] === 4, 'Event parameters');
    check(Dispatcher::periodSeconds('month') === 2592000 && Dispatcher::periodSeconds('year') === 31536000, 'Correct month/year durations');
    check($dispatch('GET', '/api/v1/history/Lamp.level', array(), array('aggregate' => 'avg')) === 50, 'History aggregate');
    rejects(fn() => Dispatcher::periodSeconds('999year'), 422, 'validation_failed');
    $room = $dispatch('POST', '/api/v1/rooms', array('title' => 'Гостиная', 'priority' => 42));
    check($room['priority'] === 42 && !isset($room['room']), 'Created room respects priority and canonical schema');
    $dispatch('PUT', '/api/v1/rooms/' . $room['id'], array('title' => 'Зал'));
    check($dispatch('GET', '/api/v1/rooms/' . $room['id'])['priority'] === 42, 'Update preserves omitted priority');
    $dispatch('POST', '/api/v1/rooms/order', array('rooms' => array(array('id' => 1, 'priority' => 66), array('id' => 2, 'priority' => 77))));
    check($dispatch('GET', '/api/v1/rooms/1')['priority'] === 66, 'Room order');
    rejects(fn() => $dispatch('POST', '/api/v1/rooms/order', array('rooms' => array(array('id' => 1, 'priority' => 99), array('id' => 999, 'priority' => 0)))), 404, 'not_found');
    check($dispatch('GET', '/api/v1/rooms/1')['priority'] === 66, 'Whole order validated before writing');
    $pdo->exec('UPDATE objects SET LOCATION_ID=' . $room['id']);
    $dispatch('DELETE', '/api/v1/rooms/' . $room['id']);
    check((int)$pdo->query('SELECT LOCATION_ID FROM objects')->fetchColumn() === 0, 'Delete clears object bindings');
    check(isset($dispatch('GET', '/api.php/rooms')['rooms']), 'Legacy api.php alias');
    check($dispatch('POST', '/api/rooms/new', array('title' => 'Legacy'))['room']['title'] === 'Legacy', 'Legacy rooms form response');
    check($dispatch('POST', '/api/room', array('title' => 'Legacy singular'))['result'] === true, 'Legacy singular room');
    $dispatch('POST', '/api/v1/messages', array('message' => 'Привет'));
    check(count($dispatch('GET', '/api/v1/messages')['messages']) === 1, 'Messages read/write');
    check($dispatch('POST', '/api/v1/say', array('message' => 'Голос', 'level' => 2, 'member_id' => 1)) === null, 'Say delegates to core and retains null');
    check($dispatch('POST', '/api/v1/say/reply', array('message' => 'Ответ', 'replyto' => 'Вопрос')) === false, 'Reply retains false');
    check($GLOBALS['fixture_reply'] === array('Ответ', 0, 'Вопрос'), 'Reply parameters forwarded');
    check($dispatch('POST', '/api/v1/say/to', array('message' => 'Терминал', 'destination' => 'Кухня')) === 0, 'Terminal result retains zero');
    check($GLOBALS['fixture_destination'] === array('Терминал', 0, 'Кухня'), 'Terminal parameters forwarded');
    check($dispatch('POST', '/api/v1/subscriptions/COMMAND', array('details' => array('message' => 'Свет'), 'return_output' => true)) === 'fixture-output', 'Subscription output returned');
    rejects(fn() => $dispatch('POST', '/api/v1/subscriptions/COMMAND', array('return_output' => 'true')), 422, 'validation_failed');
    rejects(fn() => $dispatch('POST', '/api/v1/say/to', array('message' => 'Нет терминала')), 422, 'validation_failed');
    rejects(fn() => $dispatch('GET', '/api/v1/say'), 405, 'method_not_allowed');
    foreach (array(
        array('say', array('message' => 'Фон', 'level' => 0, 'member_id' => 1, 'source' => 'queue'), 'say'),
        array('sayreply', array('message' => 'Фоновый ответ', 'level' => 1, 'replyto' => 'Вопрос'), 'sayreply'),
        array('sayto', array('message' => 'Фоновый терминал', 'level' => 0, 'destination' => 'Кухня'), 'sayto'),
        array('subscriptions', array('event' => 'CUSTOM', 'details' => array('value' => false)), 'CUSTOM'),
        array('script', array('input' => 5), 'Test script'),
    ) as [$operation, $params, $target]) {
        check(Core::queue()->enqueueRuntime($operation, $params, $target), 'Direct core operation queued: ' . $operation);
        $item = $pdo->query('SELECT * FROM operations_queue ORDER BY ID DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        check($item['DATANAME'] === $operation && str_starts_with(json_decode($item['DATAVALUE'], true)['path'], 'core:'), 'Core operation has no API path');
        $pdo->exec("UPDATE operations_queue SET CLAIM_TOKEN='direct',CLAIM_UNTIL='2099-01-01 00:00:00' WHERE ID=" . $item['ID']);
        check(Core::worker()->execute($item['ID'], 'direct')['success'] === true, 'Worker executes core function: ' . $operation);
    }
    Core::queue()->enqueueRuntime('script', array(), 'Отсутствующий сценарий');
    $missing = $pdo->query('SELECT ID FROM operations_queue ORDER BY ID DESC LIMIT 1')->fetchColumn();
    $pdo->exec("UPDATE operations_queue SET CLAIM_TOKEN='direct',CLAIM_UNTIL='2099-01-01 00:00:00' WHERE ID=" . $missing);
    $failure = Core::worker()->execute($missing, 'direct');
    check($failure['success'] === false && str_contains($failure['error'], 'Отсутствующий сценарий'), 'Missing direct script reports its original name');
    $_GET = array('outer' => 1); $_POST = array('outer' => 2); $_REQUEST = array('outer' => 3); $_SERVER['REQUEST_URI'] = '/outer';
    $globals = array($_GET, $_POST, $_REQUEST, $_SERVER);
    $module = $dispatch('POST', '/api/v1/module/FIXTUREAPI/path', array('message' => 'JSON'));
    check($module['params']['request'] === array('path') && $module['get']['message'] === 'JSON', 'Third-party parameters and case-insensitive module lookup');
    check($globals === array($_GET, $_POST, $_REQUEST, $_SERVER), 'Module globals restored');
    try { $dispatch('POST', '/api/v1/module/fixtureapi', array('mode' => 'throw')); } catch (RuntimeException $error) { check($globals === array($_GET, $_POST, $_REQUEST, $_SERVER), 'Module globals restored on exception'); }
    check($dispatch('POST', '/api/v1/modulePropertySet/fixtureapi', array('object' => 'Lamp', 'property' => 'level', 'value' => false))['setHandleResult'][2] === false, 'Linked handler preserves false');
    $internal = Core::api()->request('/api/module/fixtureapi?from=query', 'POST', array('fromBody' => 1), true);
    check($internal['params']['from'] === 'query' && $internal['params']['fromBody'] === 1, 'Internal facade dispatches without HTTP');
    check($globals === array($_GET, $_POST, $_REQUEST, $_SERVER), 'Internal facade restores caller globals');
    check(Core::api()->request('/api/method/Lamp.run', 'GET', array('value' => 8)), 'Legacy async method accepted');
    check($pdo->query('SELECT DATANAME FROM operations_queue ORDER BY ID DESC')->fetchColumn() === 'method', 'Original queue operation type retained');
    check(Core::api()->request('/api/module/fixtureapi', 'POST', array('input' => 1)), 'Async module accepted');
    $queued = $pdo->query('SELECT * FROM operations_queue ORDER BY ID DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    check($queued['DATANAME'] === 'api', 'Generic calls use existing queue');
    $pdo->exec("UPDATE operations_queue SET CLAIM_TOKEN='fixture',CLAIM_UNTIL='2099-01-01 00:00:00' WHERE ID=" . $queued['ID']);
    check(Core::worker()->execute($queued['ID'], 'fixture')['success'] === true, 'Worker executes queued API through dispatcher');
    // Api\Request разбирает сегмент пути через rawurldecode, поэтому кодировать
    // его нужно rawurlencode: urlencode() превращает пробел в "+" и сценарий
    // с пробелом в названии не находится.
    check(Core::api()->request('/api/script/' . rawurlencode('Test script'), 'GET', array('input' => 5), true) !== false, 'Script title with a space is reachable through an encoded path');
    check(Core::api()->request('/api/script/Missing%20script', 'GET', array(), true) === false, 'Missing internal script is reported as a failure');
    check(str_contains((string)Core::api()->lastError(), 'not_found'), 'Internal API failure keeps its reason for the log');
    Core::api()->request('/api/script/Missing%20script', 'GET', array());
    $queued = $pdo->query('SELECT * FROM operations_queue ORDER BY ID DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $pdo->exec("UPDATE operations_queue SET CLAIM_TOKEN='fixture',CLAIM_UNTIL='2099-01-01 00:00:00' WHERE ID=" . $queued['ID']);
    $failed = Core::worker()->execute($queued['ID'], 'fixture');
    check($failed['success'] === false && str_contains($failed['error'], '/api/script/Missing script') && str_contains($failed['error'], 'not_found'),
        'Failed background API names the path and the reason');

    $response = new Response('fixture-id', microtime(true));
    check($response->success(false)['data'] === false && $response->failure(new ApiException(422, 'validation_failed', 'Bad'))['error']['details'] instanceof stdClass, 'Response types');
    $legacy = $response->success(array('data' => 1), true, new Request('GET', '/api/data/Lamp.level', array('token' => 'secret')));
    check(isset($legacy['passed'], $legacy['timestamp']) && !isset($legacy['request']['params']['token']), 'Legacy metadata and credential redaction');
    $app = new ConsoleApplication($root, null, null, null, null, static fn() => $tokens);
    foreach (array(array('api:tokens'), array('api:spec'), array('api:revoke', (string)$expired['id'])) as $arguments) {
        $out = fopen('php://temp', 'w+'); $err = fopen('php://temp', 'w+');
        $code = $app->run(array_merge($arguments, array('--json')), $out, $err); rewind($out);
        $output = json_decode(stream_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
        check($code === 0 && $output['ok'], 'CLI ' . $arguments[0]); fclose($out); fclose($err);
    }
    require_once ROOT . 'lib/ui/MDJUI.php';
    $tokensList = $tokens->all(); $operations = Contract::operations(); $spec = Contract::openApi(); $csrf = 'fixture';
    $tokens = $tokensList;
    ob_start(); require ROOT . 'templates/api/api.php'; $html = ob_get_clean();
    check(substr_count($html, 'data-api-operation=') === count($operations) && str_contains($html, 'data-api-token-list'), 'Full admin template renders all operations');
    check(!str_contains($html, $secret) && !str_contains($html, $rotated['token']), 'Admin initial HTML contains no stored plaintext secret');
    echo 'API smoke: ' . $checks . " passed\n";
} finally {
    (new ReflectionProperty(Core::class, 'services'))->setValue(null, array());
    unset($app, $dispatch, $dispatcher, $tokens, $pdo, $GLOBALS['db']);
    gc_collect_cycles();
    ApiFixture::remove($root);
}
