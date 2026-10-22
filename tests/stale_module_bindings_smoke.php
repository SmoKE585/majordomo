<?php

/** Real property/worker services on SQLite :memory:, with modules in a disposable fixture. */
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('USE_REDIS=false');
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Core;
use MajorDoMo\Core\Database;
use MajorDoMo\Core\RedisConnection;

$tempRoot = str_replace('\\', '/', sys_get_temp_dir());
$fixture = $tempRoot . '/mjd-stale-' . bin2hex(random_bytes(6));
mkdir($fixture . '/modules', 0777, true);
register_shutdown_function(static function () use ($fixture, $tempRoot) {
    $resolved = str_replace('\\', '/', (string)realpath($fixture));
    if (!str_starts_with($resolved, $tempRoot . '/mjd-stale-')) return;
    $remove = static function ($path) use (&$remove) {
        if (!is_dir($path)) { unlink($path); return; }
        foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
        rmdir($path);
    };
    $remove($fixture);
});
define('DIR_MODULES', $fixture . '/modules/');
Config::boot($fixture);
if (rtrim(str_replace('\\', '/', DIR_MODULES), '/') !== $fixture . '/modules') {
    throw new RuntimeException('Test modules must stay inside the disposable fixture');
}
$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || Config::get('USE_REDIS')) {
    throw new RuntimeException('Isolated test environment was not established');
}
$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('GET_LOCK', fn($name, $timeout) => 1);
$pdo->sqliteCreateFunction('RELEASE_LOCK', fn($name) => 1);
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });

