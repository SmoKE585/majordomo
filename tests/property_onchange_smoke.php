<?php

/** Actual property writer, SQLite :memory:, mocked side effects; no app boot. */
namespace MajorDoMo\Core {
    class Config { public static function bool($key) { return false; } }
    class Cache { public function set($key, $value) {} public function delete($key) {} }
    class MetadataCache { public function get($key, $load) { return $load(); } }
    class PropertyLock {
        public bool $released = false;
        public function acquire($id, $property) { $this->released = false; return 1; }
        public function check($lock) {}
        public function release($lock) { $this->released = true; }
    }
    class WebSocketQueue { public function post($property, $value) {} }
    class CodeExecutor {}
    class Core {
        public static function api() { return new class {
            public function request($url, $method, $params) {
                $GLOBALS['queued'][] = $url;
                if ($GLOBALS['throwQueue'] && str_ends_with($url, '/broken')) throw new \RuntimeException('simulated queue failure');
                return !($GLOBALS['failQueue'] && str_ends_with($url, '/broken'));
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
    $connection = new class($pdo) {
        public function __construct(private PDO $pdo) {}
        public function SelectOne($sql) { return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); }
        public function Update($table, $row, $key = 'ID') {
            $id = $row[$key]; unset($row[$key]);
            return $this->pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(fn($k) => $k . '=?', array_keys($row))) . ' WHERE ' . $key . '=?')->execute(array_merge(array_values($row), array($id)));
        }
        public function DbSafe1($value) { return str_replace("'", "''", $value); }
    };
    require dirname(__DIR__) . '/lib/core/Database.php'; require dirname(__DIR__) . '/lib/core/PropertyChain.php'; require dirname(__DIR__) . '/lib/core/Properties.php';
    $locks = new MajorDoMo\Core\PropertyLock();
    $properties = new MajorDoMo\Core\Properties(new MajorDoMo\Core\Database($connection), new MajorDoMo\Core\Cache(), new MajorDoMo\Core\MetadataCache(), $locks, new MajorDoMo\Core\WebSocketQueue(), new MajorDoMo\Core\CodeExecutor());
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
    check($queued === array(), 'Excluded source module is not echoed');
    $properties->set($object, 'motion', 1, array('zigbee' => 0));
    check(count($object->calls) === 2, 'Repeated value still triggers ONCHANGE');
    $pdo->exec("UPDATE pvalues SET LINKED_MODULES='zigbee,broken,healthy'");
    foreach (array('false', 'exception') as $failure) {
        $failQueue = $failure === 'false'; $throwQueue = $failure === 'exception'; $queued = array();
        $before = count($object->calls); $caught = null;
        try { $properties->set($object, 'motion', 0, array('zigbee' => 0)); } catch (RuntimeException $e) { $caught = $e; }
        check($caught === null, 'Notification failure does not break the property writer');
        check(count($object->calls) === $before + 1 && end($object->calls)[2] === '0', 'Notification failure does not suppress ONCHANGE');
        check($locks->released, 'Property lock released after failure');
        check($queued === array('/api/modulePropertySet/broken', '/api/modulePropertySet/healthy'), 'Other subscribers still notified');
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

    echo 'Property ONCHANGE smoke tests passed: ' . $checks . " checks; SQLite :memory:, no external connections.\n";
}
