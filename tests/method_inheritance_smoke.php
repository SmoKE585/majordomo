<?php

/** 2026-10-23 — регрессии наследования: реальные Methods/CodeExecutor, SQLite в памяти, внешние операции подменены. */
namespace MajorDoMo\Core {
    final class Config
    {
        public static function get($key, $default = null) { return $key === 'USE_REDIS' ? false : $default; }
        public static function has($key) { return false; }
        public static function bool($key) { return false; }
    }
    final class Core
    {
        public static array $queued = array();
        public static $audit = null;
        public static function audit() { return self::$audit; }
        public static function queue(): object
        {
            return new class {
                public function enqueueRuntime($operation, $params, $target): bool
                {
                    Core::$queued[] = array($operation, $params, $target);
                    return true;
                }
            };
        }
    }
}

namespace {
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
    putenv('USE_REDIS=false');
    error_reporting(E_ALL);
    set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
    foreach (array('Database', 'RedisConnection', 'MetadataCache', 'CodeExecutor', 'Methods', 'PropertyAudit', 'PropertyChain') as $name) {
        require dirname(__DIR__) . '/lib/core/' . $name . '.php';
    }
    require dirname(__DIR__) . '/lib/core/Helpers/Scripts.php';
    function verbose_log(...$args) {}
    function startMeasure(...$args) {}
    function endMeasure(...$args) {}
    function setEvalCode(...$args) {}
    function isItPythonCode($code) { return false; }
    function registerError(...$args) { $GLOBALS['reported'][] = $args; }
    function sg($name, $value)
    {
        $old = $GLOBALS['states'][$name] ?? null;
        $GLOBALS['states'][$name] = $value;
        if (MajorDoMo\Core\Core::$audit && $old !== $value) MajorDoMo\Core\Core::$audit->propertyChanged(array(
            'title' => $name, 'object_id' => 1, 'property_id' => 11, 'old_value' => $old, 'new_value' => $value));
    }
    function gg($name) { return $GLOBALS['states'][$name] ?? null; }
    function check($condition, $message): void
    {
        if (!$condition) throw new RuntimeException($message);
        $GLOBALS['checks']++;
    }

