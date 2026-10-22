<?php

/** Loopback MQTT transport + real WebSocket application; no working environment. */
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('USE_REDIS=false');
$sourceRoot = dirname(__DIR__);
require_once $sourceRoot . '/vendor/autoload.php';

function packet(string $body, int $header = 0x30): string
{
    $length = strlen($body);
    $encoded = chr($header);
    do {
        $digit = $length & 127;
        $length >>= 7;
        $encoded .= chr($digit | ($length ? 128 : 0));
    } while ($length);
    return $encoded . $body;
}
function publishPacket(string $topic, string $value): string
{
    return packet(pack('n', strlen($topic)) . $topic . $value);
}
function readBrokerPacket($stream): string
{
    $header = fread($stream, 1);
    if ($header === '') throw new RuntimeException('Missing broker fixture packet');
    $length = 0;
    $multiplier = 1;
    do {
        $byte = fread($stream, 1);
        if ($byte === '') throw new RuntimeException('Missing fixture length');
        $digit = ord($byte);
        $length += ($digit & 127) * $multiplier;
        $multiplier *= 128;
    } while ($digit & 128);
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($stream, $length - strlen($body));
        if ($chunk === '') throw new RuntimeException('Truncated broker fixture packet');
        $body .= $chunk;
    }
    return $header . $body;
}

if (($argv[1] ?? '') === 'broker-fixture') {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$listener) throw new RuntimeException('Cannot bind loopback fixture');
    echo stream_socket_get_name($listener, false) . PHP_EOL;
    flush();
    $peer = stream_socket_accept($listener, 5);
    if (!$peer) throw new RuntimeException('Fixture connection timed out');
    stream_set_timeout($peer, 3);
    readBrokerPacket($peer); // CONNECT
    fwrite($peer, "\x20\x02\x00\x00");
    readBrokerPacket($peer); // SUBSCRIBE
    fwrite($peer, "\x90\x03\x00\x01\x00");
    fwrite($peer, publishPacket('zigbee/retained', json_encode(array('sent' => microtime(true), 'sequence' => 0))));
    readBrokerPacket($peer); // second SUBSCRIBE must not consume retained data
    fwrite($peer, "\x90\x03\x00\x01\x00");
    fwrite($peer, publishPacket('other/retained', json_encode(array('sent' => microtime(true), 'sequence' => 1))));
    for ($i = 0; $i < 5; $i++) {
        usleep(20000);
        $sent = microtime(true);
        fwrite($peer, publishPacket('zigbee/sensor', json_encode(array('sent' => $sent, 'sequence' => $i + 2))));
    }
    fclose($peer);
    fclose($listener);
    exit;
}

$pdo = new PDO('sqlite::memory:');
if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Test isolation failed');
$fixture = sys_get_temp_dir() . '/mjd-network-memory-' . bin2hex(random_bytes(6));
require_once $sourceRoot . '/lib/core.class.php';
\MajorDoMo\Core\Config::boot($fixture);
$originalDirectory = getcwd();
chdir($fixture);



$checks = 0;
$logs = array();
$globalValues = array();
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
}
function DebMes($message, $channel, $severity = '')
{
    $GLOBALS['logs'][] = $message;
    if (count($GLOBALS['logs']) > 20) array_shift($GLOBALS['logs']);
}
function gg($name) { return $GLOBALS['globalValues'][$name] ?? 0; }
function setGlobal($name, $value, $noLinked = 0) { $GLOBALS['globalValues'][$name] = $value; }
function getGlobal($name) { return 'fresh:' . $name; }

