<?php
/** 2026-10-25 — обрыв процесса до/после COMMIT, восстановление, повторы и строгие подписчики без рабочих сервисов. */
require __DIR__ . '/property_delivery_smoke.php';
require __DIR__ . '/fixtures/runtime_database.php';
require dirname(__DIR__) . '/lib/core/SubscriptionDelivery.php';
require dirname(__DIR__) . '/lib/core/Helpers/Subscriptions.php';
require dirname(__DIR__) . '/lib/core/Events/EventBus.php';

use MajorDoMo\Core\{Core, Database, OperationsQueue, OperationWorker, ProcessRunner, RedisConnection, Properties};
use MajorDoMo\Core\Events\EventBus;

$initialChecks = $checks;
foreach (array('before_commit' => 77, 'after_commit' => 78) as $stage => $expectedExit) {
    $file = tempnam(sys_get_temp_dir(), 'mjd-durable-');
    if ($file === false) throw new RuntimeException('Не удалось создать тестовую БД');
    try {
        $child = <<<'PHP'
$GLOBALS['crashDb'] = $argv[2];
$source = file_get_contents($argv[1]);
$source = str_replace("'sqlite::memory:'", "'sqlite:' . \$GLOBALS['crashDb']", $source);
$source = str_replace('dirname(__DIR__)', 'getcwd()', $source);
$source = str_replace("__DIR__ . '/fixtures/runtime_queue.php'", "getcwd() . '/tests/fixtures/runtime_queue.php'", $source);
if ($argv[3] === 'before_commit') {
    $source = str_replace('return (int)$this->pdo->lastInsertId();', 'if ($table === "core_runtime_queue") exit(77); return (int)$this->pdo->lastInsertId();', $source);
} else {
    $source = str_replace('final class WebSocketQueue { public function post(...$args) {} }', 'final class WebSocketQueue { public function post(...$args) { exit(78); } }', $source);
}
eval('?>' . $source);
PHP;
        $process = proc_open(array(PHP_BINARY, '-r', $child, __DIR__ . '/property_delivery_smoke.php', $file, $stage),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, dirname(__DIR__));
        if (!is_resource($process)) throw new RuntimeException('Не удалось запустить тестовый процесс');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        check($exit === $expectedExit, 'Процесс прерван в точке ' . $stage . ': ' . $stderr . $stdout);
        $disk = new PDO('sqlite:' . $file, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $disk->sqliteCreateFunction('GET_LOCK', fn(...$args) => 1);
        $disk->sqliteCreateFunction('RELEASE_LOCK', fn(...$args) => 1);
        $disk->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
        check($disk->query('SELECT VALUE FROM pvalues WHERE ID=1')->fetchColumn() === ($stage === 'before_commit' ? '1' : '0'),
            'Значение соответствует границе COMMIT после гибели процесса');
        check((int)$disk->query('SELECT COUNT(*) FROM core_runtime_queue')->fetchColumn() === ($stage === 'before_commit' ? 0 : 2),
            'Задания и свойство переживают обрыв только вместе');
        if ($stage === 'after_commit') {
            $restored = new OperationsQueue(new Database(new RuntimeTestConnection($disk)), new RedisConnection());
            $handled = 0;
            while ($items = $restored->claimRuntime(1, 60)) {
                $restored->acknowledgeRuntime($items[0]); $handled++;
            }
            check($handled === 2 && (int)$disk->query('SELECT COUNT(*) FROM core_runtime_queue')->fetchColumn() === 0,
                'Новый процесс резервирует и подтверждает задания после перезапуска');
            unset($restored);
        }
        unset($disk);
    } finally { unlink($file); }
}

$adapter = new RuntimeTestConnection($pdo);
$db = new Database($adapter);
$queue = new OperationsQueue($db, new RedisConnection());
Core::$queue = $queue;
Core::$properties = new Properties($db, new MajorDoMo\Core\Cache(), new MajorDoMo\Core\MetadataCache(),
    new MajorDoMo\Core\PropertyLock(), new MajorDoMo\Core\WebSocketQueue(), $executor);
$worker = new OperationWorker($db, new RedisConnection(), $queue, new ProcessRunner());
$pdo->exec('DELETE FROM core_runtime_queue');

// Гибель контроллера без ACK не разрешает прежнему токену подтвердить новую аренду.
$queue->enqueueRuntime('method', array(), 'A.one');
$first = $queue->claimRuntime(1, 30)[0];
$pdo->exec("UPDATE core_runtime_queue SET CLAIM_UNTIL='2000-01-01 00:00:00'");
$next = $queue->claimRuntime(1, 30)[0];
check($next['QUEUE_ID'] === $first['QUEUE_ID'] && $next['CLAIM_TOKEN'] !== $first['CLAIM_TOKEN'], 'После аварии меняется владелец того же задания');
check(!$queue->acknowledgeRuntime($first), 'Запоздалый ACK не удаляет чужое задание');
$pdo->exec("UPDATE core_runtime_queue SET CLAIM_UNTIL='2000-01-01 00:00:00'");
$third = $queue->claimRuntime(1, 30)[0];
$pdo->exec("UPDATE core_runtime_queue SET CLAIM_UNTIL='2000-01-01 00:00:00'");
check($queue->claimRuntime(1, 30) === array(), 'Аварийный выход ограничен тремя попытками');
check($pdo->query('SELECT STATUS FROM core_runtime_queue')->fetchColumn() === 'failed', 'Прерванное задание сохраняется как отказ');
$pdo->exec('DELETE FROM core_runtime_queue');

$queue->enqueueRuntime('method', array(), 'A.fail');
$queue->enqueueRuntime('method', array(), 'A.next');
$queue->enqueueRuntime('method', array(), 'B.ok');
$handled = array();
$execute = static function (array $item) use (&$handled): array {
    $target = json_decode($item['DATAVALUE'], true)['target']; $handled[] = $target;
    if ($target === 'A.fail') throw new RuntimeException('fixture handler failure');
    return array('success' => true);
};
$worker->process($execute);
check($worker->process($execute) === 1 && end($handled) === 'B.ok', 'Повтор одного объекта не блокирует другой');
check($worker->process($execute) === 0, 'Следующее событие объекта не обгоняет отложенный повтор');
for ($attempt = 2; $attempt <= 3; $attempt++) {
    $pdo->exec("UPDATE core_runtime_queue SET AVAILABLE_AT='2000-01-01 00:00:00'");
    $worker->process($execute);
}
$worker->process($execute);
check($handled === array('A.fail','B.ok','A.fail','A.fail','A.next'), 'После трёх отказов обработка объекта продолжается по порядку');
check($pdo->query("SELECT STATUS FROM core_runtime_queue WHERE DATAVALUE LIKE '%A.fail%'")->fetchColumn() === 'failed', 'Исчерпанные повторы не удаляют диагностику');

$queue->enqueue('core_runtime', 'method', '{}', false, 0);
$queue->runtime()->maintain();
check((int)$pdo->query("SELECT COUNT(*) FROM core_runtime_queue WHERE STATUS='failed'")->fetchColumn() === 2,
    'Просроченное задание отмечается отказом вместо тихого удаления');
$pdo->exec('DELETE FROM core_runtime_queue');

// Сохранение внутри внешней транзакции не должно её преждевременно фиксировать.
resetDelivery('mqtt');
$db->requireExec('START TRANSACTION');
Core::$properties->set(Core::$objects['Sensor'], 'status', 0, array('mqtt' => 0));
check($adapter->inTransaction() && count(queued()) === 2, 'Запись свойства использует точку сохранения внешней транзакции');
$db->requireExec('ROLLBACK');
check($pdo->query('SELECT VALUE FROM pvalues WHERE ID=1')->fetchColumn() === '1' && queued() === array(),
    'Внешний ROLLBACK отменяет значение и задания');

$pdo->exec("CREATE TABLE system_events (ID INTEGER PRIMARY KEY AUTOINCREMENT,NAME TEXT,PAYLOAD TEXT,SOURCE TEXT,CREATED TEXT,PROCESSED INTEGER DEFAULT 0,PROCESSED_AT TEXT,ERROR TEXT)");
EventBus::reset(); EventBus::useDatabase($db); EventBus::disableMirroring();
define('SETTINGS_HOOK_EVENT_FAILURE', '{"fixturemodule":{}}');
$calls = 0;
$ingest = static function () use (&$calls): void { $calls++; throw new RuntimeException('fixture subscriber failure'); };
EventBus::emit('FAILURE');
check(EventBus::drain() === 1 && $calls === 0, 'Супервизор только передаёт событие в очередь, код подписчика не исполняется');
$worker->process(static fn($item) => $worker->execute($item['QUEUE_ID'], $item['CLAIM_TOKEN']));
check($calls === 1 && (int)$pdo->query('SELECT ATTEMPTS FROM core_runtime_queue')->fetchColumn() === 1,
    'Подписчик выполнен отдельным заданием');
check($pdo->query('SELECT STATUS FROM core_runtime_queue')->fetchColumn() === 'pending'
    && str_contains($pdo->query('SELECT LAST_ERROR FROM core_runtime_queue')->fetchColumn(), 'subscriber failure'),
    'Исключение подписчика не подтверждается как успешная обработка');
echo 'Runtime durability smoke: ' . ($checks - $initialChecks) . " passed; SQLite and isolated crash processes.\n";
