<?php
/** Isolated API dependencies. Never loaded by an application entry point. */

use MajorDoMo\Core\{Config, Core, Database, MetadataCache, RedisConnection, Methods, CodeExecutor, PropertyAudit};
use MajorDoMo\Core\Api\{Schema, TokenStore};

final class ApiFixtureConnection
{
    public int $affected = 0;
    public function __construct(public PDO $dbh) {}
    public function DbSafe1($value): string { return str_replace("'", "''", (string)$value); }
    public function DbSafe($value): string { return $this->DbSafe1($value); }
    public function Select(string $sql): array { return $this->dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function SelectOne(string $sql): array
    {
        if (stripos($sql, 'information_schema.TABLES') !== false) {
            preg_match("/TABLE_NAME='([^']+)'/", $sql, $match);
            $sql = "SELECT name AS TABLE_NAME FROM sqlite_master WHERE type='table' AND name='" . $this->DbSafe1($match[1] ?? '') . "'";
        }
        return $this->dbh->query($sql)->fetch(PDO::FETCH_ASSOC) ?: array();
    }
    public function Exec(string $sql, bool $ignore = false): bool
    {
        if ($sql === 'START TRANSACTION') $sql = 'BEGIN TRANSACTION';
        try { $this->affected = $this->dbh->exec($sql); return true; }
        catch (Throwable $error) { if (!$ignore) throw $error; return false; }
    }
    public function Insert(string $table, array &$row): int
    {
        $keys = array_keys($row);
        $statement = $this->dbh->prepare('INSERT INTO ' . $table . ' (' . implode(',', $keys) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')');
        $statement->execute(array_values($row));
        return (int)$this->dbh->lastInsertId();
    }
    public function Update(string $table, array $row, string $key = 'ID'): bool
    {
        $id = $row[$key]; unset($row[$key]);
        $statement = $this->dbh->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(static fn($field) => $field . '=?', array_keys($row))) . ' WHERE ' . $key . '=?');
        return $statement->execute(array_merge(array_values($row), array($id)));
    }
    public function AffectedRows(): int { return $this->affected; }
    public function getLastError(): array { return array('message' => 'fixture failure', 'code' => 1); }
}

