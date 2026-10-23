<?php

/**
 * MQTT binding index and runtime overlay: SQLite/SQL mock + fake Redis.
 * No broker, no real Redis, no production configuration.
 */
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('USE_REDIS=false');
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\RedisConnection;

$fixture = sys_get_temp_dir() . '/mjd-mqtt-index-' . bin2hex(random_bytes(6));
Config::boot($fixture);
register_shutdown_function(static function () use ($fixture) {
    @unlink($fixture . '/cms/cached/config.php');
    @rmdir($fixture . '/cms/cached');
    @rmdir($fixture . '/cms');
    @rmdir($fixture);
});

$rows = array(
    array('ID' => 1, 'PATH' => 'home/lamp/state', 'TITLE' => 'state', 'PATH_WRITE' => 'home/lamp/set',
        'LINKED_OBJECT' => 'Lamp', 'LINKED_PROPERTY' => 'status', 'LINKED_METHOD' => '', 'LOGGING' => 0,
        'ONLY_NEW_VALUE' => 0, 'REPLACE_LIST' => '', 'READONLY' => 0, 'QOS' => 0, 'RETAIN' => 0,
        'WRITE_TYPE' => 0, 'VALUE' => '0'),
    array('ID' => 2, 'PATH' => 'home/lamp/brightness', 'TITLE' => 'brightness', 'PATH_WRITE' => '',
        'LINKED_OBJECT' => 'Lamp', 'LINKED_PROPERTY' => 'brightness', 'LINKED_METHOD' => '', 'LOGGING' => 0,
        'ONLY_NEW_VALUE' => 1, 'REPLACE_LIST' => '', 'READONLY' => 0, 'QOS' => 0, 'RETAIN' => 0,
        'WRITE_TYPE' => 0, 'VALUE' => '50'),
    array('ID' => 3, 'PATH' => 'home/other/state', 'TITLE' => 'state', 'PATH_WRITE' => '',
        'LINKED_OBJECT' => 'Other', 'LINKED_PROPERTY' => 'status', 'LINKED_METHOD' => '', 'LOGGING' => 0,
        'ONLY_NEW_VALUE' => 0, 'REPLACE_LIST' => '', 'READONLY' => 0, 'QOS' => 0, 'RETAIN' => 0,
        'WRITE_TYPE' => 0, 'VALUE' => ''),
    array('ID' => 4, 'PATH' => '/leading/topic', 'TITLE' => 'topic', 'PATH_WRITE' => '',
        'LINKED_OBJECT' => 'Leading', 'LINKED_PROPERTY' => 'value', 'LINKED_METHOD' => '', 'LOGGING' => 0,
        'ONLY_NEW_VALUE' => 0, 'REPLACE_LIST' => '', 'READONLY' => 0, 'QOS' => 0, 'RETAIN' => 0,
        'WRITE_TYPE' => 0, 'VALUE' => ''),
);
$queries = 0;
function SQLSelect($query)
{
    global $rows, $queries;
    $queries++;
    return $rows;
}
function SQLSelectOne($query) { return array(); }
function SQLUpdate($table, $row) { return 1; }
function SQLInsert($table, &$row) { return 1; }
function DBSafe($value) { return str_replace("'", "''", (string)$value); }
function DebMes($message, $source = '', $severity = '') {}
function check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

class FakeRedisForMqtt
{
    public array $data = array();
    public array $hashes = array();

    public function get($key) { return $this->data[$key] ?? false; }
    public function set($key, $value, $options = array())
    {
        if (isset($options['nx']) && array_key_exists($key, $this->data)) return false;
        $this->data[$key] = (string)$value;
        return true;
    }
    public function del($key) { unset($this->data[$key]); return 1; }
    public function incr($key) { $this->data[$key] = (string)(((int)($this->data[$key] ?? 0)) + 1); return (int)$this->data[$key]; }
    public function hGet($key, $field) { return $this->hashes[$key][$field] ?? false; }
    public function hMGet($key, $fields)
    {
        $result = array();
        foreach ($fields as $field) $result[] = $this->hashes[$key][$field] ?? false;
        return $result;
    }
    public function hSet($key, $field, $value) { $this->hashes[$key][$field] = (string)$value; return 1; }
}

