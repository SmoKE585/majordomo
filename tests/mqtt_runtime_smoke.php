<?php

/**
 * MQTT runtime projection on a real isolated Redis + SQLite.
 * Requires MJD_TEST_REDIS_PORT; without it the check is skipped.
 */
putenv('APP_ENV=testing');
putenv('USE_REDIS=false');
require_once __DIR__ . '/RedisTestClient.php';
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\RedisConnection;

$port = (int)getenv('MJD_TEST_REDIS_PORT');
if ($port < 1) {
    echo 'SKIP: MJD_TEST_REDIS_PORT is not set.' . PHP_EOL;
    exit(0);
}

$fixture = sys_get_temp_dir() . '/mjd-runtime-' . bin2hex(random_bytes(6));
Config::boot($fixture);
register_shutdown_function(static function () use ($fixture) {
    @unlink($fixture . '/cms/cached/config.php');
    @rmdir($fixture . '/cms/cached');
    @rmdir($fixture . '/cms');
    @rmdir($fixture);
});

$values = (new ReflectionProperty(Config::class, 'values'))->getValue();
$values['USE_REDIS'] = '127.0.0.1';
$values['REDIS_PORT'] = $port;
$values['REDIS_DB'] = 15;
$values['REDIS_PREFIX'] = 'mjdt:' . bin2hex(random_bytes(4)) . ':';
$values['MQTT_ASYNC_ENABLED'] = true;
(new ReflectionProperty(Config::class, 'values'))->setValue(null, $values);
$present = (new ReflectionProperty(Config::class, 'present'))->getValue();
foreach (array('USE_REDIS', 'REDIS_PORT', 'REDIS_DB', 'REDIS_PREFIX', 'MQTT_ASYNC_ENABLED') as $key) $present[$key] = true;
(new ReflectionProperty(Config::class, 'present'))->setValue(null, $present);

$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec('CREATE TABLE mqtt (ID INTEGER PRIMARY KEY AUTOINCREMENT, PATH TEXT, TITLE TEXT DEFAULT "", VALUE TEXT DEFAULT "", UPDATED TEXT)');
$pdo->exec('CREATE TABLE mqtt_history (ID INTEGER PRIMARY KEY AUTOINCREMENT, MQTT_ID INTEGER, DESTINATION INTEGER DEFAULT 0, TOPIC TEXT, VALUE TEXT, DATA_PAYLOAD TEXT, LINKED_DATA TEXT, UPDATED TEXT)');

function SQLSelect($query)
{
    global $pdo;
    return $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
}
function SQLSelectOne($query)
{
    global $pdo;
    $row = $pdo->query($query)->fetch(PDO::FETCH_ASSOC);
    return $row === false ? array() : $row;
}
function SQLInsert($table, &$row)
{
    global $pdo;
    $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    return (int)$pdo->lastInsertId();
}
function SQLUpdate($table, $row)
{
    global $pdo;
    $id = $row['ID'];
    unset($row['ID']);
    $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(static fn($key) => $key . '=?', array_keys($row))) . ' WHERE ID=?')->execute(array_merge(array_values($row), array($id)));
    return 1;
}
function SQLExec($query) { global $pdo; return $pdo->exec($query) !== false; }
function DBSafe($value) { return str_replace("'", "''", (string)$value); }
function DebMes($message, $source = '', $severity = '') {}
function check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

require_once dirname(__DIR__) . '/modules/mqtt/MqttRuntime.php';

$now = date('Y-m-d H:i:s');
$older = date('Y-m-d H:i:s', time() - 10);

// Coalescing: several values of one topic become one row with the latest value.
\MqttRuntime::stage(array('ID' => 0, 'PATH' => 'home/lamp/state', 'VALUE' => '0', 'UPDATED' => $older));
\MqttRuntime::stage(array('ID' => 0, 'PATH' => 'home/lamp/state', 'VALUE' => '1', 'UPDATED' => $now));
check(\MqttRuntime::flushState(50) >= 1, 'State must be projected');
$row = $pdo->query("SELECT * FROM mqtt WHERE PATH='home/lamp/state'")->fetch(PDO::FETCH_ASSOC);
check(($row['VALUE'] ?? '') === '1' && ($row['UPDATED'] ?? '') === $now, 'Projection must keep the latest value');
check((int)$pdo->query("SELECT COUNT(*) FROM mqtt WHERE PATH='home/lamp/state'")->fetchColumn() === 1, 'Coalescing must not duplicate a topic');

