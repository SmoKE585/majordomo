<?php

/**
 * Запись свойств на SQLite :memory: с подменой внешних операций, без загрузки приложения.
 * 2026-10-25 — порядок уведомлений и ONCHANGE при следующей записи сразу после освобождения блокировки.
 * 2026-10-23 — проверка отсутствия повторов WebSocket при сохранении обработчиков.
 * 2026-10-25 — входящие события и NO_LINKED разных модулей не блокируют следующую привязку.
 */
namespace MajorDoMo\Core {
    class Config { public static function bool($key) { return false; } }
    class Cache { public function get($key) { return false; } public function set($key, $value) {} public function delete($key) {} }
    class MetadataCache { public function get($key, $load) { return $load(); } }
    class PropertyLock {
        public bool $released = false;
        public ?\Closure $afterRelease = null;
        public function acquire($id, $property) { $this->released = false; return 1; }
        public function check($lock) {}
        public function release($lock) {
            $this->released = true;
            $callback = $this->afterRelease;
            $this->afterRelease = null;
            if ($callback) $callback();
        }
    }
    class WebSocketQueue {
        public array $posts = array();
        public function post($property, $value) { $this->posts[] = array($property, $value); }
    }
    class CodeExecutor {}
    class Core {
        public static function queue() { return new class {
            public function enqueueRuntime($operation, $params, $target) {
                $GLOBALS['dispatches'][] = array($operation, $target, (string)($params['NEW_VALUE'] ?? $params['value']));
                if ($operation === 'method') {
                    \check(!$GLOBALS['locks']->released, 'ONCHANGE ставится до освобождения блокировки');
                    $query = $GLOBALS['pdo']->prepare('SELECT VALUE FROM pvalues WHERE PROPERTY_NAME=?');
                    $query->execute(array('Sensor.' . $params['PROPERTY']));
                    $GLOBALS['onchangeCalls'][] = array('queued', $params, $query->fetchColumn());
                    if ($GLOBALS['onchangeFailure'] === 'exception') throw new \RuntimeException('simulated ONCHANGE queue failure');
                    return $GLOBALS['onchangeFailure'] !== 'false';
                }
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
        public function Exec($sql, $ignore = false) { $this->pdo->exec($sql === 'START TRANSACTION' ? 'BEGIN' : $sql); return true; }
        public function inTransaction() { return $this->pdo->inTransaction(); }
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
        public $id = 1; public $class_id = 0; public $object_title = 'Sensor';
        public function callMethod($name, $params) { throw new RuntimeException('ONCHANGE не должен выполняться внутри записи свойства'); }
        public function raiseEvent($name, $params) { throw new RuntimeException('Внутренний ONCHANGE не должен зависеть от старого raiseEvent'); }
    };
    $checks = 0;
    function check($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
    $_SERVER['REQUEST_URI'] = '/mqtt/module/zigbee';
    $failQueue = false; $throwQueue = false; $queued = array();
    $onchangeCalls = $dispatches = array(); $onchangeFailure = '';
    $properties->set($object, 'motion', 1, array('zigbee' => 0));
    check(count($onchangeCalls) === 1 && $onchangeCalls[0][2] === '1', 'New value saved before ONCHANGE');
    check($websockets->posts === array(array('Sensor.motion', 1)), 'Changed value is queued for WebSocket');
    check($queued === array(), 'Excluded source module is not echoed');
    $properties->set($object, 'motion', 1, array('zigbee' => 0));
    check(count($onchangeCalls) === 2, 'Repeated value still triggers ONCHANGE');
    check(count($websockets->posts) === 1, 'Repeated integer does not queue the same stored string for WebSocket');
    $pdo->exec("UPDATE pvalues SET LINKED_MODULES='zigbee,broken,healthy'");
    foreach (array('false', 'exception') as $failure) {
        $failQueue = $failure === 'false'; $throwQueue = $failure === 'exception'; $queued = array();
        $before = count($onchangeCalls); $caught = null;
        try { $properties->set($object, 'motion', 0, array('zigbee' => 0)); } catch (RuntimeException $e) { $caught = $e; }
        check($caught !== null, 'Ошибка сохранения задания видна вызывающему коду');
        check(count($onchangeCalls) === $before && $pdo->query('SELECT VALUE FROM pvalues WHERE ID=1')->fetchColumn() === '1', 'Ошибка очереди откатывает значение и не ставит ONCHANGE');
        check($locks->released, 'Property lock released after failure');
        check($queued === array(array('modulepropertyset', 'broken')), 'После отказа SQL оставшаяся запись прекращается');
    }
    unset($_SERVER['REQUEST_URI']); $failQueue = false; $throwQueue = false;
    $properties->set($object, 'motion', 1, array('zigbee' => 0));
    check(end($onchangeCalls)[0] === 'queued', 'CLI and web both dispatch ONCHANGE asynchronously');

    $pdo->exec("UPDATE pvalues SET LINKED_MODULES='mqtt'");
    $before = count($onchangeCalls); $queued = array();
    MajorDoMo\Core\PropertyChain::enter(array('property:Sensor.motion'), 'mqtt');
    try { $properties->set($object, 'motion', 0); } finally { MajorDoMo\Core\PropertyChain::leave(); }
    check($queued === array() && count($onchangeCalls) === $before
        && $pdo->query('SELECT VALUE FROM pvalues WHERE ID=1')->fetchColumn() === '0',
        'Repeated property in a delivery chain stops notifications but saves the value');

    // Вложенная привязка: модуль, который доставил предыдущее свойство, не
    // должен выпадать из уведомлений о другом своём свойстве. Иначе цепочка
    // «zigbee → кнопка → метод → свойство устройства» не доходит до устройства.
    $pdo->exec("INSERT INTO properties VALUES (2,1,0,'cmd',0,0,0,'cmdChanged')");
    $pdo->exec("INSERT INTO pvalues VALUES (2,2,1,'0','','','Sensor.cmd','zigbee,yandexhome')");
    MajorDoMo\Core\PropertyChain::enter(array('property:Sensor.motion'), 'zigbee', 'property:Sensor.motion');
    $queued = array(); $before = count($onchangeCalls);
    $inherited = MajorDoMo\Core\PropertyChain::scopedExclusions(array('zigbee' => 0), array('zigbee' => 'property:Sensor.motion'));
    try { $properties->set($object, 'cmd', 1, $inherited, 'zigbee'); } finally { MajorDoMo\Core\PropertyChain::leave(); }
    check($queued === array(array('modulepropertyset', 'zigbee'), array('modulepropertyset', 'yandexhome')),
        'Source module stays subscribed to another property in the same chain');
    check(count($onchangeCalls) === $before + 1 && !isset(end($onchangeCalls)[1]['NO_LINKED']['zigbee']),
        'Nested ONCHANGE does not inherit the source module exclusion');
    check((end($onchangeCalls)[1]['_delivery'] ?? null) === array('module' => 'zigbee', 'point' => 'property:Sensor.motion'),
        'ONCHANGE receives the delivery point needed for cross-process echo scoping');

    foreach (array('', 'zigbee') as $sourceModule) {
        MajorDoMo\Core\PropertyChain::enter(array('property:Sensor.motion'), $sourceModule, 'property:Sensor.motion', array('zigbee' => 'property:Sensor.motion'));
        $queued = array();
        try { $properties->set($object, 'cmd', 1, array('zigbee' => 0)); } finally { MajorDoMo\Core\PropertyChain::leave(); }
        check($queued === array(array('modulepropertyset', 'yandexhome')), 'Новое явное исключение не снимается контекстом предыдущего свойства');
    }

    // Собственное эхо по тому же свойству по-прежнему гасится.
    MajorDoMo\Core\PropertyChain::enter(array('property:Sensor.other'), 'zigbee', 'property:Sensor.cmd');
    $queued = array(); $before = count($onchangeCalls);
    try { $properties->set($object, 'cmd', 0); } finally { MajorDoMo\Core\PropertyChain::leave(); }
    check($queued === array(array('modulepropertyset', 'yandexhome')),
        'Source module does not receive its own echo for the delivered property');

    // boolean — это состояние 0/1, а не «пусто»: иначе false сохранялся как '',
    // карточка свойства показывала «Значение не задано», а журнал — 0.
    $properties->set($object, 'cmd', false);
    check($pdo->query('SELECT VALUE FROM pvalues WHERE ID=2')->fetchColumn() === '0',
        'Boolean false is stored as 0, not as an empty string');
    check(end($onchangeCalls)[1]['NEW_VALUE'] === '0', 'ONCHANGE receives 0 for boolean false');
    $properties->set($object, 'cmd', true);
    check($pdo->query('SELECT VALUE FROM pvalues WHERE ID=2')->fetchColumn() === '1',
        'Boolean true is stored as 1');

    // Правило одинаково для любого модуля, включая прямую запись MQTT вне воркера.
    foreach (array('zigbee', 'mqtt', 'fixturemodule') as $module) {
        $pdo->exec("UPDATE pvalues SET LINKED_MODULES='" . $module . ",yandexhome'");
        MajorDoMo\Core\PropertyChain::enter(array(), $module);
        $queued = array();
        $properties->set($object, 'cmd', 1);
        check($queued === array(array('modulepropertyset', $module), array('modulepropertyset', 'yandexhome')),
            'Входящее событие не исключает свой модуль: ' . $module);
        MajorDoMo\Core\PropertyChain::leave();

        foreach (array('', $module) as $sourceModule) {
            MajorDoMo\Core\PropertyChain::enter(array(), $sourceModule);
            $queued = array();
            $properties->set($object, 'motion', 0, array($module => 0));
            $onchange = end($onchangeCalls)[1];
            check($queued === array(array('modulepropertyset', 'yandexhome')) && $onchange['NEW_VALUE'] === '0',
                'Входящий ноль не отражается источнику, но доставляется другим подписчикам');
            check($onchange['_delivery']['exclusions'] === array($module => 'property:Sensor.motion'),
                'NO_LINKED получает область исходного свойства');
            MajorDoMo\Core\PropertyChain::leave();

            $delivery = $onchange['_delivery'];
            MajorDoMo\Core\PropertyChain::enter($onchange['_chain'], $delivery['module'], $delivery['point'], $delivery['exclusions']);
            $queued = array();
            $properties->set($object, 'cmd', 1, $onchange['NO_LINKED']);
            check($queued === array(array('modulepropertyset', $module), array('modulepropertyset', 'yandexhome')),
                'Унаследованный NO_LINKED не блокирует другое свойство: ' . $module);
            check(!isset(end($onchangeCalls)[1]['NO_LINKED'][$module]), 'Следующий ONCHANGE не наследует снятое исключение');
            MajorDoMo\Core\PropertyChain::leave();
            check(MajorDoMo\Core\PropertyChain::exclusions() === array(), 'Контекст исключений очищен между доставками');
        }
    }
    $pdo->exec("UPDATE pvalues SET LINKED_MODULES='zigbee,mqtt,yandexhome'");
    $queued = array();
    $properties->set($object, 'motion', 0, array('zigbee' => 0, 'mqtt' => false, 'yandexhome' => null));
    $onchange = end($onchangeCalls)[1];
    check($queued === array(array('modulepropertyset', 'yandexhome')),
        'Несколько явных исключений распознают 0 и false, но не null');
    check($onchange['_delivery']['exclusions'] === array('zigbee' => 'property:Sensor.motion', 'mqtt' => 'property:Sensor.motion'),
        'Область содержит все исключённые модули и пропускает null');
    MajorDoMo\Core\PropertyChain::enter($onchange['_chain'], '', '', $onchange['_delivery']['exclusions']);
    $queued = array();
    $properties->set($object, 'cmd', 1, $onchange['NO_LINKED']);
    check($queued === array(array('modulepropertyset', 'zigbee'), array('modulepropertyset', 'mqtt'), array('modulepropertyset', 'yandexhome')),
        'Все унаследованные исключения сняты на другом свойстве');
    MajorDoMo\Core\PropertyChain::leave();
    $pdo->exec("UPDATE pvalues SET LINKED_MODULES='zigbee,yandexhome' WHERE ID=2");

    // Тип входного значения не меняет состояние, но разные строки остаются разными:
    // '01' и '1' нельзя склеивать нестрогим числовым сравнением.
    foreach (array(
        array('1', false), array(1, false), array(1.0, false), array(true, false),
        array(0, true), array(false, false), array(0.0, false), array('0', false),
        array('', true), array(null, false), array('01', true), array(1, true),
        array('1.0', true), array(1.0, true), array(1.5, true), array(1.5, false),
    ) as [$input, $changed]) {
        $websockets->posts = array(); $queued = array(); $before = count($onchangeCalls);
        $properties->set($object, 'cmd', $input);
        check(count($websockets->posts) === (int)$changed,
            'WebSocket follows the stored value for ' . var_export($input, true));
        check(count($onchangeCalls) === $before + 1
            && $queued === array(array('modulepropertyset', 'zigbee'), array('modulepropertyset', 'yandexhome')),
            'ONCHANGE and linked modules still receive every assignment');
    }

    // Другой писатель начинает сразу после unlock: его задания обязаны идти
    // после всех заданий предыдущей записи, включая ONCHANGE.
    $pdo->exec("UPDATE pvalues SET LINKED_MODULES='mqtt',VALUE='1' WHERE ID=1");
    $queued = $dispatches = $onchangeCalls = array();
    $locks->afterRelease = static function () use ($properties, $object) {
        $properties->set($object, 'motion', 1);
    };
    $properties->set($object, 'motion', 0);
    check($dispatches === array(
        array('modulepropertyset', 'mqtt', '0'), array('method', 'Sensor.onMotion', '0'),
        array('modulepropertyset', 'mqtt', '1'), array('method', 'Sensor.onMotion', '1'),
    ), 'Следующая запись не обгоняет ONCHANGE предыдущего значения');
    check(array_map(static fn($call) => array($call[1]['OLD_VALUE'], $call[1]['NEW_VALUE']), $onchangeCalls)
        === array(array('1', '0'), array('0', '1')), 'ONCHANGE сохраняет пары старого и нового значения');
    check($pdo->query('SELECT VALUE FROM pvalues WHERE ID=1')->fetchColumn() === '1' && $locks->released,
        'Обе записи завершены, последнее значение сохранено и блокировка снята');

    foreach (array('false', 'exception') as $failure) {
        $onchangeFailure = $failure; $caught = null;
        try { $properties->set($object, 'motion', 0); } catch (RuntimeException $e) { $caught = $e; }
        check($caught !== null && $locks->released, 'Отказ постановки ONCHANGE виден вызывающему коду и освобождает блокировку');
        check($pdo->query('SELECT VALUE FROM pvalues WHERE ID=1')->fetchColumn() === '1',
            'Отказ постановки ONCHANGE откатывает изменение свойства');
        $onchangeFailure = '';
        $before = count($onchangeCalls);
        $properties->set($object, 'motion', 1);
        check(count($onchangeCalls) === $before + 1, 'После отказа следующий ONCHANGE не подавляется');
    }
    $pdo->exec('UPDATE properties SET VALIDATION_TYPE=2 WHERE ID=1');
    $before = count($dispatches);
    check($properties->set($object, 'motion', 3) === false && $locks->released && count($dispatches) === $before,
        'Отклонённая валидация освобождает блокировку без уведомлений');
    $pdo->exec('UPDATE properties SET VALIDATION_TYPE=0 WHERE ID=1');
    check($properties->set($object, 'motion', 0, 1) === null && $locks->released && count($dispatches) === $before,
        'Старый no_linked=1 сохраняет значение без обработчиков и освобождает блокировку');

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
