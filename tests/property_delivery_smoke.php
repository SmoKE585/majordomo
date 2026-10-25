<?php

/**
 * События, методы, сценарии и защита от циклов на SQLite :memory:, без рабочих сервисов.
 * 2026-10-25 — ONCHANGE остаётся в очереди при флагах воркера и LOWER_BACKGROUND_PROCESSES.
 */
namespace MajorDoMo\Core {
    final class Config {
        public static bool $lowerBackground = false;
        public static function get($key, $default = null) { return $key === 'USE_REDIS' ? false : $default; }
        public static function bool($key) { return $key === 'LOWER_BACKGROUND_PROCESSES' && self::$lowerBackground; }
        public static function has($key) { return false; }
    }
    final class Cache {
        public function get($key) { return false; }
        public function set(...$args) {}
        public function delete($key) {}
    }
    final class MetadataCache { public function get($key, $load) { return $load(); } }
    final class PropertyLock {
        public function acquire(...$args) { return 1; }
        public function check($lock) {}
        public function release($lock) {}
    }
    final class WebSocketQueue { public function post(...$args) {} }
    final class RedisConnection {}
    final class ProcessRunner {}
    final class Core {
        public static OperationsQueue $queue;
        public static Properties $properties;
        public static Methods $methods;
        public static array $objects = array();
        public static function queue() { return self::$queue; }
        public static function properties() { return self::$properties; }
        public static function audit() { return null; }
        public static function objects() {
            return new class {
                public function call($target, $params) {
                    [$object, $method] = explode('.', $target, 2);
                    return Core::$methods->call(Core::$objects[$object], $method, $params);
                }
            };
        }
    }
}

namespace {
    use MajorDoMo\Core\Core;
    use MajorDoMo\Core\PropertyChain;

    putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('USE_REDIS=false');
    error_reporting(E_ALL);
    set_error_handler(static function ($severity, $message, $file, $line) { throw new \ErrorException($message, 0, $severity, $file, $line); });
    foreach (array('Database', 'CodeExecutor', 'PropertyChain', 'PropertyAudit', 'Properties', 'Methods', 'RuntimeQueue', 'OperationsQueue', 'OperationWorker', 'ApiClient') as $class) {
        require dirname(__DIR__) . '/lib/core/' . $class . '.php';
    }
    require dirname(__DIR__) . '/lib/core/Helpers/Scripts.php';
    function verbose_log(...$args) {}
    function startMeasure(...$args) {}
    function endMeasure(...$args) {}
    function setEvalCode(...$args) {}
    function registerError(...$args) { throw new \RuntimeException('Неожиданный отказ метода'); }
    function DebMes($message, $channel = '', $level = '') { $GLOBALS['warnings'][] = $message; }
    function postToWebSocketQueue(...$args) {}
    function check($condition, $message) {
        if (!$condition) throw new \RuntimeException($message);
        $GLOBALS['checks']++;
    }

