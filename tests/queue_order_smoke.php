<?php
// 2026-10-25 — порядок MQTT и последовательного core после отказа: SQLite и отдельный тестовый Redis.

putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('USE_REDIS=false');
require __DIR__ . '/RedisTestClient.php';
require dirname(__DIR__) . '/lib/core.class.php';
use MajorDoMo\Core\Config;
use MajorDoMo\Core\OperationsQueue;
use MajorDoMo\Core\Database;
use MajorDoMo\Core\RedisConnection;

$fixture = sys_get_temp_dir() . '/mjd-order-' . bin2hex(random_bytes(6));
Config::boot($fixture);
register_shutdown_function(static function () use ($fixture) {
    unlink($fixture . '/cms/cached/config.php'); rmdir($fixture . '/cms/cached'); rmdir($fixture . '/cms'); rmdir($fixture);
});
$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Isolation failed');
$pdo->exec('CREATE TABLE operations_queue (ID INTEGER PRIMARY KEY AUTOINCREMENT, TOPIC TEXT, DATANAME TEXT, DATAVALUE TEXT,
    EXPIRE TEXT, CLAIM_TOKEN TEXT DEFAULT "", CLAIM_UNTIL TEXT, ATTEMPTS INTEGER DEFAULT 0)');
$pdo->sqliteCreateFunction('GET_LOCK', fn(...$args) => 1);
$pdo->sqliteCreateFunction('RELEASE_LOCK', fn(...$args) => 1);
$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
$adapter = new class($pdo) {
    public function __construct(private PDO $pdo) {}
    public function DbSafe1($value) { return str_replace("'", "''", (string)$value); }
    public function Select($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function SelectOne($sql) { return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: array(); }
    public function Exec($sql, $ignore = false) {
        if (preg_match('/^DELETE FROM operations_queue WHERE (.+) LIMIT (\d+)$/s', $sql, $match)) {
            $sql = 'DELETE FROM operations_queue WHERE ID IN (SELECT ID FROM operations_queue WHERE ' . $match[1] . ' LIMIT ' . $match[2] . ')';
        }
        $this->pdo->exec($sql); return true;
    }
    public function Insert($table, &$row) {
        $this->pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
        return (int)$this->pdo->lastInsertId();
    }
};
$checks = 0;
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
$port = (int)getenv('MJD_TEST_REDIS_PORT');
foreach ($port > 0 ? array(false, true) : array(false) as $redisMode) {
    $values = (new ReflectionProperty(Config::class, 'values'))->getValue();
    $values['USE_REDIS'] = $redisMode ? '127.0.0.1' : false;
    $values['REDIS_PORT'] = $port; $values['REDIS_DB'] = 15; $values['REDIS_PREFIX'] = 'mjdt:order:' . bin2hex(random_bytes(6)) . ':';
    (new ReflectionProperty(Config::class, 'values'))->setValue(null, $values);
    $present = (new ReflectionProperty(Config::class, 'present'))->getValue();
    foreach (array('USE_REDIS','REDIS_PORT','REDIS_DB','REDIS_PREFIX') as $key) $present[$key] = true;
    (new ReflectionProperty(Config::class, 'present'))->setValue(null, $present);
    $queue = new OperationsQueue(new Database($adapter), new RedisConnection());
    $queue->enqueue('mqtt_queue', 'lamp/set', 'ON', false, 60);
    $queue->enqueue('mqtt_queue', 'lamp/set', 'OFF', false, 60);
    $on = $queue->claim('mqtt_queue', 1, 10)[0];
    check($on['DATAVALUE'] === 'ON', 'Первая команда ON');
    $queue->release($on, 1);
    $queue->enqueue('mqtt_queue', 'other/set', 'OTHER', false, 60);
    $other = $queue->claim('mqtt_queue', 10, 10);
    check(count($other) === 1 && $other[0]['DATAVALUE'] === 'OTHER', 'Другой топик проходит, OFF не обгоняет ON');
    $queue->acknowledge($other[0]);
    usleep(1100000);
    $retry = $queue->claim('mqtt_queue', 1, 10)[0];
    check($retry['DATAVALUE'] === 'ON', 'После задержки повторяется ON');
    check($queue->claim('mqtt_queue', 1, 10) === array(), 'Второй издатель не отправляет OFF до подтверждения ON');
    $queue->acknowledge($retry);
    $off = $queue->claim('mqtt_queue', 1, 10)[0];
    check($off['DATAVALUE'] === 'OFF', 'Завершающая команда OFF');
    $queue->acknowledge($off);
    $queue->enqueue('core_runtime','method','FIRST',false,60);
    $queue->enqueue('core_runtime','script','SECOND',false,60);
    $first = $queue->claim('core_runtime',1,10)[0];
    check($queue->claim('core_runtime',1,10) === array(), 'Второй исполнитель не обгоняет ещё выполняемое задание последовательного core');
    $queue->release($first,1);
    check($queue->claim('core_runtime',1,10) === array(), 'Последовательный core не обходит повтор');
    usleep(1100000);
    $first = $queue->claim('core_runtime',1,10)[0];
    check($first['DATAVALUE'] === 'FIRST', 'Первое задание повторено раньше второго');
    $queue->acknowledge($first);
    $second = $queue->claim('core_runtime',1,10)[0];
    check($second['DATAVALUE'] === 'SECOND', 'Порядок между типами core сохранён');
    $queue->acknowledge($second);
    check($queue->claim('core_runtime',1,10) === array(), 'Очередь завершилась');
}
echo 'Queue order smoke: ' . $checks . ' passed; SQLite' . ($port > 0 ? ' + isolated Redis' : '; Redis skipped') . ".\n";
