<?php

/**
 * Запись свойств на SQLite :memory: с подменой внешних операций, без загрузки приложения.
 * 2026-10-23 — проверка отсутствия повторов WebSocket при сохранении обработчиков.
 */
namespace MajorDoMo\Core {
    class Config { public static function bool($key) { return false; } }
    class Cache { public function get($key) { return false; } public function set($key, $value) {} public function delete($key) {} }
    class MetadataCache { public function get($key, $load) { return $load(); } }
    class PropertyLock {
        public bool $released = false;
        public function acquire($id, $property) { $this->released = false; return 1; }
        public function check($lock) {}
        public function release($lock) { $this->released = true; }
    }
    class WebSocketQueue {
        public array $posts = array();
        public function post($property, $value) { $this->posts[] = array($property, $value); }
    }
    class CodeExecutor {}
    class Core {
        public static function queue() { return new class {
            public function enqueueRuntime($operation, $params, $target) {
                $GLOBALS['queued'][] = array($operation, $target);
                if ($GLOBALS['throwQueue'] && $target === 'broken') throw new \RuntimeException('simulated queue failure');
                return !($GLOBALS['failQueue'] && $target === 'broken');
            }
        }; }
    }
}
namespace {
    putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('USE_REDIS=false');
    $pdo = new PDO('sqlite::memory:');
    if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Isolation failed');
    $pdo->exec('CREATE TABLE properties (ID INTEGER PRIMARY KEY,OBJECT_ID INTEGER,CLASS_ID INTEGER,TITLE TEXT,VALIDATION_TYPE INTEGER,DATA_TYPE INTEGER,KEEP_HISTORY INTEGER,ONCHANGE TEXT)');
    $pdo->exec('CREATE TABLE pvalues (ID INTEGER PRIMARY KEY,PROPERTY_ID INTEGER,OBJECT_ID INTEGER,VALUE TEXT,SOURCE TEXT,UPDATED TEXT,PROPERTY_NAME TEXT,LINKED_MODULES TEXT)');
    $pdo->exec("INSERT INTO properties VALUES (1,1,0,'motion',0,0,0,'onMotion')");
    $pdo->exec("INSERT INTO pvalues VALUES (1,1,1,'0','','','Sensor.motion','zigbee')");
    function startMeasure($name) {} function endMeasure($name, $flag = 0) {}
    function postToWebSocketQueue($property, $value) { $GLOBALS['websockets']->post($property, $value); }
    function registerError($code, $message) { $GLOBALS['reported'][] = array($code, $message); }
    $reported = array();
    $connection = new class($pdo) {
        public function __construct(private PDO $pdo) {}
        public function SelectOne($sql) { return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); }
        public function Update($table, $row, $key = 'ID') {
            $id = $row[$key]; unset($row[$key]);
            return $this->pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(fn($k) => $k . '=?', array_keys($row))) . ' WHERE ' . $key . '=?')->execute(array_merge(array_values($row), array($id)));
        }
        public function DbSafe1($value) { return str_replace("'", "''", $value); }
    };
    require dirname(__DIR__) . '/lib/core/Database.php'; require dirname(__DIR__) . '/lib/core/PropertyChain.php'; require dirname(__DIR__) . '/lib/core/PropertyAudit.php'; require dirname(__DIR__) . '/lib/core/ErrorReporter.php'; require dirname(__DIR__) . '/lib/core/Properties.php';
    $locks = new MajorDoMo\Core\PropertyLock();
    $websockets = new MajorDoMo\Core\WebSocketQueue();
    $properties = new MajorDoMo\Core\Properties(new MajorDoMo\Core\Database($connection), new MajorDoMo\Core\Cache(), new MajorDoMo\Core\MetadataCache(), $locks, $websockets, new MajorDoMo\Core\CodeExecutor());
    $object = new class {
        public $id = 1; public $class_id = 0; public $object_title = 'Sensor'; public array $calls = array();
        public function callMethod($name, $params) { $this->calls[] = array($name, $params, $GLOBALS['pdo']->query('SELECT VALUE FROM pvalues WHERE ID=1')->fetchColumn()); }
        public function raiseEvent($name, $params) { $this->calls[] = array('queued', $params, $GLOBALS['pdo']->query('SELECT VALUE FROM pvalues WHERE ID=1')->fetchColumn()); }
    };
    $checks = 0;
    function check($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
    $_SERVER['REQUEST_URI'] = '/mqtt/module/zigbee';
    $failQueue = false; $throwQueue = false; $queued = array();
    $properties->set($object, 'motion', 1, array('zigbee' => 0));
    check(count($object->calls) === 1 && $object->calls[0][2] === '1', 'New value saved before ONCHANGE');
    check($websockets->posts === array(array('Sensor.motion', 1)), 'Changed value is queued for WebSocket');
    check($queued === array(), 'Excluded source module is not echoed');
    $properties->set($object, 'motion', 1, array('zigbee' => 0));
    check(count($object->calls) === 2, 'Repeated value still triggers ONCHANGE');
    check(count($websockets->posts) === 1, 'Repeated integer does not queue the same stored string for WebSocket');
    $pdo->exec("UPDATE pvalues SET LINKED_MODULES='zigbee,broken,healthy'");
    foreach (array('false', 'exception') as $failure) {
        $failQueue = $failure === 'false'; $throwQueue = $failure === 'exception'; $queued = array();
        $before = count($object->calls); $caught = null;
        try { $properties->set($object, 'motion', 0, array('zigbee' => 0)); } catch (RuntimeException $e) { $caught = $e; }
        check($caught === null, 'Notification failure does not break the property writer');
        check(count($object->calls) === $before + 1 && end($object->calls)[2] === '0', 'Notification failure does not suppress ONCHANGE');
        check($locks->released, 'Property lock released after failure');
        check($queued === array(array('modulepropertyset', 'broken'), array('modulepropertyset', 'healthy')), 'Other subscribers still notified directly through the queue');
    }
    unset($_SERVER['REQUEST_URI']); $failQueue = false; $throwQueue = false;
    $properties->set($object, 'motion', 1, array('zigbee' => 0));
    check(end($object->calls)[0] === 'queued', 'CLI and web both dispatch ONCHANGE asynchronously');

    $pdo->exec("UPDATE pvalues SET LINKED_MODULES='mqtt'");
    $before = count($object->calls); $queued = array();
    MajorDoMo\Core\PropertyChain::enter(array('property:Sensor.motion'), 'mqtt');
    try { $properties->set($object, 'motion', 0); } finally { MajorDoMo\Core\PropertyChain::leave(); }
    check($queued === array() && count($object->calls) === $before
        && $pdo->query('SELECT VALUE FROM pvalues WHERE ID=1')->fetchColumn() === '0',
        'Repeated property in a delivery chain stops notifications but saves the value');

    // Вложенная привязка: модуль, который доставил предыдущее свойство, не
    // должен выпадать из уведомлений о другом своём свойстве. Иначе цепочка
    // «zigbee → кнопка → метод → свойство устройства» не доходит до устройства.
    $pdo->exec("INSERT INTO properties VALUES (2,1,0,'cmd',0,0,0,'cmdChanged')");
    $pdo->exec("INSERT INTO pvalues VALUES (2,2,1,'0','','','Sensor.cmd','zigbee,yandexhome')");
    MajorDoMo\Core\PropertyChain::enter(array('property:Sensor.motion'), 'zigbee', 'property:Sensor.motion');
    $queued = array(); $before = count($object->calls);
    try { $properties->set($object, 'cmd', 1, array('zigbee' => 0), 'zigbee'); } finally { MajorDoMo\Core\PropertyChain::leave(); }
    check($queued === array(array('modulepropertyset', 'zigbee'), array('modulepropertyset', 'yandexhome')),
        'Source module stays subscribed to another property in the same chain');
    check(count($object->calls) === $before + 1 && !isset(end($object->calls)[1]['NO_LINKED']['zigbee']),
        'Nested ONCHANGE does not inherit the source module exclusion');
    check((end($object->calls)[1]['_delivery'] ?? null) === array('module' => 'zigbee', 'point' => 'property:Sensor.motion'),
        'ONCHANGE receives the delivery point needed for cross-process echo scoping');

    // Собственное эхо по тому же свойству по-прежнему гасится.
    MajorDoMo\Core\PropertyChain::enter(array('property:Sensor.other'), 'zigbee', 'property:Sensor.cmd');
    $queued = array(); $before = count($object->calls);
    try { $properties->set($object, 'cmd', 0); } finally { MajorDoMo\Core\PropertyChain::leave(); }
    check($queued === array(array('modulepropertyset', 'yandexhome')),
        'Source module does not receive its own echo for the delivered property');

    // boolean — это состояние 0/1, а не «пусто»: иначе false сохранялся как '',
    // карточка свойства показывала «Значение не задано», а журнал — 0.
    $properties->set($object, 'cmd', false);
    check($pdo->query('SELECT VALUE FROM pvalues WHERE ID=2')->fetchColumn() === '0',
        'Boolean false is stored as 0, not as an empty string');
    check(end($object->calls)[1]['NEW_VALUE'] === '0', 'ONCHANGE receives 0 for boolean false');
    $properties->set($object, 'cmd', true);
    check($pdo->query('SELECT VALUE FROM pvalues WHERE ID=2')->fetchColumn() === '1',
        'Boolean true is stored as 1');

    // Тип входного значения не меняет состояние, но разные строки остаются разными:
    // '01' и '1' нельзя склеивать нестрогим числовым сравнением.
    foreach (array(
        array('1', false), array(1, false), array(1.0, false), array(true, false),
        array(0, true), array(false, false), array(0.0, false), array('0', false),
        array('', true), array(null, false), array('01', true), array(1, true),
        array('1.0', true), array(1.0, true), array(1.5, true), array(1.5, false),
    ) as [$input, $changed]) {
        $websockets->posts = array(); $queued = array(); $before = count($object->calls);
        $properties->set($object, 'cmd', $input);
        check(count($websockets->posts) === (int)$changed,
            'WebSocket follows the stored value for ' . var_export($input, true));
        check(count($object->calls) === $before + 1
            && $queued === array(array('modulepropertyset', 'zigbee'), array('modulepropertyset', 'yandexhome')),
            'ONCHANGE and linked modules still receive every assignment');
    }

    // Событие должно объяснять себя: причину, что сохранено и что делать.
    $propertiesWithErrors = new MajorDoMo\Core\Properties(
        new MajorDoMo\Core\Database($connection), new MajorDoMo\Core\Cache(),
        new MajorDoMo\Core\MetadataCache(), new MajorDoMo\Core\PropertyLock(),
        new MajorDoMo\Core\WebSocketQueue(), new MajorDoMo\Core\CodeExecutor(),
        new MajorDoMo\Core\ErrorReporter(new MajorDoMo\Core\Cache())
    );
    MajorDoMo\Core\PropertyChain::enter(array('property:Sensor.motion'), 'mqtt');
    try { $propertiesWithErrors->set($object, 'motion', 1); } finally { MajorDoMo\Core\PropertyChain::leave(); }
    check(count($reported) === 1 && $reported[0][0] === 'property_chain', 'Chain stop is registered under its own code');
    check(str_contains($reported[0][1], 'повторно изменилось') && str_contains($reported[0][1], 'Значение сохранено')
        && str_contains($reported[0][1], 'Цепочка') && str_contains($reported[0][1], 'Что делать:'),
        'Chain stop message explains what happened, what was saved and what to do');

    MajorDoMo\Core\ErrorReporter::reset();
    $longChain = array();
    for ($i = 1; $i <= MajorDoMo\Core\PropertyChain::MAX_LENGTH; $i++) $longChain[] = 'property:Other' . $i;
    MajorDoMo\Core\PropertyChain::enter($longChain, 'mqtt');
    try { $propertiesWithErrors->set($object, 'motion', 0); } finally { MajorDoMo\Core\PropertyChain::leave(); }
    check(count($reported) === 2 && str_contains($reported[1][1], 'превысила ' . MajorDoMo\Core\PropertyChain::MAX_LENGTH . ' шагов'),
        'Only a repeated property is reported as a loop; the depth limit is named separately');

    echo 'Property ONCHANGE smoke tests passed: ' . $checks . " checks; SQLite :memory:, no external connections.\n";
}
