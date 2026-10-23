<?php

/** Actual Workerman transport over loopback; no application config or live services. */
// 2026-10-23 — Origin родительского и соседних доменов проверяется в реальном handshake.
// 2026-10-23 — пользовательский токен подключает WS и закрывает его при истечении/отзыве.
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('USE_REDIS=false');
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Api\{AccessPolicy, Schema, UserTokenStore};
use MajorDoMo\Core\Users\UserAccounts;
use MajorDoMo\Core\NetworkAccess;
use MajorDoMo\Core\WebSockets\{Access, Protocol, Server, Tickets};
use Workerman\{Worker, Timer};

if (getenv('APP_ENV') !== 'testing' || (new PDO('sqlite::memory:'))->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Isolation failed');
$secret = 'mdm_' . str_repeat('a', 64);
if (($argv[1] ?? '') === '--fixture') {
    $fixture = $argv[2];
    $values = array('Room.temp' => '21', 'Room.empty' => null);
    $pages = static function () use (&$values): Generator { foreach ($values as $name => $value) yield array(array('PROPERTY' => $name, 'VALUE' => $value)); };
    $named = static function ($names) use (&$values): array {
        $result = array();
        foreach ($values as $name => $value) foreach ($names as $wanted) if (strcasecmp($wanted, $name) === 0) $result[] = array('PROPERTY' => $name, 'VALUE' => $value);
        return $result;
    };
    $protocol = new Protocol($pages, $named);
    $userTokens = new UserTokenStore(new PDO('sqlite:' . $fixture . '/auth.sqlite', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)));
    $access = new Access(new AccessPolicy(new NetworkAccess(), '', '127.0.0.1/32'), new Tickets($fixture),
        static function ($token) use ($fixture, $secret, $userTokens): bool {
            if (str_starts_with($token, 'mdmu_')) { $userTokens->authenticate($token, false); return true; }
            clearstatcache(); return is_file($fixture . '/token-valid') && hash_equals($secret, $token);
        },
        static fn($id) => $id === 7 && is_file($fixture . '/admin-valid') ? array('id' => 7, 'stamp' => 'stamp') : null,
        'https://admin.home.test');
    $server = new Server($protocol, $access, array('per_ip' => 1), static function (Protocol $protocol) use ($fixture, &$values): void {
        if (!is_file($fixture . '/update.json')) return;
        $updates = json_decode(file_get_contents($fixture . '/update.json'), true, 8, JSON_THROW_ON_ERROR);
        unlink($fixture . '/update.json');
        foreach ($updates as $row) $values[$row['PROPERTY']] = $row['VALUE'];
        $protocol->publish($updates);
    });
    $worker = new Worker('websocket://127.0.0.1:0');
    $server->attach($worker);
    $start = $worker->onWorkerStart;
    $worker->onWorkerStart = static function (Worker $worker) use ($start, $fixture): void {
        $start();
        file_put_contents($fixture . '/address', stream_socket_get_name($worker->getMainSocket(), false));
        Timer::add(45, static fn() => Worker::stopAll());
    };
    Worker::$pidFile = $fixture . '/worker.pid';
    Worker::$logFile = $fixture . '/worker.log';
    Worker::$command = 'start';
    Worker::runAll();
    exit;
}

