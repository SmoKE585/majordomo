<?php

/** 2026-10-23 — снимки кода и вложенные шаги аудита на SQLite :memory:, без внешних подключений. */
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('USE_REDIS=false');

require_once dirname(__DIR__) . '/lib/core/Database.php';
require_once dirname(__DIR__) . '/lib/core/PropertyAudit.php';

$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec("
CREATE TABLE property_audit_runs (
    ID INTEGER PRIMARY KEY AUTOINCREMENT, TRACE_ID TEXT UNIQUE NOT NULL, STARTED TEXT NOT NULL, LAST_SEEN TEXT NOT NULL,
    FINISHED TEXT, STATUS TEXT DEFAULT 'running', TRIGGER_TYPE TEXT DEFAULT '', TRIGGER_TITLE TEXT DEFAULT '',
    SOURCE TEXT DEFAULT '', ACTOR TEXT DEFAULT '', IP TEXT DEFAULT '', REQUEST_URI TEXT DEFAULT '',
    PARAMS TEXT, RESULT TEXT, ERROR TEXT, CHAIN TEXT, EVENTS_COUNT INTEGER DEFAULT 0
);
CREATE TABLE property_audit_events (
    ID INTEGER PRIMARY KEY AUTOINCREMENT, RUN_ID INTEGER DEFAULT 0, TRACE_ID TEXT NOT NULL, ADDED TEXT NOT NULL,
    FINISHED TEXT, DURATION_MS INTEGER DEFAULT 0, TYPE TEXT DEFAULT '', STATUS TEXT DEFAULT 'running',
    TITLE TEXT DEFAULT '', SUBTITLE TEXT DEFAULT '', OBJECT_ID INTEGER DEFAULT 0, PROPERTY_ID INTEGER DEFAULT 0,
    PROPERTY_NAME TEXT DEFAULT '', METHOD_ID INTEGER DEFAULT 0, CLASS_ID INTEGER DEFAULT 0, SCRIPT_ID INTEGER DEFAULT 0,
    MODULE TEXT DEFAULT '', OLD_VALUE TEXT, NEW_VALUE TEXT, SOURCE TEXT DEFAULT '', PARAMS TEXT,
    OUT_PARAMS TEXT, RESULT TEXT, ERROR TEXT, CHAIN TEXT
);
");

$connection = new class($pdo) {
    private int $affected = 0;
    public function __construct(private PDO $pdo) {}
    public function DbSafe1($value) { return str_replace("'", "''", (string)$value); }
    public function DbSafe($value) { return $this->DbSafe1($value); }
    public function Select($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function SelectOne($sql)
    {
        if (stripos($sql, 'information_schema.TABLES') !== false) {
            preg_match("/TABLE_NAME='([^']+)'/", $sql, $match);
            $name = $match[1] ?? '';
            return $this->pdo->query("SELECT name AS TABLE_NAME FROM sqlite_master WHERE type='table' AND name='" . $this->DbSafe1($name) . "'")->fetch(PDO::FETCH_ASSOC) ?: array();
        }
        return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: array();
    }
    public function Exec($sql, $ignoreErrors = false) { $this->affected = $this->pdo->exec($sql); return true; }
    public function AffectedRows() { return $this->affected; }
    public function Insert($table, &$row)
    {
        $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        return (int)$this->pdo->lastInsertId();
    }
    public function Update($table, $row, $key = 'ID')
    {
        $id = $row[$key];
        unset($row[$key]);
        $this->pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(fn($field) => $field . '=?', array_keys($row))) . ' WHERE ' . $key . '=?')->execute(array_merge(array_values($row), array($id)));
        return true;
    }
};

function check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