/** Translate only MySQL syntax; all selects, updates and queue claims execute on SQLite. */
class StaleBindingsTestDatabase
{
    private int $affected = 0;
    private string $error = '';
    public $beforeRemoval = null;
    public bool $failRemoval = false;
    public function __construct(public PDO $pdo) {}
    public function DbSafe1($value) { return str_replace("'", "''", (string)$value); }
    public function DbSafe($value) { return $this->DbSafe1($value); }
    public function Select($sql)
    {
        if (stripos($sql, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            $tables = array();
            foreach ($this->pdo->query("SELECT name FROM sqlite_master WHERE type='table'") as $table) {
                $columns = $this->pdo->query("PRAGMA table_info(`" . str_replace('`', '', $table['name']) . "`)")->fetchAll(PDO::FETCH_ASSOC);
                if (in_array('LINKED_OBJECT', array_column($columns, 'name'), true)) $tables[] = array('TABLE_NAME' => $table['name']);
            }
            return $tables;
        }
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    public function SelectOne($sql)
    {
        if (str_contains($sql, 'information_schema.TABLES')) {
            preg_match("/TABLE_NAME='([^']+)'/", $sql, $match);
            return $this->pdo->query("SELECT name AS TABLE_NAME FROM sqlite_master WHERE name='" . $this->DbSafe1($match[1]) . "'")->fetch(PDO::FETCH_ASSOC) ?: array();
        }
        return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: array();
    }
    public function Exec($sql, $ignoreErrors = false)
    {
        if (str_starts_with($sql, 'UPDATE pvalues SET LINKED_MODULES=')) {
            if ($this->beforeRemoval) ($this->beforeRemoval)();
            if ($this->failRemoval) { $this->error = 'simulated write failure'; return false; }
        }
        $sql = preg_replace('/\bBINARY\s+/', '', $sql); // SQLite text equality is binary by default.
        $sql = preg_replace('/^INSERT IGNORE INTO/i', 'INSERT OR IGNORE INTO', $sql);
        $sql = preg_replace('/^START TRANSACTION$/i', 'BEGIN', $sql);
        if (preg_match('/^(UPDATE operations_queue SET .+?) WHERE (.+) ORDER BY ID LIMIT (\d+)$/s', $sql, $match)) {
            $sql = $match[1] . ' WHERE ID IN (SELECT ID FROM operations_queue WHERE ' . $match[2] . ' ORDER BY ID LIMIT ' . $match[3] . ')';
        } elseif (preg_match('/^DELETE FROM operations_queue WHERE (.+) LIMIT (\d+)$/s', $sql, $match)) {
            $sql = 'DELETE FROM operations_queue WHERE ID IN (SELECT ID FROM operations_queue WHERE ' . $match[1] . ' LIMIT ' . $match[2] . ')';
        }
        $this->affected = $this->pdo->exec($sql);
        return true;
    }
    public function AffectedRows() { return $this->affected; }
    public function getLastError() { return array('message' => $this->error, 'code' => 0); }
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
}
$pdo->exec("CREATE TABLE objects (ID INTEGER PRIMARY KEY, TITLE TEXT, CLASS_ID INTEGER DEFAULT 0);
    CREATE TABLE classes (ID INTEGER PRIMARY KEY, PARENT_ID INTEGER DEFAULT 0);
    CREATE TABLE properties (ID INTEGER PRIMARY KEY, TITLE TEXT, OBJECT_ID INTEGER DEFAULT 1, CLASS_ID INTEGER DEFAULT 0,
        VALIDATION_TYPE INTEGER DEFAULT 0, DATA_TYPE INTEGER DEFAULT 0, KEEP_HISTORY INTEGER DEFAULT 0, ONCHANGE TEXT DEFAULT '');
    CREATE TABLE pvalues (ID INTEGER PRIMARY KEY, OBJECT_ID INTEGER, PROPERTY_ID INTEGER, PROPERTY_NAME TEXT DEFAULT '',
        VALUE TEXT, UPDATED TEXT, SOURCE TEXT DEFAULT '', LINKED_MODULES TEXT);
    CREATE TABLE cached_values (KEYWORD TEXT PRIMARY KEY, DATAVALUE TEXT);
    CREATE TABLE operations_queue (ID INTEGER PRIMARY KEY AUTOINCREMENT, TOPIC TEXT, DATANAME TEXT, DATAVALUE TEXT, EXPIRE TEXT,
        CLAIM_TOKEN TEXT DEFAULT '', CLAIM_UNTIL TEXT, ATTEMPTS INTEGER DEFAULT 0);
    CREATE TABLE operations_failed (ID INTEGER PRIMARY KEY AUTOINCREMENT, TOPIC TEXT, PAYLOAD TEXT, LAST_ERROR TEXT, ADDED TEXT);
    INSERT INTO objects(ID,TITLE) VALUES (1,'Lamp'),(2,'OtherLamp');
    INSERT INTO properties(ID,TITLE) VALUES (1,'status'),(2,'power');
    INSERT INTO pvalues(ID,OBJECT_ID,PROPERTY_ID,VALUE,UPDATED,LINKED_MODULES) VALUES
        (1,1,1,'current value','2026-10-21 19:00:00','badsubscriber,goodsubscriber'),
        (2,2,1,'other object','2026-10-21 19:00:00','badsubscriber'),
        (3,1,2,'other property','2026-10-21 19:00:00','badsubscriber');");
$connection = new StaleBindingsTestDatabase($pdo);
$services = new ReflectionProperty(Core::class, 'services');
$services->setValue(null, array('database' => new Database($connection), 'redis' => new RedisConnection()));
$worker = Core::worker();
$checks = 0;
$logs = array();
$calls = array();
function DebMes($message, $channel = '') { $GLOBALS['logs'][] = $message; }
function startMeasure($name) {}
function endMeasure($name, $save = false) {}
function check($condition, $message)
{
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($message);
}
function moduleFixture($name, $source)
{
    $directory = $GLOBALS['fixture'] . '/modules/' . $name;
    mkdir($directory);
    file_put_contents($directory . '/' . $name . '.class.php', '<?php ' . $source);
}
function setLinks($links)
{
    $GLOBALS['pdo']->prepare('UPDATE pvalues SET LINKED_MODULES=? WHERE ID=1')->execute(array($links));
}
function links() { return $GLOBALS['pdo']->query('SELECT LINKED_MODULES FROM pvalues WHERE ID=1')->fetchColumn(); }
function runModule($module, $attempt = 1, $params = null)
{
    global $pdo, $worker;
    $pdo->exec('DELETE FROM operations_queue');
    $params ??= array('object' => 'Lamp', 'property' => 'status', 'value' => 'private value');
    $id = Core::queue()->enqueue('core_runtime', 'modulepropertyset', json_encode(array('target' => $module, 'path' => '/api/modulePropertySet/' . $module, 'params' => $params)), false, 3600);
    $pdo->exec('UPDATE operations_queue SET ATTEMPTS=' . ($attempt - 1) . ' WHERE ID=' . $id);
    $result = null;
    $worker->process(static function ($item) use ($worker, &$result) { return $result = $worker->execute($item['QUEUE_ID'], $item['CLAIM_TOKEN']); });
    return $result;
}

moduleFixture('badsubscriber', 'class badsubscriber { public function __construct() { throw new RuntimeException("Must not construct a module without a handler"); } }');
moduleFixture('goodsubscriber', 'class goodsubscriber { public function propertySetHandle($object,$property,$value) { $GLOBALS["calls"][]=array($object,$property,$value); } }');
moduleFixture('falsesubscriber', 'class falsesubscriber { public function propertySetHandle($object,$property,$value) { return false; } }');
moduleFixture('throwsubscriber', 'class throwsubscriber { public function propertySetHandle($object,$property,$value) { throw new RuntimeException("Broker is unavailable"); } }');
moduleFixture('constructorfailure', 'class constructorfailure { public function __construct() { throw new RuntimeException("Initialization failed"); } public function propertySetHandle($object,$property,$value) {} }');
moduleFixture('brokenmodule', 'class brokenmodule {');
moduleFixture('wrongclass', 'class unrelated_test_class {}');
moduleFixture('inheritedsubscriber', 'require_once dirname(__DIR__)."/goodsubscriber/goodsubscriber.class.php"; class inheritedsubscriber extends goodsubscriber {}');

setLinks('badsubscriber,goodsubscriber, badsubscriber ,badsubscriber_extra');
$result = runModule('badsubscriber');
check($result['success'] && links() === 'goodsubscriber,badsubscriber_extra', 'Remove only the exact stale module, including duplicate/whitespace tokens');
check((int)$pdo->query('SELECT COUNT(*) FROM operations_queue')->fetchColumn() === 0, 'Cleaned operation must be acknowledged immediately');
check((int)$pdo->query('SELECT COUNT(*) FROM operations_failed')->fetchColumn() === 0, 'Cleaned operation must not enter the failed archive');
$value = $pdo->query('SELECT VALUE,UPDATED FROM pvalues WHERE ID=1')->fetch(PDO::FETCH_ASSOC);
check($value === array('VALUE' => 'current value', 'UPDATED' => '2026-10-21 19:00:00'), 'Cleanup must preserve value and update time');
check($pdo->query('SELECT LINKED_MODULES FROM pvalues WHERE ID=2')->fetchColumn() === 'badsubscriber'
    && $pdo->query('SELECT LINKED_MODULES FROM pvalues WHERE ID=3')->fetchColumn() === 'badsubscriber', 'Other objects and properties must retain their bindings');
$cleanupLog = implode("\n", $logs);
check(count($logs) === 2 && str_contains($cleanupLog, 'badsubscriber') && str_contains($cleanupLog, 'Lamp')
    && str_contains($cleanupLog, 'status') && !str_contains($cleanupLog, 'private value'),
    'Cleanup logs must identify the binding without exposing the value');
runModule('badsubscriber');
check(count($logs) === 2, 'Already removed subscriber must not cause repeated cleanup logs');

setLinks('goodsubscriber,inheritedsubscriber');
$before = count($calls);
check(runModule('goodsubscriber')['success'] && runModule('inheritedsubscriber')['success'] && count($calls) === $before + 2, 'Existing and inherited handlers must still execute');
check(links() === 'goodsubscriber,inheritedsubscriber', 'Working subscribers must remain bound');

foreach (array('falsesubscriber', 'throwsubscriber', 'constructorfailure', 'brokenmodule', 'wrongclass') as $module) {
    setLinks($module . ',goodsubscriber');
    $result = runModule($module);
    check(!$result['success'] && links() === $module . ',goodsubscriber', 'Execution or load failure must not unlink ' . $module);
    check((int)$pdo->query('SELECT COUNT(*) FROM operations_queue')->fetchColumn() === 1, 'Transient failure must stay queued for ' . $module);
}
check(str_contains($result['error'], 'wrongclass') && str_contains($result['error'], 'Lamp') && str_contains($result['error'], 'status'), 'Errors must identify target module and property');

setLinks('missingsubscriber,goodsubscriber');
check(!runModule('missingsubscriber', 1)['success'] && str_contains(links(), 'missingsubscriber'), 'First missing file must allow recovery during deployment');
check(!runModule('missingsubscriber', 2)['success'] && str_contains(links(), 'missingsubscriber'), 'Second missing file must still retain the binding');
check(runModule('missingsubscriber', 3)['success'] && links() === 'goodsubscriber', 'Confirmed missing module must be removed on the third attempt');
setLinks('restoredsubscriber,goodsubscriber');
check(!runModule('restoredsubscriber', 1)['success'], 'Temporarily missing module must retry');
moduleFixture('restoredsubscriber', 'class restoredsubscriber extends goodsubscriber {}');
check(runModule('restoredsubscriber', 2)['success'] && links() === 'restoredsubscriber,goodsubscriber', 'Restored module must execute and retain its binding');

setLinks('badsubscriber,goodsubscriber');
$connection->beforeRemoval = static function () use ($connection, $pdo) {
    $connection->beforeRemoval = null;
    $pdo->exec("UPDATE pvalues SET LINKED_MODULES='badsubscriber,goodsubscriber,newsubscriber',VALUE='concurrent value' WHERE ID=1");
};
check(runModule('badsubscriber')['success'] && links() === 'goodsubscriber,newsubscriber', 'Concurrent subscriber addition must survive compare-and-swap cleanup');
check($pdo->query('SELECT VALUE FROM pvalues WHERE ID=1')->fetchColumn() === 'concurrent value', 'Concurrent value update must survive cleanup');
setLinks('badsubscriber,goodsubscriber');
$connection->beforeRemoval = static function () use ($pdo) {
    $pdo->exec("UPDATE pvalues SET LINKED_MODULES=LINKED_MODULES||',concurrent' WHERE ID=1");
};
$result = runModule('badsubscriber');
$connection->beforeRemoval = null;
check(!$result['success'] && str_contains(links(), 'badsubscriber') && str_contains($result['error'], 'concurrently'), 'Repeated concurrent changes must retry without overwriting any subscriber');
$connection->failRemoval = true;
check(!runModule('badsubscriber')['success'] && str_contains(links(), 'badsubscriber'), 'SQL cleanup failure must not acknowledge the operation');
$connection->failRemoval = false;
check(runModule('badsubscriber')['success'] && !str_contains(links(), 'badsubscriber'), 'Failed cleanup must succeed on retry');

$before = (int)$pdo->query('SELECT COUNT(*) FROM pvalues')->fetchColumn();
check(!Core::properties()->removeLinkedModule('UnknownObject', 'status', 'badsubscriber')
    && !Core::properties()->removeLinkedModule('Lamp', 'UnknownProperty', 'badsubscriber'), 'Cleanup must not create missing objects or property values');
check((int)$pdo->query('SELECT COUNT(*) FROM pvalues')->fetchColumn() === $before, 'Missing binding must not insert new rows');
$pdo->exec("INSERT INTO objects VALUES (3,'InheritedLamp',2);
    INSERT INTO classes VALUES (1,0),(2,1);
    INSERT INTO properties(ID,TITLE,OBJECT_ID,CLASS_ID) VALUES (3,'status',0,1),(4,'status',0,2);
    INSERT INTO pvalues(ID,OBJECT_ID,PROPERTY_ID,VALUE,LINKED_MODULES) VALUES
        (4,3,3,'obsolete inherited value','badsubscriber'),(5,3,4,'active inherited value','badsubscriber,goodsubscriber')");
check(runModule('badsubscriber', 1, array('object' => 'InheritedLamp', 'property' => 'status', 'value' => 'private value'))['success']
    && $pdo->query('SELECT LINKED_MODULES FROM pvalues WHERE ID=5')->fetchColumn() === 'goodsubscriber'
    && $pdo->query('SELECT LINKED_MODULES FROM pvalues WHERE ID=4')->fetchColumn() === 'badsubscriber', 'Cleanup must resolve the active inherited property instead of an obsolete same-name row');
setLinks('badsubscriber,goodsubscriber');
check(!runModule('../badsubscriber')['success'] && links() === 'badsubscriber,goodsubscriber', 'Invalid module path must not modify bindings');
check(!runModule('badsubscriber', 1, array('object' => 'Lamp', 'value' => 'private value'))['success'] && links() === 'badsubscriber,goodsubscriber', 'Invalid operation parameters must not delete any link');

// Missing files must also be queued by ordinary property writes, so they can self-clean.
setLinks('missingsubscriber');
$pdo->exec('DELETE FROM operations_queue');
$object = (object)array('id' => 1, 'class_id' => 0, 'object_title' => 'Lamp');
Core::properties()->set($object, 'status', 'new sensor value');
$queued = $pdo->query('SELECT DATAVALUE FROM operations_queue')->fetchColumn();
check($queued && json_decode($queued, true)['target'] === 'missingsubscriber', 'Property write must queue a missing subscriber for validation and cleanup');
runModule('missingsubscriber', 3);
$pdo->exec('DELETE FROM operations_queue');
Core::properties()->set($object, 'status', 'next sensor value');
check((int)$pdo->query('SELECT COUNT(*) FROM operations_queue')->fetchColumn() === 0, 'Subsequent property writes must stop queuing the removed subscriber');

// --- subscription registration must be surgical and concurrency-safe ---
require_once dirname(__DIR__) . '/lib/objects.class.php';
$bindings = Core::bindings();
$connection->beforeRemoval = null;
setLinks('goodsubscriber');
$pdo->exec("UPDATE pvalues SET VALUE='current value',UPDATED='2026-10-21 19:00:00',SOURCE='zigbee:test' WHERE ID=1");
check($bindings->attach('Lamp', 'status', 'newsubscriber') === 1 && links() === 'goodsubscriber,newsubscriber',
    'Attach appends a subscriber');
check($pdo->query('SELECT VALUE,UPDATED,SOURCE FROM pvalues WHERE ID=1')->fetch(PDO::FETCH_ASSOC)
    === array('VALUE' => 'current value', 'UPDATED' => '2026-10-21 19:00:00', 'SOURCE' => 'zigbee:test'),
    'Attach must not rewrite VALUE, UPDATED or SOURCE');

setLinks('goodsubscriber');
$connection->beforeRemoval = static function () use ($connection, $pdo) {
    $connection->beforeRemoval = null;
    $pdo->exec("UPDATE pvalues SET LINKED_MODULES='goodsubscriber,concurrent' WHERE ID=1");
};
check($bindings->attach('Lamp', 'status', 'retried') === 1 && links() === 'goodsubscriber,concurrent,retried',
    'Concurrent subscriber addition during attach must survive retry');
$connection->beforeRemoval = null;

setLinks('goodsubscriber');
$invalidModule = false;
try { $bindings->attach('Lamp', 'status', '../bad'); } catch (InvalidArgumentException $e) { $invalidModule = true; }
check($invalidModule && links() === 'goodsubscriber', 'Invalid module name must not touch bindings');

setLinks(' a , b ,, a ');
$bindings->attach('Lamp', 'status', 'c');
check(links() === 'a,b,c', 'Attach normalizes whitespace and duplicate tokens');

setLinks(str_repeat('m', 250));
$tooLong = false;
try { $bindings->attach('Lamp', 'status', 'other'); } catch (RuntimeException $e) { $tooLong = true; }
check($tooLong && links() === str_repeat('m', 250), 'Overlong subscription list must fail before it is truncated');

setLinks('a,b,a,c');
check($bindings->detach('Lamp', 'status', 'a') && links() === 'b,c', 'Detach removes every exact token and keeps other subscribers');
check(!$bindings->detach('Lamp', 'status', 'missing') && links() === 'b,c', 'Detaching an absent module is a no-op');

$pdo->exec("CREATE TABLE mqtt (ID INTEGER PRIMARY KEY, LINKED_OBJECT TEXT, LINKED_PROPERTY TEXT)");
$pdo->exec("INSERT INTO mqtt VALUES (1,'Lamp','status')");
setLinks('mqtt,other');
check(!$bindings->detachIfUnused('mqtt', 'Lamp', 'status', 'mqtt') && links() === 'mqtt,other',
    'Conditional detach keeps a module used by another record');
$pdo->exec('DELETE FROM mqtt');
check($bindings->detachIfUnused('mqtt', 'Lamp', 'status', 'mqtt') && links() === 'other',
    'Conditional detach removes the module after the last owner record is gone');
$invalidTable = false;
try { $bindings->detachIfUnused('mqtt;DROP', 'Lamp', 'status', 'mqtt'); } catch (InvalidArgumentException $e) { $invalidTable = true; }
check($invalidTable, 'Owner table name must be an identifier');

// Obsolete inherited row must not receive the subscription.
$pdo->exec("UPDATE pvalues SET PROPERTY_NAME='InheritedLamp.status',LINKED_MODULES='oldsub' WHERE ID=4");
$pdo->exec("UPDATE pvalues SET PROPERTY_NAME='',LINKED_MODULES='oldsub' WHERE ID=5");
check($bindings->attach('InheritedLamp', 'status', 'newsub') === 5
    && $pdo->query('SELECT LINKED_MODULES FROM pvalues WHERE ID=5')->fetchColumn() === 'oldsub,newsub'
    && $pdo->query('SELECT LINKED_MODULES FROM pvalues WHERE ID=4')->fetchColumn() === 'oldsub',
    'Attach must use the active inherited property, not an obsolete same-name row');
check(Core::properties()->get((object)array('id' => 3, 'class_id' => 2, 'object_title' => 'InheritedLamp'), 'status')
    === 'active inherited value',
    'Property read must use the active inherited row, not an obsolete same-name row');

$pdo->exec("INSERT INTO properties(ID,TITLE,OBJECT_ID,CLASS_ID) VALUES (5,'level',1,0)");
$created = $bindings->attach('Lamp', 'level', 'newsub');
$createdRow = $pdo->query('SELECT VALUE,LINKED_MODULES FROM pvalues WHERE ID=' . (int)$created)->fetch(PDO::FETCH_ASSOC);
check($created > 0 && $createdRow === array('VALUE' => '', 'LINKED_MODULES' => 'newsub'),
    'Attach must create the missing value row for an existing property');

// Moving an object property into a class property must keep bindings and history.
$pdo->exec("CREATE TABLE phistory (ID INTEGER PRIMARY KEY AUTOINCREMENT, VALUE_ID INTEGER, ADDED TEXT, VALUE TEXT, SOURCE TEXT)");
$pdo->exec("INSERT INTO properties(ID,TITLE,OBJECT_ID,CLASS_ID) VALUES (20,'mode',1,0),(21,'mode',0,1)");
$pdo->exec("INSERT INTO pvalues(ID,OBJECT_ID,PROPERTY_ID,PROPERTY_NAME,VALUE,UPDATED,SOURCE,LINKED_MODULES) VALUES
    (20,1,20,'Lamp.mode','object value','2026-10-21 20:00:00','object','mqtt,scripts'),
    (21,1,21,'Lamp.mode','class value','2026-10-21 19:00:00','class','zigbee')");
$pdo->exec("INSERT INTO phistory(VALUE_ID,ADDED,VALUE,SOURCE) VALUES (20,'2026-10-21 20:00:00','object value','object')");
$classPvalue = $pdo->query('SELECT * FROM pvalues WHERE ID=21')->fetch(PDO::FETCH_ASSOC);
$objectPvalue = $pdo->query('SELECT * FROM pvalues WHERE ID=20')->fetch(PDO::FETCH_ASSOC);
Core::migrations()->mergeRows($classPvalue, array($objectPvalue));
$merged = $pdo->query('SELECT VALUE,LINKED_MODULES FROM pvalues WHERE ID=21')->fetch(PDO::FETCH_ASSOC);
check((int)$pdo->query('SELECT COUNT(*) FROM pvalues WHERE ID=20')->fetchColumn() === 0
    && $merged === array('VALUE' => 'object value', 'LINKED_MODULES' => 'zigbee,mqtt,scripts'),
    'Property merge keeps the newest value and unions module bindings');
check((int)$pdo->query('SELECT COUNT(*) FROM phistory WHERE VALUE_ID=21')->fetchColumn() === 1,
    'Property merge moves history instead of deleting it');

// A rename must update both the label and every LINKED_OBJECT owner row.
setLinks('goodsubscriber');
$pdo->exec("INSERT INTO mqtt VALUES (2,'Lamp','status')");
Core::objects()->renameReferences(1, 'Lamp', 'Lamp2');
check($pdo->query('SELECT PROPERTY_NAME FROM pvalues WHERE ID=1')->fetchColumn() === 'Lamp2.status'
    && $pdo->query('SELECT LINKED_OBJECT FROM mqtt WHERE ID=2')->fetchColumn() === 'Lamp2'
    && links() === 'goodsubscriber',
    'Object rename keeps module owner rows and pvalue labels in sync');

$pdo->exec("UPDATE objects SET TITLE='Lamp2' WHERE ID=1");
Core::properties()->renameReferences(1, 'status', 'state');
check($pdo->query('SELECT PROPERTY_NAME FROM pvalues WHERE ID=1')->fetchColumn() === 'Lamp2.state'
    && $pdo->query('SELECT LINKED_PROPERTY FROM mqtt WHERE ID=2')->fetchColumn() === 'state'
    && links() === 'goodsubscriber',
    'Property rename keeps module owner rows and pvalue labels in sync');

$before = (int)$pdo->query('SELECT COUNT(*) FROM pvalues')->fetchColumn();
check($bindings->attach('UnknownObject', 'status', 'newsub') === 0
    && $bindings->attach('Lamp', 'unknown', 'newsub') === 0
    && (int)$pdo->query('SELECT COUNT(*) FROM pvalues')->fetchColumn() === $before,
    'Attach must not create objects/properties for an unknown pair');

require_once dirname(__DIR__) . '/lib/objects.class.php';
$pdo->exec("UPDATE pvalues SET VALUE='next sensor value' WHERE ID=1");
setLinks('badsubscriber,goodsubscriber');
removeLinkedProperty('Lamp2', 'status', 'badsubscriber');
check(links() === 'goodsubscriber' && $pdo->query('SELECT VALUE FROM pvalues WHERE ID=1')->fetchColumn() === 'next sensor value', 'Legacy unlink must use the same selective cleanup without overwriting values');
setLinks('goodsubscriber');
check(addLinkedProperty('Lamp2', 'status', 'legacymodule') === 1 && links() === 'goodsubscriber,legacymodule',
    'Legacy addLinkedProperty must use the atomic implementation');

echo 'Stale module binding smoke tests passed: ' . $checks . ' checks (SQLite :memory:, no external services).' . PHP_EOL;