    $checks = 0;
    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    check(getenv('APP_ENV') === 'testing' && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Изоляция тестов подтверждена');
    $pdo->exec('CREATE TABLE classes (ID INTEGER PRIMARY KEY, PARENT_ID INTEGER);
        CREATE TABLE methods (ID INTEGER PRIMARY KEY, TITLE TEXT, OBJECT_ID INTEGER, CLASS_ID INTEGER,
            CALL_PARENT INTEGER, SCRIPT_ID INTEGER, CODE TEXT, EXECUTED TEXT, EXECUTED_SRC TEXT, EXECUTED_PARAMS TEXT)');
    $connection = new class($pdo) {
        public function __construct(private PDO $pdo) {}
        public function DbSafe1($value) { return str_replace("'", "''", (string)$value); }
        public function SelectOne($sql) { return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: array(); }
        public function Select($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
        public function Exec($sql, $ignoreErrors = false) { $this->pdo->exec($sql); return true; }
        public function Insert($table, &$row)
        {
            $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            return (int)$this->pdo->lastInsertId();
        }
        public function Update($table, $row, $key = 'ID')
        {
            $id = $row[$key]; unset($row[$key]);
            return $this->pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(fn($field) => $field . '=?', array_keys($row))) . ' WHERE ' . $key . '=?')->execute(array_merge(array_values($row), array($id)));
        }
    };
    $db = new MajorDoMo\Core\Database($connection);
    $methods = new MajorDoMo\Core\Methods($db, new MajorDoMo\Core\MetadataCache($db, new MajorDoMo\Core\RedisConnection()), new MajorDoMo\Core\CodeExecutor());
    $object = new class {
        public $object_title = 'OC_Toilet'; public $class_title = 'Sensor'; public $class_id = 1; public $id = 1;
        public array $seen = array(); public $service;
    };
    $object->service = $methods;
    function methodRow(int $id, int $objectId, int $classId, int $order, string $code, int $scriptId = 0, string $title = 'data'): void
    {
        $GLOBALS['pdo']->prepare('INSERT INTO methods (ID,TITLE,OBJECT_ID,CLASS_ID,CALL_PARENT,SCRIPT_ID,CODE) VALUES (?,?,?,?,?,?,?)')
            ->execute(array($id, $title, $objectId, $classId, $order, $scriptId, $code));
    }
    function resetMethods(): void
    {
        $GLOBALS['pdo']->exec('DELETE FROM methods; DELETE FROM classes; INSERT INTO classes VALUES (1,2),(2,3),(3,0)');
        $GLOBALS['object']->seen = array();
        $GLOBALS['states'] = array('Toilet.turnOnWhenOpen' => 1, 'Toilet.nightMode_status' => 0, 'Light_Toilet.status' => 0);
    }

    // Исходный сбой: класс инвертирует контакт, объект должен включить свет.
    resetMethods();
    methodRow(1, 1, 0, 1, <<<'PHP'
if (gg('Toilet.turnOnWhenOpen') != 1) return;
if ($params['NEW_VALUE'] != 1) {
    if (gg('Toilet.nightMode_status') == 1) sg('Light_ToiletNochnik.status', 1);
    else sg('Light_Toilet.status', '1');
}
$this->seen[] = $params;
PHP);
    methodRow(2, 0, 1, 0, '$params["NEW_VALUE"] = 0; sg("OC_Toilet.status", 0);');
    $input = array('VALUE' => 1, 'NEW_VALUE' => 1, 'OLD_VALUE' => 0, 'RAW_VALUE' => true);
    check($methods->call($object, 'data', $input) === true, 'Сохраняется прежний результат метода без return');
    check(gg('Light_Toilet.status') === '1' && $object->seen[0]['NEW_VALUE'] === 0, 'Инверсия класса доходит до объекта и включает свет');
    check($input['NEW_VALUE'] === 1 && !isset($input['ORIGINAL_OBJECT_TITLE']), 'Публичный вызов не меняет массив вызывающего кода');
    $states['Toilet.nightMode_status'] = 1;
    $methods->call($object, 'data', $input);
    check(gg('Light_ToiletNochnik.status') === 1, 'Ночная ветка получает нормализованное значение');

    // Несколько уровней, в том числе класс без переопределения метода.
    resetMethods();
    methodRow(1, 1, 0, 1, '$this->seen[] = $params;');
    methodRow(2, 0, 2, 1, '$params["middle"] = $params["root"] + 1;');
    methodRow(3, 0, 3, 0, '$params["root"] = 10; unset($params["drop"]);');
    $methods->call($object, 'data', array('drop' => true));
    check($object->seen[0]['middle'] === 11 && !isset($object->seen[0]['drop']), 'Добавление и удаление параметров проходят через все уровни');
    check(count($object->seen) === 1, 'Пропущенный класс не вызывает повторное выполнение найденного метода');

    resetMethods();
    methodRow(1, 1, 0, 2, '$params["NEW_VALUE"] = 7; $this->seen[] = "object";');
    methodRow(2, 0, 1, 2, '$this->seen[] = $params["NEW_VALUE"]; $params["NEW_VALUE"] = 8;');
    methodRow(3, 0, 2, 0, '$this->seen[] = $params["NEW_VALUE"];');
    $methods->call($object, 'data', array('NEW_VALUE' => 1));
    check($object->seen === array('object', 7, 8), 'Родитель после объекта видит изменения всех предыдущих уровней');

    resetMethods();
    methodRow(1, 1, 0, 1, '$this->seen[] = $params;');
    methodRow(2, 0, 1, 0, '$params = array("replaced" => true);');
    $methods->call($object, 'data', array('old' => 1));
    check($object->seen === array(array('replaced' => true)), 'Полная замена массива родителем сохраняется');

    foreach (array(false, 0, '', array('result' => 1)) as $result) {
        resetMethods();
        methodRow(1, 1, 0, 0, 'return ' . var_export($result, true) . ';');
        check($methods->call($object, 'data') === $result, 'Явный результат возвращается без подмены');
    }
    foreach (array(1, 2) as $order) {
        resetMethods();
        methodRow(1, 1, 0, $order, '');
        methodRow(2, 0, 1, 0, 'return false;');
        check($methods->call($object, 'data') === false, 'Отказ родителя не превращается в успех при порядке ' . $order);
        resetMethods();
        methodRow(1, 1, 0, $order, '$this->seen[] = "child"; return true;');
        methodRow(2, 0, 1, 0, 'return false;');
        check($methods->call($object, 'data') === false, 'Явный успех дочернего метода не маскирует отказ родителя');
        check($object->seen === ($order === 1 ? array() : array('child')), 'После отказа родителя до объекта дочерние действия не выполняются');
    }
    resetMethods();
    methodRow(1, 1, 0, 2, 'return false;');
    methodRow(2, 0, 1, 0, '$this->seen[] = "parent";');
    check($methods->call($object, 'data') === false && !$object->seen, 'Отказ объекта не запускает родителя после него');

    resetMethods();
    methodRow(1, 1, 0, 1, '$this->seen[] = "must not run";');
    methodRow(2, 0, 1, 0, 'throw new \RuntimeException("parent failure");');
    try { $methods->call($object, 'data'); throw new LogicException('Ожидалось исключение'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'parent failure' && $object->seen === array(), 'Исключение родителя доходит до вызывающего кода'); }

    resetMethods();
    $pdo->exec('UPDATE classes SET PARENT_ID=1 WHERE ID=3');
    methodRow(1, 0, 1, 1, '');
    methodRow(2, 0, 2, 1, '');
    try { $methods->call($object, 'data'); throw new LogicException('Цикл не обнаружен'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Цикл наследования'), 'Цикл классов останавливается с явной ошибкой'); }

    resetMethods();
    methodRow(1, 1, 0, 1, '', 42);
    methodRow(2, 0, 1, 0, '$params["NEW_VALUE"] = 0;');
    check($methods->call($object, 'data', array('NEW_VALUE' => 1)) === true, 'Связанный сценарий принят в очередь');
    $queued = end(MajorDoMo\Core\Core::$queued);
    check($queued[0] === 'script' && $queued[2] === '42' && $queued[1]['NEW_VALUE'] === 0, 'Сценарий получает параметры после родителя');

    resetMethods();
    methodRow(1, 1, 0, 0, '$this->service->call($this, "other", array("value" => 2)); $this->seen[] = $params["value"];');
    methodRow(2, 1, 0, 0, '$params["value"] = 9; $this->seen[] = $params["value"];', 0, 'other');
    $methods->call($object, 'data', array('value' => 1));
    check($object->seen === array(9, 1), 'Независимый вложенный вызов не меняет параметры внешнего метода');
    check($methods->call($object, 'missing') === false, 'Отсутствующий метод возвращает false');
    foreach (array('text', 7, true) as $value) {
        resetMethods();
        methodRow(1, 1, 0, 0, 'return $params;');
        check($methods->call($object, 'data', $value) === $value, 'Скалярный параметр объекта не ломает запись метаданных');
        resetMethods();
        methodRow(1, 0, 1, 0, 'return $params;');
        check($methods->call($object, 'data', $value) === $value, 'Скалярный параметр класса не используется как массив');
    }
    // Совместно проверяем исполнение и журнал: копии входа не должны меняться
    // задним числом, а выход объекта должен содержать нормализацию класса.
    $pdo->exec('CREATE TABLE property_audit_runs (ID INTEGER PRIMARY KEY AUTOINCREMENT, TRACE_ID TEXT UNIQUE, STARTED TEXT, LAST_SEEN TEXT,
        FINISHED TEXT, STATUS TEXT, TRIGGER_TYPE TEXT, TRIGGER_TITLE TEXT, SOURCE TEXT, ACTOR TEXT, IP TEXT, REQUEST_URI TEXT,
        PARAMS TEXT, RESULT TEXT, ERROR TEXT, CHAIN TEXT, EVENTS_COUNT INTEGER DEFAULT 0);
        CREATE TABLE property_audit_events (ID INTEGER PRIMARY KEY AUTOINCREMENT, RUN_ID INTEGER, TRACE_ID TEXT, ADDED TEXT,
        FINISHED TEXT, DURATION_MS INTEGER, TYPE TEXT, STATUS TEXT, TITLE TEXT, SUBTITLE TEXT, OBJECT_ID INTEGER,
        PROPERTY_ID INTEGER, PROPERTY_NAME TEXT, METHOD_ID INTEGER, CLASS_ID INTEGER, SCRIPT_ID INTEGER, MODULE TEXT,
        OLD_VALUE TEXT, NEW_VALUE TEXT, SOURCE TEXT, PARAMS TEXT, OUT_PARAMS TEXT, RESULT TEXT, ERROR TEXT, CHAIN TEXT)');
    // Таблицы уже созданы в изолированной SQLite; штатная миграция MySQL здесь не нужна.
    (new ReflectionProperty(MajorDoMo\Core\PropertyAudit::class, 'schemaReady'))->setValue(null, true);
    $audit = new MajorDoMo\Core\PropertyAudit($db);
    MajorDoMo\Core\Core::$audit = $audit;
    $auditedMethods = new MajorDoMo\Core\Methods($db, new MajorDoMo\Core\MetadataCache($db, new MajorDoMo\Core\RedisConnection()), new MajorDoMo\Core\CodeExecutor(), $audit);
    resetMethods();
    $objectCode = 'if ($params["NEW_VALUE"] != 1) sg("Light_Toilet.status", 1);';
    methodRow(1, 1, 0, 1, $objectCode);
    methodRow(2, 0, 1, 0, '$params["NEW_VALUE"] = 0;');
    $auditedMethods->call($object, 'data', array('NEW_VALUE' => 1));
    $page = $audit->tracesForProperty(1, 11, time() - 60, time() + 60);
    $events = $page['runs'][0]['events'];
    check($events[1]['params']['NEW_VALUE'] === 1 && $events[1]['out_params']['NEW_VALUE'] === 0, 'Журнал объекта фиксирует исходный вход и нормализованный выход');
    check($events[2]['parent_event_id'] === $events[1]['id'] && $events[2]['out_params']['NEW_VALUE'] === 0, 'Журнал связывает объект и выполнившийся класс');
    check($events[1]['execution']['code'] === $objectCode && $events[3]['title'] === 'Light_Toilet.status', 'В одной цепочке сохранены код и фактическая запись света');
    check($events[3]['parent_event_id'] === $events[1]['id'], 'Свет связан с методом объекта, а не уже завершённым родителем');
    echo 'Method inheritance smoke: ' . $checks . " passed; SQLite :memory:, no external connections.\n";
}
