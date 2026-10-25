<?php

/**
 * Async MQTT message processing on real isolated Redis + SQLite:
 * JSON leaves, bindings, method actions, write paths, strict mode, throttling,
 * state projection and linked-only history.
 * Requires MJD_TEST_REDIS_PORT.
 */
putenv('APP_ENV=testing');
putenv('USE_REDIS=false');
require_once __DIR__ . '/RedisTestClient.php';
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Config;

$port = (int)getenv('MJD_TEST_REDIS_PORT');
if ($port < 1) {
    echo 'SKIP: MJD_TEST_REDIS_PORT is not set.' . PHP_EOL;
    exit(0);
}

$fixture = sys_get_temp_dir() . '/mjd-mqtt-process-' . bin2hex(random_bytes(6));
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
$pdo->exec('CREATE TABLE mqtt (ID INTEGER PRIMARY KEY AUTOINCREMENT, PATH TEXT, TITLE TEXT DEFAULT "", VALUE TEXT DEFAULT "", UPDATED TEXT,
    PATH_WRITE TEXT DEFAULT "", LINKED_OBJECT TEXT DEFAULT "", LINKED_PROPERTY TEXT DEFAULT "", LINKED_METHOD TEXT DEFAULT "",
    LOGGING INTEGER DEFAULT 0, ONLY_NEW_VALUE INTEGER DEFAULT 0, REPLACE_LIST TEXT DEFAULT "", READONLY INTEGER DEFAULT 0,
    QOS INTEGER DEFAULT 0, RETAIN INTEGER DEFAULT 0, WRITE_TYPE INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE mqtt_history (ID INTEGER PRIMARY KEY AUTOINCREMENT, MQTT_ID INTEGER, DESTINATION INTEGER DEFAULT 0,
    TOPIC TEXT, VALUE TEXT, DATA_PAYLOAD TEXT, LINKED_DATA TEXT, UPDATED TEXT)');
$insert = $pdo->prepare('INSERT INTO mqtt(PATH,TITLE,LINKED_OBJECT,LINKED_PROPERTY,LINKED_METHOD,PATH_WRITE) VALUES (?,?,?,?,?,?)');
$insert->execute(array('home/lamp/state', 'state', 'Lamp', 'status', '', ''));
$insert->execute(array('home/lamp/brightness', 'brightness', 'Lamp', 'brightness', '', ''));
$insert->execute(array('home/button/action', 'action', 'Button', '', 'press', ''));
$insert->execute(array('home/lamp/set', 'set', '', '', '', 'home/lamp/set'));
$insert->execute(array('home/unlinked', 'unlinked', '', '', '', ''));

$actions = array();
$logs = array();
function SQLSelect($query) { global $pdo; return $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC); }
function SQLSelectOne($query) { global $pdo; $row = $pdo->query($query)->fetch(PDO::FETCH_ASSOC); return $row === false ? array() : $row; }
function SQLInsert($table, &$row)
{
    global $pdo;
    $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    return (int)$pdo->lastInsertId();
}
function SQLUpdate($table, $row)
{
    global $pdo;
    $id = $row['ID']; unset($row['ID']);
    $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(static fn($key) => $key . '=?', array_keys($row))) . ' WHERE ID=?')->execute(array_merge(array_values($row), array($id)));
    return 1;
}
function SQLExec($query) { global $pdo; return $pdo->exec($query) !== false; }
function DBSafe($value) { return str_replace("'", "''", (string)$value); }
function DebMes($message, $source = '', $severity = '') { $GLOBALS['logs'][] = array($message, $source, $severity); }
function callMethod($name, $parameters) { $GLOBALS['actions'][] = array($name, $parameters['NEW_VALUE']); return $GLOBALS['methodOutcome'] ?? null; }
function setGlobal($name, $value, $noLinked = 0, $source = '') { $GLOBALS['writeAttempts']++; return $GLOBALS['writeOutcome']; }
function startMeasure($name) {}
function endMeasure($name) {}
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }

class module
{
    public array $config = array();
}
require_once dirname(__DIR__) . '/modules/mqtt/mqtt.class.php';

class RuntimeTestMqtt extends mqtt
{
    public ?MqttBindingIndex $index = null;
    public function __construct()
    {
        $this->config = array('MQTT_STRIPMODE' => 0, 'MQTT_UNLINKED_UPDATE_INTERVAL' => 3600, 'DEBUG_MODE' => 0);
    }
    public function getConfig() { return $this->config; }
    public function getRuntimeIndex(): MqttBindingIndex
    {
        if ($this->index === null) {
            $this->index = new MqttBindingIndex(\MajorDoMo\Core\Core::redis());
            $this->index->load();
        }
        return $this->index;
    }
    public function setLinkedPropertyFromMqtt($object, $property, $value, $source_url)
    {
        $GLOBALS['actions'][] = array($object . '.' . $property, $value);
    }
}

