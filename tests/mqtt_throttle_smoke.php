<?php

/** MQTT admission/integration tests: SQLite :memory:, mocked Redis, no application boot. */
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('USE_REDIS=false');
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\RedisConnection;

$fixture = sys_get_temp_dir() . '/mjd-mqtt-' . bin2hex(random_bytes(6));
Config::boot($fixture);
register_shutdown_function(static function () use ($fixture) {
    unlink($fixture . '/cms/cached/config.php');
    rmdir($fixture . '/cms/cached');
    rmdir($fixture . '/cms');
    rmdir($fixture);
});

$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
    throw new RuntimeException('Test isolation was not established');
}
$pdo->exec("CREATE TABLE mqtt (ID INTEGER PRIMARY KEY AUTOINCREMENT, PATH TEXT, TITLE TEXT DEFAULT '', VALUE TEXT DEFAULT '', UPDATED TEXT,
    PATH_WRITE TEXT DEFAULT '', LINKED_OBJECT TEXT DEFAULT '', LINKED_PROPERTY TEXT DEFAULT '', LINKED_METHOD TEXT DEFAULT '',
    LOGGING INTEGER DEFAULT 0, ONLY_NEW_VALUE INTEGER DEFAULT 0, REPLACE_LIST TEXT DEFAULT '')");
$queries = 0;
$writes = 0;
$failWrite = false;
$actions = array();
$checks = 0;

function check($condition, $message)
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function SQLSelect($query)
{
    global $pdo, $queries;
    $queries++;
    return $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
}
function SQLInsert($table, &$row)
{
    global $pdo, $queries, $writes, $failWrite;
    $queries++;
    if ($failWrite) return 0;
    $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    $writes++;
    return (int)$pdo->lastInsertId();
}
function SQLUpdate($table, $row)
{
    global $pdo, $queries, $writes, $failWrite;
    $queries++;
    if ($failWrite) return 0;
    $id = $row['ID'];
    unset($row['ID']);
    $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(fn($key) => $key . '=?', array_keys($row))) . ' WHERE ID=?')->execute(array_merge(array_values($row), array($id)));
    $writes++;
    return 1;
}
function startMeasure($name) {}
function endMeasure($name) {}
function DebMes($message, $source) {}
function callMethod($name, $parameters)
{
    $GLOBALS['actions'][] = array($name, $parameters['VALUE']);
}

class module
{
    public array $config = array();
}
require_once dirname(__DIR__) . '/modules/mqtt/mqtt.class.php';

class TestMqttThrottle extends MqttUnlinkedThrottle
{
    public int $clock = 1000;
    protected function now(): int { return $this->clock; }
    public function count(): int { return count($this->expires); }
}
class TestMqtt extends mqtt
{
    public int $configReads = 0;
    public function __construct(public TestMqttThrottle $throttle)
    {
        $this->config = array('MQTT_STRIPMODE' => 0, 'MQTT_UNLINKED_UPDATE_INTERVAL' => 3600);
    }
    function getUnlinkedThrottle() { return $this->throttle; }
    function getConfig() { $this->configReads++; return $this->config; }
    function setLinkedPropertyFromMqtt($object, $property, $value, $source_url)
    {
        $GLOBALS['actions'][] = array($object . '.' . $property, $value);
    }
    function expireLookup() { $this->lookup_cache_updated = 0; }
}

$throttle = new TestMqttThrottle(new RedisConnection());
$mqtt = new TestMqtt($throttle);
$mqtt->processMessage('new/sensor', '1', false, array());
check($pdo->query("SELECT VALUE FROM mqtt WHERE PATH='new/sensor'")->fetchColumn() === '1', 'New topic must appear on its first message');
$before = $queries;
$mqtt->expireLookup();
$mqtt->processMessage('new/sensor', '2', false, array());
check($queries === $before && $mqtt->configReads === 0, 'Skipped messages must not read config, lookup table, or write to SQL');
$throttle->clock += 3599;
$mqtt->processMessage('new/sensor', '3', false, array());
check($queries === $before, 'Window must not slide on skipped messages');
$throttle->clock++;
$mqtt->processMessage('new/sensor', '4', false, array());
check($pdo->query("SELECT VALUE FROM mqtt WHERE PATH='new/sensor'")->fetchColumn() === '4', 'First message at the interval boundary must update the topic');