// New topic discovery is deferred too.
\MqttRuntime::stage(array('ID' => 0, 'PATH' => 'home/new/topic', 'VALUE' => '42', 'UPDATED' => $now));
\MqttRuntime::flushState(50);
check((string)$pdo->query("SELECT VALUE FROM mqtt WHERE PATH='home/new/topic'")->fetchColumn() === '42', 'New topic must be discovered by the projection');

// A newer manual edit in the card must not be overwritten by a delayed projection.
// (See below, after the overlay check, so the state still holds the fresh value here.)

// The admin reads the Redis value, not the stale SQL projection.
$stale = array(array('PATH' => 'home/lamp/state', 'VALUE' => '0', 'UPDATED' => $older));
$overlay = \MqttRuntime::overlay($stale);
check($overlay[0]['VALUE'] === '1' && $overlay[0]['UPDATED'] === $now, 'Overlay must hide the projection lag');

// A newer manual edit in the card must not be overwritten by a delayed projection.
$manualUpdated = date('Y-m-d H:i:s', time() + 5);
SQLExec("UPDATE mqtt SET VALUE='manual',UPDATED='" . $manualUpdated . "' WHERE PATH='home/lamp/state'");
\MqttRuntime::stage(array('ID' => 0, 'PATH' => 'home/lamp/state', 'VALUE' => 'stale', 'UPDATED' => $now));
\MqttRuntime::flushState(50);
check((string)$pdo->query("SELECT VALUE FROM mqtt WHERE PATH='home/lamp/state'")->fetchColumn() === 'manual', 'Projection must not overwrite a newer manual edit');

// Linked history stays a separate event.
\MqttRuntime::stageHistory(array('MQTT_ID' => (int)$row['ID'], 'DESTINATION' => 0, 'TOPIC' => 'home/lamp/state',
    'VALUE' => '1', 'DATA_PAYLOAD' => '1', 'LINKED_DATA' => 'Lamp.status', 'UPDATED' => $now));
check(\MqttRuntime::flushHistory(50) === 1, 'History event must be written');
check((int)$pdo->query('SELECT COUNT(*) FROM mqtt_history')->fetchColumn() === 1, 'History must have exactly one row');
check((string)$pdo->query('SELECT LINKED_DATA FROM mqtt_history')->fetchColumn() === 'Lamp.status', 'History must keep the linked target');

// A new topic with LOGGING/debug history is inserted before its history row.
\MqttRuntime::stageHistory(array('MQTT_ID' => 0, 'DESTINATION' => 0, 'TOPIC' => 'home/new/topic',
    'VALUE' => '42', 'DATA_PAYLOAD' => '42', 'UPDATED' => $now));
\MqttRuntime::flushHistory(50);
check((int)$pdo->query("SELECT COUNT(*) FROM mqtt WHERE PATH='home/new/topic'")->fetchColumn() === 1, 'History must not duplicate a discovered topic');
check((int)$pdo->query('SELECT COUNT(*) FROM mqtt_history')->fetchColumn() === 2, 'Second history event must be written');

// Forget removes pending state and does not resurrect a deleted topic.
\MqttRuntime::forget('home/lamp/state');
\MqttRuntime::stage(array('ID' => 0, 'PATH' => 'home/lamp/state', 'VALUE' => '9', 'UPDATED' => $now));
\MqttRuntime::forget('home/lamp/state');
check(\MqttRuntime::flushState(50) === 0, 'Forgotten dirty state must not be projected');

\MqttRuntime::invalidate();
check(\MqttRuntime::version() !== '0', 'Invalidate must advance the binding version');
\MqttRuntime::heartbeat('publisher');
check(\MqttRuntime::isAlive('publisher'), 'Publisher heartbeat must be visible');
$status = \MqttRuntime::status();
check($status['enabled'] === true && isset($status['incoming']), 'Status must report the runtime queues');

echo 'MQTT runtime projection smoke tests passed: 16 checks on real Redis + SQLite.' . PHP_EOL;