class MemoryTestMqtt extends \Bluerhinos\phpMQTT
{
    public function attach($stream): void
    {
        $this->socket = $stream;
        stream_set_blocking($stream, false);
        $this->timesinceping = time();
    }
    public function bodyBytes(): int
    {
        return strlen((new ReflectionProperty(\Bluerhinos\phpMQTT::class, 'incomingBody'))->getValue($this));
    }
}
function mqttPair(int $limit = 1048576, float $timeout = 0.05): array
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$listener) throw new RuntimeException('Cannot create loopback listener');
    $peer = stream_socket_client('tcp://' . stream_socket_get_name($listener, false), $errno, $error, 1);
    $socket = stream_socket_accept($listener, 1);
    fclose($listener);
    if (!$peer || !$socket) throw new RuntimeException('Cannot create loopback pair');
    $mqtt = new MemoryTestMqtt('unused', 0, 'fixture');
    $mqtt->setReadLimits($limit, $timeout);
    $mqtt->attach($socket);
    return array($mqtt, $peer);
}
class MemoryTestWsClient
{
    public array $subscribedTo = array();
    public array $watchedProperties = array();
    public array $lastMessage = array();
    public int $sent = 0;
    public function getClientId() { return 'fixture-client'; }
    public function getClientIp() { return 'fixture'; }
    public function send($message)
    {
        $this->sent++;
        $this->lastMessage = json_decode($message, true);
        $this->lastMessage['decoded'] = json_decode($this->lastMessage['data'], true);
    }
}
function wsAction($app, $client, string $action, array $data): void
{
    $app->receive('fixture-client', json_encode(array('action' => $action, 'data' => $data)));
}
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (scandir($path) as $item) if ($item !== '.' && $item !== '..') $remove($path . '/' . $item);
        rmdir($path);
    } elseif (is_file($path)) unlink($path);
};
$exitCode = 0;
try {
    [$mqtt, $peer] = mqttPair();
    $received = array();
    $mqtt->topics['zigbee/#'] = array('function' => static function ($topic, $value) use (&$received) { $received[] = array($topic, $value); });
    $frame = publishPacket('zigbee/door', '{"contact":true}');
    foreach (str_split($frame) as $byte) {
        fwrite($peer, $byte);
        check($mqtt->proc(false) === true, 'fragmented MQTT packet remains valid');
    }
    check($received === array(array('zigbee/door', '{"contact":true}')), 'fragmented packet is delivered exactly once');
    fwrite($peer, "\xd0\x00" . publishPacket('zigbee/door', 'second') . publishPacket('zigbee/door', 'third'));
    for ($i = 0; $i < 3; $i++) $mqtt->proc(false);
    check(array_column($received, 1) === array('{"contact":true}', 'second', 'third'), 'coalesced packets retain order across PINGRESP');
    $mqtt->timesinceping = time() - 60;
    fwrite($peer, publishPacket('zigbee/door', 'active'));
    check($mqtt->proc(false), 'continuous incoming traffic remains responsive');
    stream_set_timeout($peer, 1);
    check(fread($peer, 2) === "\xc0\x00", 'keepalive is sent even during continuous sensor traffic');
    $mqtt->close(); fclose($peer);

    [$mqtt, $peer] = mqttPair(128);
    $received = array();
    $mqtt->topics['zigbee/#'] = array('function' => static function ($topic, $value) use (&$received) { $received[] = $value; });
    fwrite($peer, publishPacket('zigbee/large', str_repeat('x', 5000)) . publishPacket('zigbee/ok', 'after-large'));
    for ($i = 0; $i < 3; $i++) $mqtt->proc(false);
    check($received === array('after-large') && $mqtt->bodyBytes() === 0, 'oversized QoS0 packet is drained without losing the following message');
    check(str_contains(implode('\n', $logs), 'discarding'), 'oversized packet is reported');
    $mqtt->close(); fclose($peer);

    [$mqtt, $peer] = mqttPair();
    fwrite($peer, "\x30\xff\xff\xff\xff");
    check($mqtt->proc(false) === false, 'malformed Remaining Length is rejected');
    $mqtt->close(); fclose($peer);
    [$mqtt, $peer] = mqttPair();
    fwrite($peer, "\x30\x64partial");
    $start = microtime(true);
    check($mqtt->proc(false) && microtime(true) - $start < 0.04, 'incomplete packet returns control without busy waiting');
    usleep(60000);
    check($mqtt->proc(false) === false && $mqtt->bodyBytes() === 0, 'partial packet expires and releases memory');
    $mqtt->close(); fclose($peer);
    [$mqtt, $peer] = mqttPair();
    $start = microtime(true);
    check($mqtt->read(4) === false && microtime(true) - $start < 0.3, 'synchronous handshake read has a deadline');
    $mqtt->close(); fclose($peer);
    [$mqtt, $peer] = mqttPair();
    fwrite($peer, packet("\x00\xffshort"));
    check($mqtt->proc(false) === false, 'invalid topic length is rejected');
    $mqtt->close(); fclose($peer);

    [$mqtt, $peer] = mqttPair();
    $received = array();
    $mqtt->topics['zigbee/#'] = array('function' => static function ($topic, $value) use (&$received) { $received[] = $value; });
    fwrite($peer, packet(pack('n', 250) . 'zigbee/door' . "\x00\x07value", 0x32));
    // topic length is deliberately invalid first, then test a valid QoS1 frame.
    check($mqtt->proc(false) === false, 'malformed QoS1 payload is rejected');
    $mqtt->close(); fclose($peer);
    [$mqtt, $peer] = mqttPair();
    $mqtt->topics['zigbee/#'] = array('function' => static function ($topic, $value) use (&$received) { $received[] = $value; });
    fwrite($peer, packet(pack('n', strlen('zigbee/door')) . 'zigbee/door' . "\x00\x07value", 0x32));
    check($mqtt->proc(false) === true && end($received) === 'value', 'QoS1 packet identifier is excluded from sensor payload');
    stream_set_timeout($peer, 1);
    check(fread($peer, 4) === "\x40\x02\x00\x07", 'QoS1 delivery is acknowledged');
    $mqtt->close(); fclose($peer);

    [$mqtt, $peer] = mqttPair();
    $commandBody = pack('n', strlen('zigbee/cmd')) . 'zigbee/cmd';
    check($mqtt->publish('zigbee/cmd', 'ON', 0) !== false && readBrokerPacket($peer) === "\x30" . $commandBody . 'ON', 'outgoing sensor command retains its QoS0 wire format');
    check($mqtt->publish('zigbee/cmd', 'OFF', 1) !== false && readBrokerPacket($peer) === "\x32" . $commandBody . "\x00\x01OFF", 'outgoing QoS1 command retains its packet identifier');
    $mqtt->close(); fclose($peer);

    [$mqtt, $peer] = mqttPair();
    $count = 0;
    $mqtt->topics['zigbee/#'] = array('function' => static function ($topic, $value) use (&$count) {
        if ($value !== (string)$count) throw new RuntimeException('Load test delivery order changed');
        $count++;
    });
    $baseline = memory_get_usage();
    $start = microtime(true);
    for ($batch = 0; $batch < 600; $batch++) {
        $bytes = '';
        for ($i = 0; $i < 50; $i++) $bytes .= publishPacket('zigbee/sensor-' . ($batch * 50 + $i), (string)($batch * 50 + $i));
        fwrite($peer, $bytes);
        for ($i = 0; $i < 50; $i++) if (!$mqtt->proc(false)) throw new RuntimeException('Load test connection failed');
    }
    $loadSeconds = microtime(true) - $start;
    $mqttGrowth = memory_get_usage() - $baseline;
    check($count === 30000 && $mqtt->bodyBytes() === 0 && $mqttGrowth < 1048576, '30,000 MQTT messages remain ordered with bounded receiver memory');
    $mqtt->close(); fclose($peer);

    $broker = proc_open(array(PHP_BINARY, __FILE__, 'broker-fixture'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($broker)) throw new RuntimeException('Cannot start local broker fixture');
    fclose($pipes[0]);
    $address = trim(fgets($pipes[1]));
    if (!preg_match('/^127\.0\.0\.1:(\d+)$/D', $address, $match)) throw new RuntimeException('Fixture did not bind loopback');
    $mqtt = new \Bluerhinos\phpMQTT('127.0.0.1', (int)$match[1], 'fixture');
    $mqtt->setReadLimits(1048576, 1);
    check($mqtt->connect(), 'real CONNECT/CONNACK handshake succeeds');
    $latencies = array();
    $onSensor = static function ($topic, $value) use (&$latencies) {
        $data = json_decode($value, true);
        if ($data['sequence'] !== count($latencies)) throw new RuntimeException('Delayed delivery order changed');
        $latencies[] = microtime(true) - $data['sent'];
    };
    $mqtt->subscribe(array('zigbee/#' => array('qos' => 0, 'function' => $onSensor)));
    $mqtt->subscribe(array('other/#' => array('qos' => 0, 'function' => $onSensor)));
    $deadline = microtime(true) + 3;
    while (count($latencies) < 7 && microtime(true) < $deadline && $mqtt->proc()) {}
    $mqtt->close();
    $brokerErrors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($broker) === 0 && $brokerErrors === '' && count($latencies) === 7, 'multiple SUBACKs, retained and delayed messages preserve delivery order');
    check(max($latencies) < 0.25, 'socket waiting wakes promptly on new sensor data');

    $pages = static function (): Generator { if (false) yield array(); };
    $app = new \MajorDoMo\Core\WebSockets\Protocol($pages, static fn($names) => array(), 8, 8, 2048, 1048576,
        static fn($id) => 'object-' . $id);
    $client = new MemoryTestWsClient();
    $app->connect('fixture-client', static fn($message) => $client->send($message), static function ($code, $reason): void {}, false);
    $app->publish(array(array('PROPERTY' => 'room.value', 'VALUE' => 'single')));
    check($client->lastMessage['decoded'][0]['VALUE'] === 'single', 'single property update is delivered correctly');
    $app->publish(array(array('PROPERTY' => 'room.big', 'VALUE' => str_repeat('x', 3000))));
    check(strlen($client->lastMessage['decoded'][0]['VALUE']) === 3000, 'large update is delivered without a value cache');
    $baseline = memory_get_usage();
    for ($i = 0; $i < 10000; $i++) $app->publish(array(array('PROPERTY' => 'dynamic.' . $i, 'VALUE' => str_repeat('v', 320))));
    $wsGrowth = memory_get_usage() - $baseline;
    check($wsGrowth < 1048576, '10,000 unique property updates do not retain a value history');

    wsAction($app, $client, 'Subscribe', array('TYPE' => 'properties', 'PROPERTIES' => 'room.a', 'REPLACE' => true));
    wsAction($app, $client, 'Subscribe', array('TYPE' => 'properties', 'PROPERTIES' => 'room.b'));
    $app->publish(array(array('PROPERTY' => 'room.a', 'VALUE' => 1)));
    check($client->lastMessage['action'] === 'properties', 'legacy subscriptions remain additive');
    wsAction($app, $client, 'Unsubscribe', array('TYPE' => 'properties', 'PROPERTIES' => 'room.a'));
    $before = $client->sent;
    $app->publish(array(array('PROPERTY' => 'room.a', 'VALUE' => 2)));
    check($client->sent === $before, 'explicit unsubscribe removes only requested property');
    $app->publish(array(array('PROPERTY' => 'room.b', 'VALUE' => 3)));
    check($client->sent === $before + 1, 'other subscriptions survive unsubscribe');
    wsAction($app, $client, 'Subscribe', array('TYPE' => 'events', 'EVENTS' => 'SAY'));
    for ($i = 1; $i <= 1000; $i++) wsAction($app, $client, 'Subscribe', array('TYPE' => 'objects', 'OBJECT_ID' => (string)$i));
    $app->publish(array(array('PROPERTY' => 'room1000.state', 'VALUE' => 1, 'OBJECT_ID' => 1000), array('PROPERTY' => 'room999.state', 'VALUE' => 1, 'OBJECT_ID' => 999)));
    check($client->lastMessage['action'] === 'objects' && count($client->lastMessage['decoded']['DATA']) === 1 && $client->lastMessage['decoded']['DATA'][0]['OBJECT_ID'] === 1000, 'object changes replace old watches');
    wsAction($app, $client, 'Subscribe', array('TYPE' => 'properties', 'PROPERTIES' => 'room.c', 'REPLACE' => true));
    $app->event('SAY', 'hello');
    check($client->lastMessage['action'] === 'events', 'replace preserves other subscription types');
    wsAction($app, $client, 'Subscribe', array('TYPE' => 'properties', 'PROPERTIES' => 'a,b,c,d,e,f,g,h,i'));
    check($client->lastMessage['decoded']['SUCCESS'] === 0, 'subscription limit rejects changes atomically');
    $app->publish(array(array('PROPERTY' => 'room.c', 'VALUE' => 1)));
    check($client->lastMessage['action'] === 'properties', 'rejected subscription preserves old selection');
    wsAction($app, $client, 'Unsubscribe', array('TYPE' => 'objects'));
    $before = $client->sent;
    $app->publish(array(array('PROPERTY' => 'room1000.state', 'VALUE' => 1, 'OBJECT_ID' => 1000)));
    check($client->sent === $before, 'object unsubscribe removes object watches');
    $app->disconnect('fixture-client');
    check($app->count() === 0, 'disconnected clients are not retained');
    printf("Network memory smoke: %d passed; MQTT 30000 messages %.3fs, retained growth %d bytes; WebSocket growth %d bytes; loopback max latency %.2fms.\n",
        $checks, $loadSeconds, $mqttGrowth, $wsGrowth, max($latencies) * 1000);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    $exitCode = 1;
} finally {
    chdir($originalDirectory);
    $remove($fixture);
}
exit($exitCode);