$mqtt = new RuntimeTestMqtt();
$mqtt->processRuntimeMessage('home/lamp', '{"state":1,"brightness":50}', false, $mqtt->getRuntimeIndex());
$actionsByName = array();
foreach ($actions as $action) $actionsByName[$action[0]] = $action[1];
check(($actionsByName['Lamp.status'] ?? null) === 1, 'Linked JSON property must be written');
check(($actionsByName['Lamp.brightness'] ?? null) === 50, 'Nested linked JSON property must be written');
check(count($actions) === 2, 'Only linked leaves trigger property actions');

\MqttRuntime::flushState(100);
check((string)$pdo->query("SELECT VALUE FROM mqtt WHERE PATH='home/lamp/state'")->fetchColumn() === '1', 'Linked state must be projected');
check((string)$pdo->query("SELECT VALUE FROM mqtt WHERE PATH='home/lamp/brightness'")->fetchColumn() === '50', 'Nested state must be projected');

$actions = array();
$mqtt->processRuntimeMessage('home/button/action', 'press', false, $mqtt->getRuntimeIndex());
check($actions === array(array('Button.press', 'press')), 'Linked method must be called exactly once');

$actions = array();
$mqtt->processRuntimeMessage('home/lamp/set', 'off', false, $mqtt->getRuntimeIndex());
check($actions === array(), 'Write-only topic must not be processed as input');

// Strict mode drops unknown topics before any state/history write.
$mqtt->config['MQTT_STRIPMODE'] = 1;
$mqtt->processRuntimeMessage('home/unknown', '1', false, $mqtt->getRuntimeIndex());
check($pdo->query("SELECT ID FROM mqtt WHERE PATH='home/unknown'")->fetchColumn() === false, 'Strict mode must not discover unknown topics');
$mqtt->config['MQTT_STRIPMODE'] = 0;

// Unlinked telemetry is throttled by the existing admission window.
$mqtt->processRuntimeMessage('home/unlinked', 'first', false, $mqtt->getRuntimeIndex());
$mqtt->processRuntimeMessage('home/unlinked', 'second', false, $mqtt->getRuntimeIndex());
\MqttRuntime::flushState(100);
check((string)$pdo->query("SELECT VALUE FROM mqtt WHERE PATH='home/unlinked'")->fetchColumn() === 'first', 'Unlinked topic must honor its update interval');

// Linked events and the method call remain separate history rows.
check(\MqttRuntime::flushHistory(100) >= 3, 'Linked messages must produce history events');
check((int)$pdo->query('SELECT COUNT(*) FROM mqtt_history')->fetchColumn() >= 3, 'History rows must be stored');

$writeAttempts = 0;
foreach (array(false, 0) as $rejected) {
    $path = 'audit/reject/' . ($rejected === false ? 'validation' : 'object');
    $pdo->prepare('INSERT INTO mqtt(PATH,LINKED_OBJECT,LINKED_PROPERTY,ONLY_NEW_VALUE,VALUE) VALUES (?,?,?,?,?)')
        ->execute(array($path, 'Lamp', 'status', 1, '0'));
    MqttRuntime::invalidate();
    $native = new class extends mqtt {
        public function __construct() { $this->config = array('MQTT_STRIPMODE' => 0, 'DEBUG_MODE' => 0); }
        public function getConfig() { return $this->config; }
    };
    $writeOutcome = $rejected;
    $before = $writeAttempts;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $caught = false;
        try { $native->processRuntimeMessage($path, '1'); } catch (RuntimeException $error) { $caught = true; }
        check($caught && MqttRuntime::state($path) === null, 'Отказ записи не подтверждает входящее сообщение');
    }
    check($writeAttempts === $before + 2, 'Повтор после отказа снова вызывает запись');
    $writeOutcome = null;
    $native->processRuntimeMessage($path, '1');
    $native->processRuntimeMessage($path, '1');
    check($writeAttempts === $before + 3 && MqttRuntime::value($path) === '1', 'Дедупликация включается после успешной записи');
}
$pdo->exec("INSERT INTO mqtt(PATH,LINKED_OBJECT,LINKED_METHOD,ONLY_NEW_VALUE) VALUES ('audit/method','Button','press',1)");
MqttRuntime::invalidate();
$methodOutcome = false;
$caught = false;
try { $native->processRuntimeMessage('audit/method', 'press'); } catch (RuntimeException $error) { $caught = true; }
check($caught && MqttRuntime::state('audit/method') === null, 'Отказ метода не подтверждает входящий пакет');
$methodOutcome = 0;
$native->processRuntimeMessage('audit/method', 'press');
check(MqttRuntime::value('audit/method') === 'press', 'Явный результат метода 0 остаётся допустимым');
echo 'MQTT runtime processing smoke tests passed: 22 checks on real Redis + SQLite.' . PHP_EOL;