$mqtt->config['MQTT_STRIPMODE'] = 1;
$before = $queries;
$mqtt->processMessage('strict/new', '{"temperature":5}', false, array());
$mqtt->processMessage('new/sensor', '5', false, array());
check($queries === $before, 'Strict mode must ignore existing and new unlinked topics including JSON');
$mqtt->config['MQTT_STRIPMODE'] = 0;
$mqtt->config['MQTT_UNLINKED_UPDATE_INTERVAL'] = 0;
$before = $writes;
$mqtt->processMessage('unlimited', '1', false, array());
$mqtt->processMessage('unlimited', '2', false, array());
check($writes === $before + 2, 'Zero interval must disable throttling');
$mqtt->config['MQTT_UNLINKED_UPDATE_INTERVAL'] = 3600;

// A linked JSON descendant must remain responsive while its parent and siblings are throttled.
$pdo->exec("INSERT INTO mqtt(PATH, LINKED_OBJECT, LINKED_PROPERTY) VALUES ('device/temperature','Room','temperature')");
$pdo->exec("INSERT INTO mqtt(PATH, LINKED_OBJECT, LINKED_METHOD) VALUES ('device/nested/state','Room','setState')");
$pdo->exec("INSERT INTO mqtt(PATH, LINKED_OBJECT) VALUES ('incomplete','Room')");
$paths = $mqtt->getLinkedPathsSnapshot(true);
check(count($paths) === 2 && !in_array('incomplete', $paths), 'A real binding needs an object and a property or method');
check(!$mqtt->hasLinkedPathWithPrefixInList('device/temp', $paths), 'Path prefix must respect slash boundaries');
$beforeActions = count($actions);
$mqtt->processMessage('device', '{"temperature":20,"noise":1,"nested":{"state":"on","noise":1}}', false, $paths);
$before = $writes;
$mqtt->processMessage('device', '{"temperature":21,"noise":2,"nested":{"state":"off","noise":2}}', false, $paths);
check($writes === $before + 2 && count($actions) === $beforeActions + 4, 'Linked property and nested method must run on every changed message');
check($pdo->query("SELECT VALUE FROM mqtt WHERE PATH='device/noise'")->fetchColumn() === '1', 'Unlinked JSON sibling must retain its first value during the window');
$mqtt->config['MQTT_STRIPMODE'] = 1;
$before = $writes;
$mqtt->processMessage('device', '{"temperature":22,"noise":3,"nested":{"state":"on","new":1}}', false, $paths);
check($writes === $before + 2 && !$pdo->query("SELECT ID FROM mqtt WHERE PATH='device/nested/new'")->fetchColumn(), 'Strict JSON mode must process linked leaves only');

// On SQL failure the reservation is released and the next incoming message can retry.
$mqtt->config['MQTT_STRIPMODE'] = 0;
$failWrite = true;
try {
    $mqtt->processMessage('retry', '1', false, $paths);
    check(false, 'Failed INSERT must be reported');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'Unable to insert MQTT topic', 'INSERT failure must remain visible');
}
$failWrite = false;
$mqtt->processMessage('retry', '2', false, $paths);
check($pdo->query("SELECT VALUE FROM mqtt WHERE PATH='retry'")->fetchColumn() === '2', 'Failed INSERT must not suppress retries');
$throttle->clock += 3600;
$failWrite = true;
try {
    $mqtt->processMessage('retry', '3', false, $paths);
    check(false, 'Failed UPDATE must be reported');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'Unable to update MQTT topic', 'UPDATE failure must remain visible');
}
$failWrite = false;
$mqtt->processMessage('retry', '4', false, $paths);
check($pdo->query("SELECT VALUE FROM mqtt WHERE PATH='retry'")->fetchColumn() === '4', 'Failed UPDATE must not suppress retries');

// Incoming values must not overwrite a binding changed concurrently in the editor.
$pdo->exec("UPDATE mqtt SET TITLE='edited title' WHERE PATH='retry'");
$throttle->clock += 3600;
$mqtt->processMessage('retry', '5', false, $paths);
check($pdo->query("SELECT TITLE FROM mqtt WHERE PATH='retry'")->fetchColumn() === 'edited title', 'Value updates must not overwrite stale metadata');

