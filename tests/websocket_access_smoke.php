<?php
/**
 * Проверка Origin для WebSocket: локальные localhost/127.0.0.1 совместимы,
 * посторонний домен по-прежнему отклоняется.
 *
 * Запуск: php tests/websocket_access_smoke.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use MajorDoMo\Core\Api\AccessPolicy;
use MajorDoMo\Core\Api\ApiException;
use MajorDoMo\Core\NetworkAccess;
use MajorDoMo\Core\WebSockets\Access;
use MajorDoMo\Core\WebSockets\Tickets;

$root = sys_get_temp_dir() . '/mjd-ws-access-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0777, true) && !is_dir($root)) {
    fwrite(STDERR, "Cannot create temporary directory\n");
    exit(1);
}

$failures = array();
$check = static function (string $name, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
        echo 'FAIL: ' . $name . PHP_EOL;
    } else {
        echo 'ok: ' . $name . PHP_EOL;
    }
};

$make = static function (string $publicHost) use ($root): Access {
    return new Access(
        new AccessPolicy(new NetworkAccess(), '', '', $publicHost),
        new Tickets($root),
        static fn(): bool => false,
        static fn(): ?array => null,
        $publicHost
    );
};

$server = static fn(string $origin): array => array(
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_ORIGIN' => $origin,
);

$local = $make('http://localhost');
$check('localhost Origin accepted', $local->authorize($server('http://localhost'), array())['type'] === 'local');
$check('127.0.0.1 Origin accepted for localhost BASE_URL', $local->authorize($server('http://127.0.0.1'), array())['type'] === 'local');
$check('LAN Origin accepted for localhost BASE_URL', $local->authorize($server('http://192.168.1.2'), array())['type'] === 'local');

$ip = $make('http://127.0.0.1');
$check('localhost Origin accepted for 127.0.0.1 BASE_URL', $ip->authorize($server('http://localhost'), array())['type'] === 'local');

$lan = $make('http://192.168.1.2');
$check('localhost Origin accepted for LAN BASE_URL', $lan->authorize($server('http://localhost'), array())['type'] === 'local');
$check('LAN Origin accepted for LAN BASE_URL', $lan->authorize($server('http://192.168.1.2'), array())['type'] === 'local');

$rejected = false;
try {
    $local->authorize($server('http://evil.example'), array());
} catch (ApiException $error) {
    $rejected = $error->status === 403;
}
$check('foreign Origin rejected', $rejected);

$rejected = false;
try {
    $make('http://home.example')->authorize($server('http://127.0.0.1'), array());
} catch (ApiException $error) {
    $rejected = $error->status === 403;
}
$check('loopback Origin rejected for public BASE_URL', $rejected);

$rejected = false;
try {
    $lan->authorize($server('http://evil.example'), array());
} catch (ApiException $error) {
    $rejected = $error->status === 403;
}
$check('foreign Origin rejected for LAN BASE_URL', $rejected);

@rmdir($root . '/var/security/websocket-tickets');
@rmdir($root . '/var/security');
@rmdir($root . '/var');
@rmdir($root);

if ($failures) {
    echo PHP_EOL . 'FAILED: ' . count($failures) . PHP_EOL;
    exit(1);
}
echo PHP_EOL . 'WebSocket access smoke tests passed.' . PHP_EOL;
exit(0);