$checks = 0;
function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); $GLOBALS['checks']++; }
function handshake(string $address, string $query, array $headers = array(), string $host = 'home.test'): array {
    $stream = stream_socket_client('tcp://' . $address, $errno, $error, 2);
    if (!$stream) throw new RuntimeException($error);
    stream_set_timeout($stream, 8);
    $request = 'GET /majordomo' . $query . " HTTP/1.1\r\nHost: " . $host . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\n";
    foreach ($headers as $name => $value) $request .= $name . ': ' . $value . "\r\n";
    fwrite($stream, $request . "\r\n");
    $response = '';
    while (($line = fgets($stream)) !== false) { $response .= $line; if ($line === "\r\n") break; }
    return array($stream, $response);
}
function bytes($stream, int $length): string {
    $data = '';
    while (strlen($data) < $length) {
        $part = fread($stream, $length - strlen($data));
        if ($part === false || $part === '') throw new RuntimeException('Truncated or timed out WS fixture frame');
        $data .= $part;
    }
    return $data;
}
function frame($stream): array {
    while (true) {
        $header = bytes($stream, 2);
        $opcode = ord($header[0]) & 15;
        $size = ord($header[1]) & 127;
        if ($size === 126) $size = unpack('n', bytes($stream, 2))[1];
        if ($size === 127) { $words = unpack('N2', bytes($stream, 8)); if ($words[1] > 0) throw new RuntimeException('Fixture frame too large'); $size = $words[2]; }
        $body = $size ? bytes($stream, $size) : '';
        if ($opcode === 9 || $opcode === 10) continue;
        if ($opcode === 8) return array('close' => unpack('n', substr($body, 0, 2))[1], 'reason' => substr($body, 2));
        $message = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        $message['decoded'] = json_decode($message['data'], true, 32, JSON_THROW_ON_ERROR);
        return $message;
    }
}
function sendRequest($stream, string $action): void {
    $body = json_encode(array('action' => $action, 'data' => array()));
    $mask = random_bytes(4); $encoded = '';
    for ($index = 0; $index < strlen($body); $index++) $encoded .= $body[$index] ^ $mask[$index % 4];
    fwrite($stream, "\x81" . chr(128 | strlen($body)) . $mask . $encoded);
}
function snapshot($stream): array {
    $rows = array();
    for ($index = 0; $index < 20; $index++) {
        $message = frame($stream);
        if ($message['action'] === 'properties') $rows = array_merge($rows, $message['decoded']);
        if ($message['action'] === 'snapshot_end') return $rows;
    }
    throw new RuntimeException('No snapshot end');
}
$fixture = sys_get_temp_dir() . '/mjd-ws-transport-' . bin2hex(random_bytes(6));
mkdir($fixture . '/var/sessions', 0700, true);
touch($fixture . '/token-valid'); touch($fixture . '/admin-valid');
$authDb = new PDO('sqlite:' . $fixture . '/auth.sqlite', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$authDb->exec('CREATE TABLE users (ID INTEGER PRIMARY KEY,USERNAME TEXT,NAME TEXT,PASSWORD TEXT,IS_ADMIN INTEGER);
    INSERT INTO users VALUES (1,\'member\',\'Житель\',\'fixture-hash\',0)');
$authDb->exec(Schema::userSessionsSql(true));
$userTokens = new UserTokenStore($authDb);
$user = (new UserAccounts($authDb))->userState(1);
$process = proc_open(array(PHP_BINARY, __FILE__, '--fixture', $fixture), array(0 => array('pipe','r'), 1 => array('file', $fixture . '/stdout', 'w'), 2 => array('file', $fixture . '/stderr', 'w')), $pipes, sys_get_temp_dir());
$remove = static function (string $path) use (&$remove): void { if (is_dir($path)) { foreach (scandir($path) as $file) if ($file !== '.' && $file !== '..') $remove($path . '/' . $file); rmdir($path); } else unlink($path); };
try {
    if (!is_resource($process)) throw new RuntimeException('Cannot start fixture');
    fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    while (!is_file($fixture . '/address') && microtime(true) < $deadline) usleep(20000);
    if (!is_file($fixture . '/address')) throw new RuntimeException(file_get_contents($fixture . '/stderr') . file_get_contents($fixture . '/stdout'));
    $address = file_get_contents($fixture . '/address');
    [$stream, $response] = handshake($address, '', array('X-Forwarded-For' => '203.0.113.8'));
    check(str_contains($response, '401') && !str_contains($response, '101'), 'external handshake denied before upgrade'); fclose($stream);
    [$stream, $response] = handshake($address, '', array('X-Forwarded-For' => '192.168.1.3', 'Origin' => 'https://evil.test'));
    check(str_contains($response, '403'), 'LAN cross-site handshake denied'); fclose($stream);
    [$local, $response] = handshake($address, '', array('X-Forwarded-For' => '192.168.1.3', 'Origin' => 'https://home.test'));
    check(str_contains($response, '101'), 'LAN upgrades without key');
    // BASE_URL сохраняет доменную границу даже с адресом апстрима вместо Host.
    [$stream, $response] = handshake($address, '', array('X-Forwarded-For' => '192.168.1.4', 'Origin' => 'https://home.test', 'X-Forwarded-Host' => 'home.test'), '127.0.0.1:8001');
    check(str_contains($response, '101'), 'forwarded host restores the origin behind a proxy');
    fclose($stream);
    [$stream, $response] = handshake($address, '', array('X-Forwarded-For' => '192.168.1.5', 'Origin' => 'https://home.test'), '127.0.0.1:8001');
    check(str_contains($response, '101'), 'public address accepts the site even without original proxy host');
    fclose($stream);
    foreach (array('https://home.test', 'https://ui.home.test', 'http://deep.ui.home.test:8080') as $index => $origin) {
        [$stream, $response] = handshake($address, '?snapshot=0&token=' . $secret,
            array('X-Forwarded-For' => '203.0.113.' . (20 + $index), 'Origin' => $origin, 'Sec-Fetch-Site' => 'same-site'), '127.0.0.1:8001');
        check(str_contains($response, '101') && frame($stream)['action'] === 'ready', 'external parent or sibling Origin upgrades with a valid token: ' . $origin);
        fclose($stream);
    }
    [$stream, $response] = handshake($address, '?token=' . $secret,
        array('X-Forwarded-For' => '203.0.113.25', 'Origin' => 'https://home.test.evil.com', 'X-Forwarded-Host' => 'home.test.evil.com', 'Sec-Fetch-Site' => 'same-site'), 'home.test.evil.com');
    check(str_contains($response, '403'), 'valid token and forged site headers cannot allow another domain'); fclose($stream);
    [$stream, $response] = handshake($address, '', array('X-Forwarded-For' => '203.0.113.26', 'Origin' => 'https://ui.home.test'));
    check(str_contains($response, '401'), 'allowed external Origin still requires credentials'); fclose($stream);
    $rejections = (string)@file_get_contents($fixture . '/stderr') . (string)@file_get_contents($fixture . '/worker.log');
    check(str_contains($rejections, 'handshake rejected') && str_contains($rejections, 'cross_origin_denied'), 'rejection reason is written to the log');
    // Если прокси не передал Host вообще, билет помнит хост, который видел браузер.
    $proxySid = str_repeat('c', 32); file_put_contents($fixture . '/var/sessions/sess_' . $proxySid, 'fixture');
    $proxyTicket = (new Tickets($fixture))->issue(array('id' => 7, 'stamp' => 'stamp'), $proxySid, time(), null, 'home.test');
    [$stream, $response] = handshake($address, '?snapshot=0&ticket=' . $proxyTicket, array('X-Forwarded-For' => '192.168.1.6', 'Origin' => 'https://home.test'), '127.0.0.1:8001');
    check(str_contains($response, '101'), 'ticket host restores the origin behind a proxy');
    fclose($stream);
    $rows = snapshot($local);
    check(count($rows) === 2 && $rows[0]['VALUE'] === '21' && $rows[1]['VALUE'] === null, 'actual default snapshot, including null');
    sendRequest($local, 'SetGlobal');
    check(frame($local)['decoded']['CODE'] === 'read_only', 'transport cannot dispatch write action');
    file_put_contents($fixture . '/update.json', json_encode(array(array('PROPERTY' => 'Room.temp', 'VALUE' => '22'))));
    check(frame($local)['decoded'][0]['VALUE'] === '22', 'live queue publication reaches socket');
    [$remote, $response] = handshake($address, '?snapshot=0', array('X-Forwarded-For' => '203.0.113.8', 'Authorization' => 'Bearer ' . $secret));
    check(str_contains($response, '101') && frame($remote)['action'] === 'ready', 'external Bearer read connection');
    unlink($fixture . '/token-valid');
    check(frame($remote)['close'] === 1008, 'revocation closes actual connection without a client request'); fclose($remote);
    $sid = str_repeat('b', 32); file_put_contents($fixture . '/var/sessions/sess_' . $sid, 'fixture');
    $ticket = (new Tickets($fixture))->issue(array('id' => 7, 'stamp' => 'stamp'), $sid, time());
    [$admin, $response] = handshake($address, '?snapshot=0&ticket=' . $ticket, array('X-Forwarded-For' => '203.0.113.8'));
    check(str_contains($response, '101') && frame($admin)['action'] === 'ready', 'external administrator uses one-use ticket');
    unlink($fixture . '/var/sessions/sess_' . $sid);
    check(frame($admin)['close'] === 1008, 'logout closes actual connection'); fclose($admin);
    $userSecret = $userTokens->issue($user)['access_token'];
    [$member, $response] = handshake($address, '?token=' . $userSecret,
        array('X-Forwarded-For' => '203.0.113.90', 'Origin' => 'https://ui.home.test'));
    check(str_contains($response, '101') && count(snapshot($member)) === 2, 'ordinary user token receives actual snapshot from sibling origin');
    $authDb->exec('UPDATE api_user_sessions SET LAST_USED_AT=' . (time() - 3600));
    $closed = frame($member);
    check($closed['close'] === 1008 && $closed['reason'] === 'authorization_expired', 'idle user session closes actual socket without client traffic'); fclose($member);
    [$member, $response] = handshake($address, '?token=' . $userSecret, array('X-Forwarded-For' => '203.0.113.90'));
    check(str_contains($response, '401'), 'expired user token cannot reconnect'); fclose($member);
    $userSecret2 = $userTokens->issue($user)['access_token'];
    [$member, $response] = handshake($address, '?snapshot=0&token=' . $userSecret2, array('X-Forwarded-For' => '203.0.113.91'));
    check(str_contains($response, '101') && frame($member)['action'] === 'ready', 'new user login reconnects');
    $userTokens->revoke($userTokens->authenticate($userSecret2, false)['id']);
    check(frame($member)['close'] === 1008, 'user logout closes actual connection'); fclose($member);
    fclose($local);
    check(!str_contains(file_get_contents($fixture . '/worker.log'), $secret) && !str_contains(file_get_contents($fixture . '/worker.log'), $ticket), 'transport logs contain no credentials');
    check(!str_contains(file_get_contents($fixture . '/worker.log'), $userSecret) && !str_contains(file_get_contents($fixture . '/worker.log'), $userSecret2), 'user tokens never enter transport logs');
    echo 'WebSocket transport smoke: ' . $checks . " passed (real loopback Workerman).\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    unset($userTokens, $authDb);
    $remove($fixture);
}