// Discovery uses one table read for a burst, not one SELECT * for every new topic.
$bulk = new TestMqtt(new TestMqttThrottle(new RedisConnection()));
$before = $queries;
for ($i = 0; $i < 30000; $i++) $bulk->processMessage('bulk/' . $i, '1', false, array());
check($queries - $before === 30001, '30,000 discovered topics must require only one lookup snapshot plus their INSERTs');
$before = $queries;
for ($i = 0; $i < 30000; $i++) $bulk->processMessage('bulk/' . $i, '2', false, array());
check($queries === $before, '30,000 repeated unlinked messages must make zero SQL calls');
$bounded = new TestMqttThrottle(new RedisConnection(), 32);
for ($i = 0; $i < 1000; $i++) $bounded->allow('bounded/' . $i, 3600);
check($bounded->count() === 32, 'Memory fallback must have a hard entry limit');
$bounded->clock += 3600;
$bounded->allow('after-expiry', 3600);
check($bounded->count() === 1, 'Expired fallback entries must be cleaned up');

// Inject a fake transport into the real RedisConnection; never open a socket.
class MqttFakeRedis
{
    public int $clock = 1000;
    public bool $broken = false;
    public array $data = array();
    public array $calls = array();
    public function set($key, $value, $options)
    {
        $this->calls[] = array($key, $options);
        if ($this->broken) throw new RuntimeException('simulated transport failure');
        if (($this->data[$key]['expires'] ?? 0) > $this->clock) return false;
        $this->data[$key] = array('value' => $value, 'expires' => $this->clock + $options['ex']);
        return true;
    }
    public function ttl($key) { return max(-2, ($this->data[$key]['expires'] ?? 0) - $this->clock); }
    public function eval($script, $args, $numKeys)
    {
        if (($this->data[$args[0]]['value'] ?? null) !== $args[1]) return 0;
        unset($this->data[$args[0]]);
        return 1;
    }
}
function fakeConnection($fake)
{
    $connection = new RedisConnection();
    (new ReflectionProperty(RedisConnection::class, 'connection'))->setValue($connection, $fake);
    return $connection;
}
$configValues = new ReflectionProperty(Config::class, 'values');
$values = $configValues->getValue();
$values['USE_REDIS'] = 'mock-only';
$configValues->setValue(null, $values);
$fake = new MqttFakeRedis();
$first = new TestMqttThrottle(fakeConnection($fake));
$second = new TestMqttThrottle(fakeConnection($fake));
check($first->allow('Case/Topic', 3600) && !$second->allow('Case/Topic', 3600), 'Redis must share admission windows between worker instances');
check($first->allow('case/topic', 3600), 'MQTT topic keys must remain case-sensitive');
check($fake->calls[0][1] === array('nx', 'ex' => 3600), 'Redis reservations must be atomic and expire automatically');
$first->clock = $second->clock = $fake->clock = 4600;
check($second->allow('Case/Topic', 3600), 'Redis reservation must expire at the window boundary');
$second->cancel('Case/Topic', 3600);
check((new TestMqttThrottle(fakeConnection($fake)))->allow('Case/Topic', 3600), 'Cancellation must release the Redis reservation');
check($first->allow('different-interval', 3600) && $first->allow('different-interval', 60), 'Interval changes must take effect immediately');
$fake->broken = true;
$connection = fakeConnection($fake);
$fallback = new TestMqttThrottle($connection);
$log = $fixture . '/redis-test.log';
ini_set('error_log', $log);
check($fallback->allow('outage', 3600) && !$fallback->allow('outage', 3600), 'Redis failure must fall back to memory');
check((new ReflectionProperty(RedisConnection::class, 'connection'))->getValue($connection) === null, 'Broken Redis sockets must be discarded');
check(str_contains(file_get_contents($log), 'bounded cycle memory'), 'Redis fallback must be reported');
unlink($log);

echo 'MQTT smoke tests passed: ' . $checks . ' checks; 30,000 repeated topics: 0 SQL queries.' . PHP_EOL;