final class ApiFixture
{
    public static function initialize(string $root, string $project): void
    {
        foreach (array('lib', 'cms/modules_installed', 'modules/objects', 'modules/fixtureapi') as $directory) if (!is_dir($root . '/' . $directory)) mkdir($root . '/' . $directory, 0777, true);
        file_put_contents($root . '/.env', "APP_ENV=testing\nDB_HOST=\nDB_NAME=\nUSE_REDIS=false\nROOTHTML=/\nHOME_NETWORK=192.168.55.0/24\nTRUSTED_PROXIES=127.0.0.2/32\n");
        file_put_contents($root . '/config.php', '<?php \\MajorDoMo\\Core\\Config::boot(__DIR__);');
        file_put_contents($root . '/lib/perfmonitor.class.php', '<?php');
        file_put_contents($root . '/lib/loader.php', '<?php \\ApiFixture::boot(dirname(__DIR__));');
        file_put_contents($root . '/load_settings.php', '<?php');
        file_put_contents($root . '/modules/objects/objects.class.php', '<?php // objects stub is defined by test runtime');
        file_put_contents($root . '/modules/fixtureapi/fixtureapi.class.php', <<<'PHP'
<?php
class fixtureapi {
    public function api($params) {
        if (($params['mode'] ?? '') === 'throw') throw new RuntimeException('private implementation detail');
        if (($params['mode'] ?? '') === 'exit') { echo '<h1>legacy exit</h1>'; exit; }
        if (($params['mode'] ?? '') === 'echo') echo '<p>legacy debug output</p>';
        if (($params['mode'] ?? '') === 'resource') return fopen('php://memory', 'r');
        return array('params' => $params, 'get' => $_GET, 'post' => $_POST, 'method' => $_SERVER['REQUEST_METHOD']);
    }
    public function propertySetHandle($object, $property, $value) { return array($object, $property, $value); }
}
PHP);
        file_put_contents($root . '/cms/modules_installed/control_modules.installed', 'fixture');
        $pdo = self::pdo($root);
        $pdo->exec(Schema::sql(true));
        $pdo->exec("CREATE TABLE IF NOT EXISTS locations (ID INTEGER PRIMARY KEY AUTOINCREMENT,TITLE TEXT NOT NULL,PRIORITY INTEGER DEFAULT 0);
            CREATE TABLE IF NOT EXISTS objects (ID INTEGER PRIMARY KEY,TITLE TEXT,CLASS_ID INTEGER,LOCATION_ID INTEGER DEFAULT 0);
            CREATE TABLE IF NOT EXISTS classes (ID INTEGER PRIMARY KEY,TITLE TEXT,PARENT_ID INTEGER DEFAULT 0);
            CREATE TABLE IF NOT EXISTS properties (ID INTEGER PRIMARY KEY,TITLE TEXT,CLASS_ID INTEGER,OBJECT_ID INTEGER DEFAULT 0);
            CREATE TABLE IF NOT EXISTS methods (ID INTEGER PRIMARY KEY,TITLE TEXT,CLASS_ID INTEGER,OBJECT_ID INTEGER DEFAULT 0,CODE TEXT,CALL_PARENT INTEGER DEFAULT 0,SCRIPT_ID INTEGER DEFAULT 0,EXECUTED TEXT,EXECUTED_SRC TEXT,EXECUTED_PARAMS TEXT);
            CREATE TABLE IF NOT EXISTS scripts (ID INTEGER PRIMARY KEY,TITLE TEXT);
            CREATE TABLE IF NOT EXISTS users (ID INTEGER PRIMARY KEY,NAME TEXT,USERNAME TEXT);
            CREATE TABLE IF NOT EXISTS shouts (ID INTEGER PRIMARY KEY AUTOINCREMENT,MEMBER_ID INTEGER,ADDED TEXT,MESSAGE TEXT);
            CREATE TABLE IF NOT EXISTS fixture_values (NAME TEXT PRIMARY KEY,VALUE TEXT);
            CREATE TABLE IF NOT EXISTS operations_queue (ID INTEGER PRIMARY KEY AUTOINCREMENT,TOPIC TEXT,DATANAME TEXT,DATAVALUE TEXT,EXPIRE TEXT,CLAIM_TOKEN TEXT DEFAULT '',CLAIM_UNTIL TEXT,ATTEMPTS INTEGER DEFAULT 0);
            CREATE TABLE IF NOT EXISTS property_audit_runs (ID INTEGER PRIMARY KEY AUTOINCREMENT,TRACE_ID TEXT UNIQUE,STARTED TEXT,LAST_SEEN TEXT,FINISHED TEXT,STATUS TEXT,TRIGGER_TYPE TEXT,TRIGGER_TITLE TEXT,SOURCE TEXT,ACTOR TEXT,IP TEXT,REQUEST_URI TEXT,PARAMS TEXT,RESULT TEXT,ERROR TEXT,CHAIN TEXT,EVENTS_COUNT INTEGER DEFAULT 0);
            CREATE TABLE IF NOT EXISTS property_audit_events (ID INTEGER PRIMARY KEY AUTOINCREMENT,RUN_ID INTEGER,TRACE_ID TEXT,ADDED TEXT,FINISHED TEXT,DURATION_MS INTEGER,TYPE TEXT,STATUS TEXT,TITLE TEXT,SUBTITLE TEXT,OBJECT_ID INTEGER,PROPERTY_ID INTEGER,PROPERTY_NAME TEXT,METHOD_ID INTEGER,CLASS_ID INTEGER,SCRIPT_ID INTEGER,MODULE TEXT,OLD_VALUE TEXT,NEW_VALUE TEXT,SOURCE TEXT,PARAMS TEXT,OUT_PARAMS TEXT,RESULT TEXT,ERROR TEXT,CHAIN TEXT);
            INSERT OR IGNORE INTO classes VALUES (1,'Fixture',0);
            INSERT OR IGNORE INTO objects VALUES (1,'Lamp',1,0);
            INSERT OR IGNORE INTO properties VALUES (1,'level',1,0);
            INSERT OR IGNORE INTO scripts VALUES (1,'Test script');
            INSERT OR IGNORE INTO methods (ID,TITLE,CLASS_ID,CODE) VALUES (1,'run',1,'return ' || char(36) || 'params[\"value\"] ?? 7;');
            INSERT OR IGNORE INTO users VALUES (1,'Tester','test');
            INSERT OR IGNORE INTO locations VALUES (1,'Кухня',10);
            INSERT OR IGNORE INTO locations VALUES (2,'Спальня',20);
            INSERT OR IGNORE INTO fixture_values VALUES ('Lamp.level','0');");
    }

    public static function pdo(string $root): PDO
    {
        $pdo = new PDO('sqlite:' . $root . '/fixture.sqlite', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $pdo->sqliteCreateFunction('GET_LOCK', static fn() => 1);
        $pdo->sqliteCreateFunction('RELEASE_LOCK', static fn() => 1);
        $pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
        return $pdo;
    }

    public static function boot(string $root): void
    {
        Config::boot($root);
        $pdo = self::pdo($root);
        if (Config::string('APP_ENV') !== 'testing' || Config::string('DB_HOST') !== '' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Fixture isolation failed');
        $GLOBALS['db'] = new ApiFixtureConnection($pdo);
        $db = new Database($GLOBALS['db']);
        (new ReflectionProperty(Core::class, 'services'))->setValue(null, array(
            'database' => $db, 'apiTokens' => new TokenStore($pdo),
            'methods' => new Methods($db, new MetadataCache($db, new RedisConnection()), new CodeExecutor()),
            'audit' => new PropertyAudit($db),
        ));
        PropertyAudit::leave();
    }

    public static function remove(string $root): void
    {
        // Only our exact random fixture tree, never a workspace or inferred root.
        if (!preg_match('/^mjd-(?:api|auth-http)-[a-f0-9]+$/D', basename($root)) || realpath(dirname($root)) !== realpath(sys_get_temp_dir())) throw new RuntimeException('Unsafe fixture cleanup');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
        }
        rmdir($root);
    }
}

class objects
{
    public int $id; public int $class_id; public string $object_title; public string $description = ''; public string $class_title = 'Fixture'; public int $location_id = 0;
    public function loadObjectRecord(array $row): void { $this->id = (int)$row['ID']; $this->class_id = (int)$row['CLASS_ID']; $this->object_title = $row['TITLE']; }
    public function getProperty(string $name): mixed { return getGlobal($this->object_title . '.' . $name); }
}
function getGlobal($name) { $row = $GLOBALS['db']->SelectOne("SELECT VALUE FROM fixture_values WHERE NAME='" . $GLOBALS['db']->DbSafe1($name) . "'"); return isset($row['VALUE']) ? json_decode($row['VALUE'], true, 64, JSON_THROW_ON_ERROR) : null; }
function setGlobal($name, $value): void { $statement = $GLOBALS['db']->dbh->prepare('REPLACE INTO fixture_values (NAME,VALUE) VALUES (?,?)'); $statement->execute(array($name, json_encode($value, JSON_THROW_ON_ERROR))); }
function getClassProperties($class): array { return $GLOBALS['db']->Select("SELECT TITLE FROM properties WHERE CLASS_ID=1 AND OBJECT_ID=0"); }
function getObjectsByClass($class): array { return $GLOBALS['db']->Select('SELECT ID,TITLE FROM objects WHERE CLASS_ID=1'); }
function getRoomObjectByLocation($id, $auto = 0): string { return 'Room' . $id; }
function processTitle($value) { return $value; }
function registerEvent($name, $params): int { $GLOBALS['fixture_event'] = array($name, $params); return 41; }
function runScript($id, $params) { return array('id' => $id, 'params' => $params); }
function getHistory($name, $period) { return array(array('VALUE' => getGlobal($name), 'ADDED' => '2026-10-22 10:00:00')); }
function getHistoryMax($name, $period) { return 100; }
function getHistoryMin($name, $period) { return 0; }
function getHistoryCount($name, $period) { return 1; }
function getHistorySum($name, $period) { return 100; }
function getHistoryAvg($name, $period) { return 50; }
function say($message, $level, $user, $source): void { $row = array('MEMBER_ID' => $user, 'ADDED' => date('Y-m-d H:i:s'), 'MESSAGE' => $message); $GLOBALS['db']->Insert('shouts', $row); }
function startMeasure($name): void {}
function endMeasure($name, $save = 0): void {}
function verbose_log($message): void {}
function setEvalCode($code = ''): void {}
function registerError($type, $message): void { throw new RuntimeException('Fixture method error'); }
function DebMes($message, $source = '', $level = ''): void { $GLOBALS['fixture_logs'][] = $message; }
