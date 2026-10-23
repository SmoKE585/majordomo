<?php

/** SQLite and temporary sessions only. Never boot the application or its .env. */
// 2026-10-23 — границы домена BASE_URL, поддомены и защита от подмены Origin.
// 2026-10-23 — источник и дата WebSocket соответствуют выбранной записи значения.
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('USE_REDIS=false');
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\{Database, NetworkAccess, PropertyValues};
use MajorDoMo\Core\Api\{AccessPolicy, ApiException, TokenStore};
use MajorDoMo\Core\WebSockets\{Access, Tickets, Protocol, SiteDomain};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (getenv('APP_ENV') !== 'testing' || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Test isolation failed');
$fixture = sys_get_temp_dir() . '/mjd-ws-' . bin2hex(random_bytes(6));
mkdir($fixture . '/var/sessions', 0700, true);
\MajorDoMo\Core\Config::boot($fixture);
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    ++$checks;
}
function denies(callable $operation, int $status): void {
    try { $operation(); } catch (ApiException $error) { check($error->status === $status, 'expected denial ' . $status); return; }
    throw new RuntimeException('Expected authentication failure');
}
function message(string $action, array $data = array()): string { return json_encode(compact('action', 'data'), JSON_THROW_ON_ERROR); }
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) { foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name); rmdir($path); }
    elseif (is_file($path)) unlink($path);
};
try {
    $db->exec('CREATE TABLE api_tokens (ID INTEGER PRIMARY KEY,NAME TEXT,TOKEN_HASH TEXT,PREFIX TEXT,CREATED_AT INTEGER,EXPIRES_AT INTEGER,REVOKED_AT INTEGER,LAST_USED_AT INTEGER)');
    $tokens = new TokenStore($db);
    $issued = $tokens->issue('fixture');
    $ticketStore = new Tickets($fixture);
    $user = array('id' => 7, 'stamp' => 'stamp');
    $state = static function ($id) use (&$user) { return $id === 7 ? $user : null; };
    $access = new Access(new AccessPolicy(new NetworkAccess()), $ticketStore, fn($token) => $tokens->verify($token), $state, 'home.test');
    $remote = array('REMOTE_ADDR' => '203.0.113.10', 'HTTP_HOST' => 'home.test:8001');
    $local = array('REMOTE_ADDR' => '192.168.1.2', 'HTTP_HOST' => 'home.test:8001');
    check($access->authorize($local, array())['type'] === 'local', 'LAN requires no token');
    check($access->authorize(array('REMOTE_ADDR' => '::1'), array())['type'] === 'local', 'IPv6 loopback');
    denies(fn() => $access->authorize($remote, array()), 401);
    denies(fn() => $access->authorize($local, array('token' => 'invalid')), 401);
    denies(fn() => $access->authorize($local + array('HTTP_ORIGIN' => 'https://evil.test'), array()), 403);
    denies(fn() => $access->authorize($local + array('HTTP_ORIGIN' => 'null'), array()), 403);
    denies(fn() => $access->authorize($local + array('HTTP_SEC_FETCH_SITE' => 'cross-site'), array()), 403);
    check($access->authorize($local + array('HTTP_ORIGIN' => 'https://home.test'), array())['type'] === 'local', 'same host direct port');
    // nginx по умолчанию оставляет в Host адрес апстрима: исходный хост берём
    // из X-Forwarded-Host доверенного прокси или из публичного адреса проекта.
    $behindProxy = array('REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8001', 'HTTP_ORIGIN' => 'https://home.test', 'HTTP_X_FORWARDED_HOST' => 'home.test');
    check($access->authorize($behindProxy, array())['type'] === 'local', 'forwarded host restores the origin');
    check($access->authorize(array('REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8001', 'HTTP_ORIGIN' => 'https://home.test'), array())['type'] === 'local', 'public address accepts origin without forwarded host');
    denies(fn() => $access->authorize(array('REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8001', 'HTTP_ORIGIN' => 'https://other.test'), array()), 403);
    denies(fn() => $access->authorize(array('REMOTE_ADDR' => '203.0.113.10', 'HTTP_HOST' => '127.0.0.1:8001', 'HTTP_ORIGIN' => 'https://evil.test', 'HTTP_X_FORWARDED_HOST' => 'evil.test'), array('token' => $issued['token'])), 403);
    $site = static fn(string $url) => new Access(new AccessPolicy(new NetworkAccess()), $ticketStore, fn($token) => $tokens->verify($token), $state, $url);
    $domainAccess = $site('https://siteA.example.com:443/panel/');
    foreach (array('https://example.com', 'https://siteA.example.com', 'https://ui.example.com', 'http://deep.ui.example.com:8080', 'https://EXAMPLE.COM.') as $origin) {
        check($domainAccess->authorize($local + array('HTTP_ORIGIN' => $origin), array())['type'] === 'local', 'BASE_URL site accepts ' . $origin);
    }
    check($domainAccess->authorize($local + array('HTTP_ORIGIN' => 'https://example.com', 'HTTP_SEC_FETCH_SITE' => 'cross-site'), array())['type'] === 'local', 'explicit Origin policy also permits a different scheme');
    foreach (array('https://other.com', 'https://notexample.com', 'https://example.com.evil.test', 'https://evil-example.com', 'https://com', 'https://example.com/path', 'https://example.com@evil.test', 'https://example.com,https://evil.test', 'null') as $origin) {
        denies(fn() => $domainAccess->authorize($local + array('HTTP_ORIGIN' => $origin), array('token' => $issued['token'])), 403);
    }
    denies(fn() => $domainAccess->authorize(array('REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'other.com', 'HTTP_X_FORWARDED_HOST' => 'other.com', 'HTTP_ORIGIN' => 'https://other.com', 'HTTP_SEC_FETCH_SITE' => 'same-site'), array('token' => $issued['token'])), 403);
    denies(fn() => $domainAccess->authorize($remote + array('HTTP_ORIGIN' => 'https://example.com'), array()), 401);
    check($domainAccess->authorize($remote + array('HTTP_ORIGIN' => 'https://ui.example.com'), array('token' => $issued['token']))['type'] === 'token', 'allowed subdomain still needs a valid external token');
    denies(fn() => $domainAccess->authorize($local + array('HTTP_ORIGIN' => 'https://example.com'), array('token' => 'invalid')), 401);
    $uk = $site('https://admin.example.co.uk');
    check($uk->authorize($local + array('HTTP_ORIGIN' => 'https://example.co.uk'), array())['type'] === 'local', 'multi-label public suffix retains owner boundary');
    denies(fn() => $uk->authorize($local + array('HTTP_ORIGIN' => 'https://other.co.uk'), array()), 403);
    denies(fn() => $uk->authorize($local + array('HTTP_ORIGIN' => 'https://co.uk'), array()), 403);
    $hosting = $site('https://admin.my-home.github.io');
    check($hosting->authorize($local + array('HTTP_ORIGIN' => 'https://my-home.github.io'), array())['type'] === 'local', 'private suffix retains tenant boundary');
    denies(fn() => $hosting->authorize($local + array('HTTP_ORIGIN' => 'https://another-home.github.io'), array()), 403);
    foreach (array('http://192.168.0.200', 'http://[2001:db8::1]:8001', 'http://localhost') as $url) {
        $exact = $site($url);
        check($exact->authorize($local + array('HTTP_ORIGIN' => $url), array())['type'] === 'local', 'IP or single-label public address accepts only itself');
        denies(fn() => $exact->authorize($local + array('HTTP_ORIGIN' => 'https://other.test'), array()), 403);
    }
    denies(fn() => $site('http://localhost')->authorize($local + array('HTTP_ORIGIN' => 'http://ui.localhost'), array()), 403);
    denies(fn() => $site('http://192.168.0.200')->authorize($local + array('HTTP_ORIGIN' => 'http://192.168.0.201'), array()), 403);
    check(SiteDomain::registered('admin.home.test') === 'home.test', 'unknown or test suffix uses the default PSL rule');
    check(SiteDomain::registered('admin.home.ck') === 'admin.home.ck', 'PSL wildcard includes an additional suffix label');
    check(SiteDomain::registered('admin.www.ck') === 'www.ck', 'PSL exception overrides wildcard');
    check(SiteDomain::registered('co.uk') === null && SiteDomain::registered('github.io') === null, 'public and private suffixes cannot become whole shared sites');
    check(SiteDomain::normalize('[2001:0db8:0:0:0:0:0:1]') === '2001:db8::1', 'IPv6 is canonicalized');
    $idn = $site('https://admin.xn--e1afmkfd.xn--p1ai');
    check($idn->authorize($local + array('HTTP_ORIGIN' => 'https://xn--e1afmkfd.xn--p1ai'), array())['type'] === 'local', 'punycode domain and parent are accepted');
    if (function_exists('idn_to_ascii')) check($site('https://админ.пример.рф')->authorize($local + array('HTTP_ORIGIN' => 'https://xn--e1afmkfd.xn--p1ai'), array())['type'] === 'local', 'Unicode setting is normalized to browser ASCII Origin');
    check($access->authorize($remote + array('HTTP_AUTHORIZATION' => 'Bearer ' . $issued['token']), array())['type'] === 'token', 'Bearer access');
    check($access->authorize($remote + array('HTTP_X_API_KEY' => $issued['token']), array())['type'] === 'token', 'X-API-Key access');
    $identity = $access->authorize($remote, array('token' => $issued['token']));
    check($access->valid($identity), 'token connection valid');
    $tokens->revoke($issued['id']);
    check(!$access->valid($identity), 'connected token revoked');
    $expired = $tokens->issue('expired', time() + 20);
    $db->exec('UPDATE api_tokens SET EXPIRES_AT=1 WHERE ID=' . $expired['id']);
    denies(fn() => $access->authorize($remote, array('token' => $expired['token'])), 401);
    $proxied = new Access(new AccessPolicy(new NetworkAccess(), '', '127.0.0.1/32'), $ticketStore, fn($token) => $tokens->verify($token), $state);
    denies(fn() => $proxied->authorize(array('REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'), array()), 401);
    check($proxied->authorize(array('REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '192.168.1.8'), array())['type'] === 'local', 'trusted proxy LAN');
    denies(fn() => $access->authorize($local + array('HTTP_X_FORWARDED_FOR' => '127.0.0.1'), array()), 401);
    $custom = new Access(new AccessPolicy(new NetworkAccess(), '192.168.50.0/24'), $ticketStore, fn($token) => false, $state);
    denies(fn() => $custom->authorize($local, array()), 401);

    $sid = str_repeat('a', 32);
    file_put_contents($fixture . '/var/sessions/sess_' . $sid, 'fixture');
    $ticket = $ticketStore->issue($user, $sid, time());
    $identity = $access->authorize($remote, array('ticket' => $ticket));
    check($identity['type'] === 'session' && $access->valid($identity), 'session ticket accepted');
    denies(fn() => $access->authorize($remote, array('ticket' => $ticket)), 401);
    $foreignTicket = $ticketStore->issue($user, $sid, time(), null, 'other.com');
    denies(fn() => $domainAccess->authorize($local + array('HTTP_ORIGIN' => 'https://other.com'), array('ticket' => $foreignTicket)), 403);
    $user['stamp'] = 'password-changed';
    check(!$access->valid($identity), 'password change invalidates connected session');
    $user['stamp'] = 'stamp';
    unlink($fixture . '/var/sessions/sess_' . $sid);
    check(!$access->valid($identity), 'logout deletes old session and invalidates socket');
    file_put_contents($fixture . '/var/sessions/sess_' . $sid, 'fixture');
    $ticket = $ticketStore->issue($user, $sid, time() - 50, time() - 40);
    denies(fn() => $access->authorize($remote, array('ticket' => $ticket)), 401);
    check(!$ticketStore->valid(array('session' => $sid, 'id' => 7, 'stamp' => 'stamp', 'expires' => time() - 1), $state), 'session expiration');

    // Билет запоминает каталог сессий: сервер WebSocket работает отдельным
    // процессом и не обязан видеть тот же session.save_path, что php-fpm.
    $customSessions = $fixture . '/var/custom-sessions';
    mkdir($customSessions, 0700, true);
    file_put_contents($customSessions . '/sess_' . $sid, 'fixture');
    unlink($fixture . '/var/sessions/sess_' . $sid);
    $issuer = new Tickets($fixture, $customSessions);
    $moved = (new Tickets($fixture))->consume($issuer->issue($user, $sid, time()));
    check(is_array($moved) && ($moved['sessions'] ?? '') === str_replace('\\', '/', $customSessions), 'ticket keeps the session directory');
    check((new Tickets($fixture))->valid($moved, $state), 'ticket validates against a non-default session directory');
    $unsafe = $moved;
    $unsafe['sessions'] = $fixture . '/../' . basename($fixture);
    check(!(new Tickets($fixture))->valid($unsafe, $state), 'unsafe session path is rejected');
    try { (new Tickets($fixture))->issue($user, str_repeat('c', 32), time()); throw new LogicException('Expected missing session failure'); }
    catch (RuntimeException $error) { check($error->getMessage() !== '', 'missing session file explains the reason'); }

    $db->exec('CREATE TABLE classes (ID INTEGER PRIMARY KEY,PARENT_ID INTEGER)');
    $db->exec('CREATE TABLE objects (ID INTEGER PRIMARY KEY,TITLE TEXT COLLATE NOCASE,CLASS_ID INTEGER)');
    $db->exec('CREATE TABLE properties (ID INTEGER PRIMARY KEY,TITLE TEXT,CLASS_ID INTEGER,OBJECT_ID INTEGER)');
    $db->exec('CREATE TABLE pvalues (ID INTEGER PRIMARY KEY,OBJECT_ID INTEGER,PROPERTY_ID INTEGER,PROPERTY_NAME TEXT,VALUE TEXT,SOURCE TEXT,UPDATED TEXT)');
    $db->exec("INSERT INTO classes VALUES (1,0),(2,1); INSERT INTO objects VALUES (1,'Room',2),(2,'Other',1)");
    $db->exec("INSERT INTO properties VALUES (1,'temp',1,0),(2,'temp',2,0),(3,'empty',1,0),(4,'temp',0,1),(5,'zero',0,1)");
    $db->exec("INSERT INTO pvalues (ID,OBJECT_ID,PROPERTY_ID,PROPERTY_NAME,VALUE) VALUES (1,1,1,'Room.temp','stale'),(2,1,4,'Room.temp','21'),(3,1,5,'Room.zero','0'),(4,2,1,'Other.temp','17')");
    $db->exec("UPDATE pvalues SET SOURCE='mqtt',UPDATED='2026-10-22 10:20:30' WHERE ID=2");
    $adapter = new class($db) {
        public function __construct(private PDO $db) {}
        public function Select(string $sql): array { return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
        public function Exec(string $sql, bool $ignore = false): int|false { return $this->db->exec($sql); }
        public function DbSafe1(mixed $value): string { return substr($this->db->quote(is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string)$value), 1, -1); }
    };
    $source = new PropertyValues(new Database($adapter));
    $rows = array_merge(...iterator_to_array($source->pages(1), false));
    $values = array_column($rows, 'VALUE', 'PROPERTY');
    check(count($values) === 5 && $values['Room.temp'] === '21', 'snapshot resolves object overrides and both objects');
    check(array_key_exists('Room.empty', $values) && $values['Room.empty'] === null && $values['Room.zero'] === '0', 'unset values and zero preserved');
    check($source->named(array('room.TEMP'))[0]['VALUE'] === '21', 'bulk named read follows resolution');
    $metadata = $source->named(array('Room.temp'))[0];
    check($metadata['SOURCE'] === 'mqtt' && $metadata['UPDATED'] === '2026-10-22 10:20:30', 'live values include the saved source and timestamp');
    $snapshot = array_column($rows, null, 'PROPERTY');
    check($snapshot['Room.temp']['SOURCE'] === 'mqtt' && $snapshot['Room.temp']['UPDATED'] === $metadata['UPDATED'], 'snapshot includes the same saved metadata');
    check($snapshot['Room.empty']['SOURCE'] === '' && $snapshot['Room.empty']['UPDATED'] === null, 'unset property has no invented source or update time');
    check(count($source->named(array('Room'))) === 3, 'whole object read');
    $db->exec("DELETE FROM properties WHERE ID=4; INSERT INTO pvalues (ID,OBJECT_ID,PROPERTY_ID,PROPERTY_NAME,VALUE) VALUES (5,1,2,'Room.temp','child')");
    check($source->named(array('Room.temp'))[0]['VALUE'] === 'child', 'child class overrides stale parent/object values');
    check($source->named(array('Room.temp'))[0]['SOURCE'] === '' && $source->named(array('Room.temp'))[0]['UPDATED'] === null, 'override metadata does not leak from a stale property row');

    $db->exec('CREATE TABLE cached_ws (PROPERTY TEXT PRIMARY KEY,DATAVALUE TEXT,POST_ACTION TEXT,ADDED TEXT,REVISION TEXT)');
    $database = new Database($adapter);
    $redis = new \MajorDoMo\Core\RedisConnection();
    $queue = new \MajorDoMo\Core\WebSocketQueue($database, $redis, new \MajorDoMo\Core\OperationsQueue($database, $redis));
    $services = new ReflectionProperty(\MajorDoMo\Core\Core::class, 'services');
    $services->setValue(null, array('websockets' => $queue));
    require_once dirname(__DIR__) . '/lib/core/Helpers/WebSockets.php';
    check(postToWebSocket('Room.temp', 'value') === 1 && count($queue->claim(10)) === 1, 'legacy sender accepts a queued publication without any socket');
    $old = $queue->claim(1)[0];
    postToWebSocketQueue('Room.temp', 'new-value');
    $queue->acknowledge($old);
    check($queue->claim(1)[0]['DATAVALUE'] === 'new-value', 'acknowledging old revision preserves concurrent update');
    check(postToWebSocket(array('one.a','two.b'), array(0,'text')) === true && count($queue->claim(10)) === 3, 'legacy array sender queues all properties');
    $event = array('message' => 'тест');
    postToWebSocketQueue('SAY', $event, 'PostEvent');
    $events = array_values(array_filter($queue->claim(10), static fn($item) => $item['POST_ACTION'] === 'PostEvent'));
    check(json_decode($events[0]['DATAVALUE'], true) === $event, 'event payload survives database queue');
    foreach ($queue->claim(10) as $item) $queue->acknowledge($item);
    check($queue->claim(10) === array(), 'published queue entries acknowledged');
    check(getWebSocketClientPath() === '/majordomo', 'legacy path contains no permanent credential');
    try { postToWebSocket(array('a','b'), array(1)); throw new RuntimeException('Expected mismatched arrays failure'); }
    catch (InvalidArgumentException $error) { check($queue->claim(10) === array(), 'mismatched arrays rejected before publication'); }

    $sent = array(); $closed = array(); $current = array('Room.temp' => 'initial');
    $pages = static function () use (&$current): Generator {
        yield array(array('PROPERTY' => 'Room.temp', 'VALUE' => $current['Room.temp']));
        yield array(array('PROPERTY' => 'Other.temp', 'VALUE' => '17'));
    };
    $named = static function ($names) use (&$current): array {
        $result = array();
        foreach ($names as $name) foreach ($current as $key => $value) if (strcasecmp($name, $key) === 0) $result[] = array('PROPERTY' => $key, 'VALUE' => $value);
        return $result;
    };
    $app = new Protocol($pages, $named, 8, 8, 2048);
    $send = static function ($json) use (&$sent): void { $row = json_decode($json, true); $row['decoded'] = json_decode($row['data'], true); $sent[] = $row; };
    $close = static function ($code, $reason) use (&$closed): void { $closed[] = array($code, $reason); };
    $app->connect('one', $send, $close);
    check(array_column($sent, 'action') === array('ready', 'snapshot_start'), 'default starts full snapshot without Subscribe');
    $app->tick();
    $current['Room.temp'] = 'fresh';
    $app->publish(array(array('PROPERTY' => 'Room.temp', 'VALUE' => 'stale-queued')));
    $app->tick(); $app->tick();
    $updates = array_values(array_filter($sent, static fn($row) => $row['action'] === 'properties'));
    check(end($updates)['decoded'][0]['VALUE'] === 'fresh' && end($sent)['action'] === 'snapshot_end', 'snapshot race reconciles current value before completion');
    foreach (array('SetGlobal','CallMethod','RunScript','PostProperty','PostEvent','SetFilename') as $action) {
        $app->receive('one', message($action, array('PROPERTY' => 'Room.temp', 'VALUE' => 'bad')));
        check(end($sent)['decoded']['CODE'] === 'read_only', $action . ' denied');
    }
    $app->receive('one', '{broken');
    check(end($sent)['decoded']['CODE'] === 'invalid_request', 'invalid JSON rejected without stopping server');
    $app->receive('one', message('Subscribe', array('PROPERTIES' => 'Room', 'REPLACE' => true)));
    $before = count($sent);
    $app->publish(array(array('PROPERTY' => 'Other.temp', 'VALUE' => 'ignored')));
    check(count($sent) === $before, 'selective object prefix filter');
    $app->receive('one', message('Subscribe', array('PROPERTIES' => 'a,b,c,d,e,f,g,h,i')));
    check(end($sent)['decoded']['CODE'] === 'invalid_request', 'subscription limit');
    $app->publish(array(array('PROPERTY' => 'Room.temp', 'VALUE' => 'queued')));
    check(end($sent)['action'] === 'properties' && end($sent)['decoded'][0]['VALUE'] === 'fresh', 'rejected subscription retains previous selection');
    $app->receive('one', message('Subscribe', array('TYPE' => 'events', 'EVENTS' => 'SAY')));
    $app->event('SAY', array('message' => 'hello'));
    check(end($sent)['action'] === 'events' && end($sent)['decoded']['EVENT_DATA']['VALUE']['message'] === 'hello', 'trusted event publisher');
    $app->disconnect('one');
    check($app->count() === 0, 'disconnect releases state');
    $app->connect('two', $send, $close, false);
    check(end($sent)['action'] === 'ready' && !end($sent)['decoded']['SNAPSHOT'], 'snapshot can be disabled');
    $app->receive('two', message('GetProperties', array('PROPERTIES' => array('Room.temp'), 'REQUEST_ID' => 'read')));
    check(end($sent)['decoded']['REQUEST_ID'] === 'read' && end($sent)['decoded']['DATA'][0]['VALUE'] === 'fresh', 'read request and correlation');
    $limited = new Protocol($pages, $named, 8, 1, 2048, 1024);
    $limited->connect('limited', $send, $close);
    $limited->publish(array(array('PROPERTY' => 'x.a', 'VALUE' => 1), array('PROPERTY' => 'x.b', 'VALUE' => 2)));
    check(end($closed)[1] === 'resync_required', 'snapshot buffer bounded');
    $limited->disconnect('limited');
    $closed = array();
    for ($index = 0; $index < 100; $index++) $app->receive('two', message('Ping'));
    check(!$closed && end($sent)['action'] === 'pong', 'request frequency has no artificial limit');
    $sent = array();
    printf("WebSocket smoke: %d passed (SQLite, authentication, snapshot, read-only, subscriptions, limits).\n", $checks);
} finally { $remove($fixture); }