$audit = new MajorDoMo\Core\PropertyAudit(new MajorDoMo\Core\Database($connection));
$trace = $audit->begin('method', 'Lamp.onTimer', array('brightness' => 80), array('source' => 'Job: Morning'));
check(strlen($trace) === 32, 'Trace id is a 32-char hexadecimal token');
$step = $audit->stepStart('method', 'Lamp.onTimer', array(
    'object_id' => 1, 'method_id' => 7, 'params' => array('brightness' => 80),
));
check(is_array($step) && $step['id'] > 0, 'Method step is created immediately');
$audit->propertyChanged(array(
    'title' => 'Lamp.status', 'object_id' => 1, 'property_id' => 11, 'property_name' => 'Lamp.status',
    'old_value' => '0', 'new_value' => '1', 'source' => 'method:Lamp.onTimer',
    'chain' => array('property:Lamp.status'),
));
$audit->stepFinish($step, true, null, array('brightness' => 80));
$audit->finishRun('done', array('ok' => true));
$audit->leave();

$page = $audit->tracesForProperty(1, 11, time() - 3600, time() + 60, 1, 10);
check($page['pagination']['total'] === 1 && count($page['runs']) === 1, 'Run is found by property and object');
$run = $page['runs'][0];
check($run['trace_id'] === $trace && $run['status'] === 'done', 'Run header is exposed');
check($run['params'] === array('brightness' => 80), 'Run input params are preserved');
check(count($run['events']) === 3, 'Trigger, method and property events are in the chain');
$types = array_column($run['events'], 'type');
check(in_array('trigger', $types, true) && in_array('method', $types, true) && in_array('property', $types, true), 'Chain contains trigger, method and property');
$propertyEvent = null;
foreach ($run['events'] as $event) {
    if ($event['type'] === 'property') $propertyEvent = $event;
}
check($propertyEvent !== null, 'Property event is present');
check($propertyEvent['old_value'] === '0' && $propertyEvent['new_value'] === '1', 'Old and new values are stored');
check($propertyEvent['is_target'] === true, 'The selected property is marked as target');
check($propertyEvent['chain'] === array('property:Lamp.status'), 'Property chain is stored');
check($run['events'][1]['result'] === true && $run['events'][1]['out_params'] === array('brightness' => 80), 'Method output is stored');

// A resumed trace appends to the same run instead of creating a second one.
$audit->enter($trace, 'resume', 'Продолжение');
$audit->propertyChanged(array(
    'title' => 'Lamp.status', 'object_id' => 1, 'property_id' => 11, 'property_name' => 'Lamp.status',
    'old_value' => '1', 'new_value' => '0', 'source' => 'module:scripts',
));
$audit->finishRun('done');
$audit->leave();
$page = $audit->tracesForProperty(1, 11, time() - 3600, time() + 60, 1, 10);
check($page['pagination']['total'] === 1 && count($page['runs'][0]['events']) === 4, 'Resumed trace appends to the same run');

// Снимок кода должен пережить завершение шага и последующее редактирование метода.
$audit->begin('method', 'Sensor.data');
$methodCode = '$params["NEW_VALUE"] = 0;';
$outer = $audit->stepStart('method', 'Sensor.data', array('params' => array('NEW_VALUE' => 1),
    'execution' => array('code' => 'sg("Lamp.status", 1);', 'parent_order' => 1)));
$inner = $audit->stepStart('method', 'Sensor.data class', array('params' => array('NEW_VALUE' => 1),
    'execution' => array('code' => $methodCode)));
$audit->propertyChanged(array('title' => 'Lamp.status', 'object_id' => 1, 'property_id' => 11, 'old_value' => 0, 'new_value' => 1));
$audit->stepFinish($inner, true, null, array('NEW_VALUE' => 0));
$audit->stepFinish($outer, true, null, array('NEW_VALUE' => 0));
$audit->finishRun(); $audit->leave();
$page = $audit->tracesForProperty(1, 11, time() - 3600, time() + 60, 1, 10);
$events = $page['runs'][0]['events'];
check($events[1]['parent_event_id'] === 0 && $events[2]['parent_event_id'] === $outer['id'], 'Nested method links to its caller');
check($events[3]['parent_event_id'] === $inner['id'], 'Property write links to the method that performed it');
check($events[2]['execution']['code'] === $methodCode && $events[2]['execution']['code_hash'] === hash('sha256', $methodCode), 'Executed code snapshot and hash are retained');
check($events[2]['params'] === array('NEW_VALUE' => 1) && $events[2]['out_params'] === array('NEW_VALUE' => 0), 'Metadata does not leak into input/output parameters');