    $checks = 0;
    $pdo = new \PDO('sqlite::memory:', null, null, array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION));
    check(getenv('APP_ENV') === 'testing' && $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Изоляция подтверждена');
    require __DIR__ . '/fixtures/runtime_queue.php';
    createRuntimeQueueFixture($pdo);
    $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('GET_LOCK', fn(...$args) => 1);
    $pdo->sqliteCreateFunction('RELEASE_LOCK', fn(...$args) => 1);
    $pdo->exec("CREATE TABLE properties (ID INTEGER PRIMARY KEY, OBJECT_ID INTEGER, CLASS_ID INTEGER DEFAULT 0,
        TITLE TEXT, VALIDATION_TYPE INTEGER DEFAULT 0, DATA_TYPE INTEGER DEFAULT 0, KEEP_HISTORY INTEGER DEFAULT 0, ONCHANGE TEXT DEFAULT '');
        CREATE TABLE pvalues (ID INTEGER PRIMARY KEY, PROPERTY_ID INTEGER, OBJECT_ID INTEGER, PROPERTY_NAME TEXT,
            VALUE TEXT, SOURCE TEXT DEFAULT '', UPDATED TEXT, LINKED_MODULES TEXT);
        CREATE TABLE methods (ID INTEGER PRIMARY KEY, OBJECT_ID INTEGER, CLASS_ID INTEGER DEFAULT 0, TITLE TEXT,
            CODE TEXT, CALL_PARENT INTEGER DEFAULT 0, SCRIPT_ID INTEGER DEFAULT 0, EXECUTED TEXT, EXECUTED_SRC TEXT, EXECUTED_PARAMS TEXT);
        CREATE TABLE scripts (ID INTEGER PRIMARY KEY, TITLE TEXT, CODE TEXT);
        CREATE TABLE operations_queue (ID INTEGER PRIMARY KEY AUTOINCREMENT, TOPIC TEXT, DATANAME TEXT, DATAVALUE TEXT,
            EXPIRE TEXT, CLAIM_TOKEN TEXT DEFAULT '', CLAIM_UNTIL TEXT, ATTEMPTS INTEGER DEFAULT 1);
        INSERT INTO properties(ID,OBJECT_ID,TITLE,ONCHANGE) VALUES (1,1,'status','changed'),(2,2,'status','');
        INSERT INTO pvalues(ID,PROPERTY_ID,OBJECT_ID,PROPERTY_NAME,VALUE) VALUES (1,1,1,'Sensor.status','1'),(2,2,2,'Lamp.status','0')");
    $connection = new class($pdo) {
        public function __construct(private \PDO $pdo) {}
        public function DbSafe1($value) { return str_replace("'", "''", (string)$value); }
        public function SelectOne($sql) { return $this->pdo->query($sql)->fetch(\PDO::FETCH_ASSOC) ?: array(); }
        public function Exec($sql, $ignoreErrors = false) { $this->pdo->exec($sql === 'START TRANSACTION' ? 'BEGIN' : $sql); return true; }
        public function inTransaction() { return $this->pdo->inTransaction(); }
        public function Insert($table, &$row) {
            $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            return (int)$this->pdo->lastInsertId();
        }
        public function Update($table, $row, $key = 'ID') {
            $id = $row[$key]; unset($row[$key]);
            return $this->pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(fn($name) => $name . '=?', array_keys($row))) . ' WHERE ' . $key . '=?')->execute(array_merge(array_values($row), array($id)));
        }
    };
    $db = new MajorDoMo\Core\Database($connection);
    $executor = new MajorDoMo\Core\CodeExecutor();
    Core::$queue = new MajorDoMo\Core\OperationsQueue($db, new MajorDoMo\Core\RedisConnection());
    Core::$properties = new MajorDoMo\Core\Properties($db, new MajorDoMo\Core\Cache(), new MajorDoMo\Core\MetadataCache(),
        new MajorDoMo\Core\PropertyLock(), new MajorDoMo\Core\WebSocketQueue(), $executor);
    Core::$methods = new MajorDoMo\Core\Methods($db, new MajorDoMo\Core\MetadataCache(), $executor);
    $worker = new MajorDoMo\Core\OperationWorker($db, new MajorDoMo\Core\RedisConnection(), Core::$queue, new MajorDoMo\Core\ProcessRunner());
    foreach (array('Sensor', 'Lamp', 'Relay') as $index => $title) {
        Core::$objects[$title] = new class($index + 1, $title) {
            public int $class_id = 0;
            public function __construct(public int $id, public string $object_title) {}
            public function raiseEvent($name, $params) {
                $params['raiseEvent'] = '1';
                return Core::$methods->callSafe($this, $name, $params);
            }
        };
    }
    $changedCode = <<<'PHP'
\MajorDoMo\Core\Core::queue()->enqueueRuntime('method', array('NEW_VALUE' => $params['NEW_VALUE'], 'NO_LINKED' => $params['NO_LINKED']), 'Relay.forward');
PHP;
    $forwardCode = <<<'PHP'
runScriptSafe(1, array('NEW_VALUE' => $params['NEW_VALUE'], 'NO_LINKED' => $params['NO_LINKED']));
PHP;
    $scriptCode = <<<'PHP'
\MajorDoMo\Core\Core::properties()->set(\MajorDoMo\Core\Core::$objects['Lamp'], 'status', $params['NEW_VALUE'] === '0' ? 1 : 0, $params['NO_LINKED']);
PHP;
    $cycleCode = <<<'PHP'