$fake = new FakeRedisForMqtt();
$connection = new RedisConnection();
(new ReflectionProperty(RedisConnection::class, 'connection'))->setValue($connection, $fake);

// MqttRuntime::enabled() requires USE_REDIS and the Redis extension. The real
// extension is not needed because the transport is injected below.
if (!class_exists('Redis')) eval('class Redis {}');
$values = (new ReflectionProperty(Config::class, 'values'))->getValue();
$values['USE_REDIS'] = 'mock-only';
$values['MQTT_ASYNC_ENABLED'] = true;
(new ReflectionProperty(Config::class, 'values'))->setValue(null, $values);
$coreRedis = \MajorDoMo\Core\Core::redis();
(new ReflectionProperty(RedisConnection::class, 'connection'))->setValue($coreRedis, $fake);

require_once dirname(__DIR__) . '/modules/mqtt/MqttBindingIndex.php';
require_once dirname(__DIR__) . '/modules/mqtt/MqttRuntime.php';

$index = new MqttBindingIndex($connection);
$index->load();
check($index->record('home/lamp/state')['ID'] === 1, 'Exact binding must be indexed by path');
check($index->isWritePath('home/lamp/set'), 'PATH_WRITE must be indexed');
check($index->isLinked('home/lamp/state'), 'Linked path must be known');
check($index->hasLinkedPrefix('home/lamp'), 'JSON parent must see linked children');
check($index->laneFor('home/lamp') === 'object:Lamp', 'JSON packet lane must be the common object');
check($index->laneFor('home/lamp/state') === 'object:Lamp', 'Binding lane must be the object');
check($index->laneFor('home/unknown') === 'topic:home/unknown', 'Unlinked topic keeps its own lane');
check($index->laneFor('/leading/topic') === 'object:Leading', 'Leading slash must not change binding lookup');
check(count($index->propertyRecords('Lamp', 'status')) === 1, 'Property lookup must be indexed by object/property');
check($index->record('home/lamp/brightness')['VALUE'] === '50', 'ONLY_NEW_VALUE keeps the old value in the snapshot');
check($index->record('home/lamp/state')['VALUE'] === '', 'Regular bindings do not copy values into the snapshot');

// Version change rebuilds the snapshot without a broker reconnect.
$fake->data[$connection->key('mqtt:runtime:bindings')] = '7';
$index->ensureFresh(0.0);
check($index->version() === '7:0', 'Binding version must advance');
check($queries >= 2, 'Version change must rebuild the index');

// Overlay replaces a stale SQL value with the Redis projection.
$updated = date('Y-m-d H:i:s');
$fake->hashes[$connection->key('mqtt:runtime:state')]['home/lamp/state'] = json_encode(array(
    'ID' => 1, 'PATH' => 'home/lamp/state', 'VALUE' => '1', 'UPDATED' => $updated,
));
$sqlRows = array(array('PATH' => 'home/lamp/state', 'VALUE' => '0', 'UPDATED' => '2000-01-01 00:00:00'));
$overlay = \MqttRuntime::overlay($sqlRows);
check($overlay[0]['VALUE'] === '1' && $overlay[0]['UPDATED'] === $updated, 'Overlay must use the newer Redis value');
check(\MqttRuntime::value('home/lamp/state') === '1', 'Runtime value must read the Redis projection');

// Invalidating a binding only increments the version, it does not reconnect.
$index->invalidate();
check((int)$fake->data[$connection->key('mqtt:runtime:bindings')] === 8, 'Invalidate must increment the binding version');

echo 'MQTT binding index smoke tests passed: 15 checks; no broker, no real Redis.' . PHP_EOL;