// Завершённые шаги не должны становиться родителями следующего запуска.
$audit->begin('method', 'Clean');
$empty = $audit->stepStart('method', 'Clean', array('params' => array('remove' => 1), 'execution' => array('code' => str_repeat('я', 20000))));
$audit->stepFinish($empty, true, null, array());
$audit->propertyChanged(array('title' => 'Lamp.status', 'object_id' => 1, 'property_id' => 11, 'old_value' => 1, 'new_value' => 0));
$audit->finishRun(); $audit->leave();
$page = $audit->tracesForProperty(1, 11, time() - 3600, time() + 60, 1, 10);
$events = $page['runs'][0]['events'];
check($events[1]['parent_event_id'] === 0 && $events[2]['parent_event_id'] === 0, 'Completed steps and old runs are not reused as parents');
check($events[1]['out_params'] === array(), 'Removal of all parameters is preserved as an empty array');
check($events[1]['execution']['code_truncated'] === true && mb_check_encoding($events[1]['execution']['code'], 'UTF-8'), 'Large code is explicitly truncated at a valid UTF-8 boundary');

$audit->begin('method', 'Large', array('large' => str_repeat('"', 80000)));
$audit->propertyChanged(array('title' => 'Lamp.status', 'object_id' => 1, 'property_id' => 11, 'old_value' => 0, 'new_value' => 1));
$audit->finishRun(); $audit->leave();
$page = $audit->tracesForProperty(1, 11, time() - 3600, time() + 60, 1, 10);
check($page['runs'][0]['params']['_truncated'] === true, 'Oversized JSON remains decodable and explicitly marked');
$audit->begin('method', 'Failure');
$failed = $audit->stepStart('method', 'Failure');
$audit->propertyChanged(array('title' => 'Lamp.status', 'object_id' => 1, 'property_id' => 11, 'old_value' => 1, 'new_value' => 0));
$audit->stepFinish($failed, false);
$audit->finishRun('done', array('success' => true));
$audit->leave();
$page = $audit->tracesForProperty(1, 11, time() - 3600, time() + 60, 1, 10);
check($page['runs'][0]['status'] === 'error' && $page['runs'][0]['events'][1]['status'] === 'error', 'Outer success cannot hide an explicit method failure');
check($page['runs'][0]['error'] !== '', 'A failed result has an explanation');
$audit->begin('method', 'Types');
foreach (array(false, true, 0, '0', 'false', '', null) as $value) {
    $step = $audit->stepStart('method', 'Types');
    $audit->stepFinish($step, $value);
    $stored = $pdo->query('SELECT RESULT FROM property_audit_events WHERE ID=' . $step['id'])->fetchColumn();
    check(($stored === null ? null : json_decode($stored, true, 512, JSON_THROW_ON_ERROR)) === $value, 'Result type is preserved');
}
$step = $audit->stepStart('method', 'Large metadata', array('params' => array('large' => str_repeat('x', 80000)), 'execution' => array('code' => 'return 1;')));
$audit->stepFinish($step, true);
$stored = json_decode($pdo->query('SELECT PARAMS FROM property_audit_events WHERE ID=' . $step['id'])->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
check($stored['__mdj_audit_step_v1']['execution']['code'] === 'return 1;' && $stored['__mdj_audit_step_v1']['params']['_truncated'] === true, 'Large input cannot erase the code snapshot or step metadata');
$audit->finishRun(); $audit->leave();
echo 'Property audit smoke tests passed: 32 checks; SQLite :memory:, no external connections.' . "\n";