\MajorDoMo\Core\Core::properties()->set(\MajorDoMo\Core\Core::$objects['Sensor'], 'status', 0, $params['NO_LINKED']);
PHP;
    foreach (array(array(1,1,'changed',$changedCode),array(2,3,'forward',$forwardCode),array(3,2,'changed',$cycleCode)) as $row) {
        $pdo->prepare('INSERT INTO methods(ID,OBJECT_ID,TITLE,CODE) VALUES (?,?,?,?)')->execute($row);
    }
    $pdo->prepare("INSERT INTO scripts VALUES (1,'forward',?)")->execute(array($scriptCode));

    // Воркер загружает только одноразовые модули из временного каталога.
    $tempRoot = str_replace('\\', '/', sys_get_temp_dir());
    $fixture = $tempRoot . '/mjd-delivery-' . bin2hex(random_bytes(6));
    mkdir($fixture);
    register_shutdown_function(static function () use ($fixture, $tempRoot) {
        $resolved = str_replace('\\', '/', (string)realpath($fixture));
        if (!str_starts_with($resolved, $tempRoot . '/mjd-delivery-')) return;
        foreach (glob($fixture . '/*/*') as $file) unlink($file);
        foreach (glob($fixture . '/*') as $directory) rmdir($directory);
        rmdir($fixture);
    });
    define('DIR_MODULES', $fixture . '/');
    class DeliveryModuleFixture {
        public function processSubscription($event, $details) { ($GLOBALS['ingest'])(static::class); }
        public function propertySetHandle($object, $property, $value) {
            $GLOBALS['delivered'][] = array(static::class, $object, $property, $value);
            if ($GLOBALS['echo'] !== null) ($GLOBALS['echo'])();
        }
    }
    foreach (array('zigbee', 'mqtt', 'fixturemodule', 'witness') as $name) {
        mkdir($fixture . '/' . $name);
        file_put_contents($fixture . '/' . $name . '/' . $name . '.class.php', '<?php class ' . $name . ' extends DeliveryModuleFixture {}');
    }
    mkdir($fixture . '/scripts');
    file_put_contents($fixture . '/scripts/scripts.class.php', <<<'PHP'
<?php
class scripts {
    public function runScript($id, $params) {
        $row = $GLOBALS['pdo']->query('SELECT CODE FROM scripts WHERE ID=' . (int)$id)->fetch(PDO::FETCH_ASSOC);
        $variables = array('params' => &$params);
        return $GLOBALS['executor']->evaluate(\MajorDoMo\Core\Core::$objects['Relay'], $row['CODE'], $variables);
    }
}
PHP);
    function queued(): array { return $GLOBALS['pdo']->query("SELECT * FROM core_runtime_queue WHERE STATUS='pending' ORDER BY ID")->fetchAll(\PDO::FETCH_ASSOC); }
    function executeNext(): array {
        $row = queued()[0];
        $GLOBALS['pdo']->prepare('UPDATE core_runtime_queue SET CLAIM_TOKEN=?,CLAIM_UNTIL=? WHERE ID=?')->execute(array('fixture', date('Y-m-d H:i:s', time() + 60), $row['ID']));
        $result = $GLOBALS['worker']->execute((string)$row['ID'], 'fixture');
        check($result['success'], 'Операция выполнена: ' . ($result['error'] ?? $row['DATANAME']));
        $GLOBALS['pdo']->prepare('DELETE FROM core_runtime_queue WHERE ID=?')->execute(array($row['ID']));
        check(PropertyChain::delivery() === array('module' => '', 'point' => ''), 'Воркер очистил контекст');
        return $row;
    }
    function drain(): void {
        $limit = 40;
        while (queued()) {
            check($limit-- > 0, 'Цепочка обязана завершиться');
            executeNext();
        }
    }
    function resetDelivery(string $module): void {
        $GLOBALS['pdo']->exec("DELETE FROM core_runtime_queue; UPDATE pvalues SET VALUE=CASE ID WHEN 1 THEN '1' ELSE '0' END;
            UPDATE properties SET ONCHANGE=CASE ID WHEN 1 THEN 'changed' ELSE '' END");
        $GLOBALS['pdo']->prepare('UPDATE pvalues SET LINKED_MODULES=?')->execute(array($module . ',witness'));
        $GLOBALS['delivered'] = $GLOBALS['warnings'] = array();
        $GLOBALS['echo'] = null;
        PropertyChain::leave();
        $_GET = $_REQUEST = array(); unset($_SERVER['REQUEST_URI']);
    }

    // Используется настоящий Methods::callSafe: упрощённый raiseEvent в
    // фикстуре раньше скрывал синхронную ветку старого механизма.
    foreach (array('web', 'worker', 'lower_background') as $context) {
        resetDelivery('mqtt');
        $pdo->exec('UPDATE methods SET EXECUTED=NULL');
        $_SERVER['REQUEST_URI'] = $context === 'worker' ? 'core:modulepropertyset/mqtt' : '/api/v1/properties';
        $_GET = $context === 'worker' ? array('r_s_m' => 1) : array();
        MajorDoMo\Core\Config::$lowerBackground = $context === 'lower_background';
        Core::$properties->set(Core::$objects['Sensor'], 'status', 0, array('mqtt' => 0));
        $pending = queued();
        check(array_column($pending, 'DATANAME') === array('modulepropertyset', 'method'),
            'Уведомление и ONCHANGE поставлены в общую очередь: ' . $context);
        check(json_decode($pending[1]['DATAVALUE'], true)['target'] === 'Sensor.changed',
            'Очередь содержит исходный ONCHANGE, а не результат его преждевременного выполнения');
        check((int)$pdo->query('SELECT COUNT(*) FROM methods WHERE EXECUTED IS NOT NULL')->fetchColumn() === 0,
            'Код метода не выполнялся во время сохранения свойства: ' . $context);
        drain();
        check(in_array(array('mqtt', 'Lamp', 'status', 1), $delivered, true),
            'После разбора очереди цепочка дошла до MQTT: ' . $context);
    }
    MajorDoMo\Core\Config::$lowerBackground = false;

    foreach (array('zigbee', 'mqtt', 'fixturemodule') as $module) {
        resetDelivery($module);
        $ingest = static function ($module) { Core::$properties->set(Core::$objects['Lamp'], 'status', 1); };
        Core::$queue->enqueueRuntime('moduleevent', array('event' => 'MQTT_MESSAGE', 'details' => array()), $module);
        drain();
        check(in_array(array($module, 'Lamp', 'status', 1), $delivered, true), 'Прямая команда из события вернулась своему модулю: ' . $module);

        foreach (array('event', 'direct') as $mode) {
            resetDelivery($module);
            $ingest = static function ($module) { Core::$properties->set(Core::$objects['Sensor'], 'status', 0, array($module => 0)); };
            if ($mode === 'event') Core::$queue->enqueueRuntime('moduleevent', array('event' => 'MQTT_MESSAGE', 'details' => array()), $module);
            else $ingest($module);
            drain();
            check(!in_array($module, array_column(array_filter($delivered, fn($row) => $row[1] === 'Sensor'), 0), true), 'Нет эха входящего нуля: ' . $module);
            check(in_array(array('witness', 'Sensor', 'status', 0), $delivered, true), 'Ноль дошёл другому подписчику');
            check(in_array(array($module, 'Lamp', 'status', 1), $delivered, true), 'Метод → метод → сценарий → другое устройство того же модуля: ' . $module);
        }

        resetDelivery($module);
        // Задание старого ядра: NO_LINKED есть, области exclusions ещё нет.
        Core::$queue->enqueueRuntime('method', array('PROPERTY' => 'status', 'NEW_VALUE' => '0', 'NO_LINKED' => array($module => 0),
            '_chain' => array('property:Sensor.status'), '_delivery' => array('module' => $module, 'point' => '')), 'Sensor.changed');
        drain();
        check(in_array(array($module, 'Lamp', 'status', 1), $delivered, true), 'Старое задание ONCHANGE продолжилось без потери модуля');

        resetDelivery($module);
        $pdo->exec("UPDATE properties SET ONCHANGE='changed' WHERE ID=2");
        $ingest($module);
        drain();
        check(count($warnings) === 1 && str_contains($warnings[0], 'Sensor.status'), 'Цикл Sensor → сценарий → Lamp → метод → Sensor остановлен');
    }

    resetDelivery('fixturemodule');
    $echo = static function () { Core::$properties->set(Core::$objects['Lamp'], 'status', 1); };
    Core::$queue->enqueueRuntime('modulepropertyset', array('object' => 'Lamp', 'property' => 'status', 'value' => 1), 'fixturemodule');
    executeNext();
    check(count(queued()) === 1 && json_decode(queued()[0]['DATAVALUE'], true)['target'] === 'witness', 'Собственное эхо свойства подавлено и без входящей цепочки');

    resetDelivery('fixturemodule');
    $echo = static function () { Core::$properties->set(Core::$objects['Lamp'], 'status', 1); };
    Core::$queue->enqueueRuntime('modulepropertyset', array('object' => 'Lamp', 'property' => 'status', 'value' => 1,
        '_delivery' => array('module' => '', 'point' => '', 'exclusions' => array('fixturemodule' => 'property:Sensor.status'))), 'fixturemodule');
    executeNext();
    check(count(queued()) === 1 && json_decode(queued()[0]['DATAVALUE'], true)['target'] === 'witness',
        'Унаследованное исключение другого свойства не отменяет защиту собственного эха');

    resetDelivery('mqtt');
    PropertyChain::enter(array('property:Sensor.status'), '', '', array('mqtt' => 'property:Sensor.status'));
    (new MajorDoMo\Core\ApiClient(Core::$queue))->request('/api/method/Relay.forward', 'GET', array(), false);
    check(json_decode(queued()[0]['DATAVALUE'], true)['params']['_delivery']['exclusions'] === array('mqtt' => 'property:Sensor.status'), 'Внутренний API сохраняет область прямого входа MQTT');
    PropertyChain::leave();
    echo 'Property delivery smoke: ' . $checks . " passed; SQLite :memory:, no external connections.\n";
}
