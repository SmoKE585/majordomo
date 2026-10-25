<?php
/** 2026-10-25 — настоящий Redis в отдельном контейнере; SQL остаётся SQLite в памяти. */
if ((int)getenv('MJD_TEST_REDIS_PORT') < 1) { echo "SKIP: set isolated MJD_TEST_REDIS_PORT\n"; exit; }
require __DIR__ . '/scheduler_smoke.php';
if (!class_exists('Redis')) require __DIR__ . '/RedisTestClient.php';

use MajorDoMo\Core\{Config, Core, Database, ProcessRunner, RedisConnection, Scheduler, SchedulerQueue};

$prefix = 'mjdt:scheduler:' . bin2hex(random_bytes(8)) . ':';
$values = (new ReflectionProperty(Config::class, 'values'))->getValue();
$values['USE_REDIS'] = '127.0.0.1';
$values['REDIS_PORT'] = (int)getenv('MJD_TEST_REDIS_PORT');
$values['REDIS_DB'] = 15;
$values['REDIS_PREFIX'] = $prefix;
(new ReflectionProperty(Config::class, 'values'))->setValue(null, $values);
$present = (new ReflectionProperty(Config::class, 'present'))->getValue();
foreach (array('USE_REDIS', 'REDIS_PORT', 'REDIS_DB', 'REDIS_PREFIX') as $key) $present[$key] = true;
(new ReflectionProperty(Config::class, 'present'))->setValue(null, $present);
$connection = new RedisConnection();
$redis = $connection->requireConnection();
$queue = new SchedulerQueue($db, $connection);
$scheduler = new Scheduler($db, new ProcessRunner(), $audit, $queue);
$services->setValue(null, array('database' => $db, 'audit' => $audit, 'scheduler' => $scheduler));
$run = fn($job, $token) => $scheduler->execute($job['ID'], $token);
$member = static fn($id) => str_pad((string)$id, 20, '0', STR_PAD_LEFT);
try {
    $scheduler->run($run);
    $adapter->queries = array();
    for ($i = 0; $i < 10; $i++) $scheduler->run($run);
    check($adapter->queries === array(), 'Простой Redis не делает SQL-запросов на каждом тике');
    $id = $scheduler->schedule('redis-immediate', 'return true;', time());
    $scheduler->run($run);
    check((int)$scheduler->get($id)['PROCESSED'] === 1, 'Новое задание выполняется из Redis без ожидания сверки');
    $id = $scheduler->schedule('redis-retry', 'return false;', time());
    $scheduler->run($run);
    check((int)$scheduler->get($id)['ATTEMPTS'] === 1 && $scheduler->get($id)['LAST_ERROR'] !== '', 'Ошибка через Redis сохраняет попытку');
    for ($attempt = 2; $attempt <= 3; $attempt++) {
        $pdo->exec("UPDATE jobs SET RUNTIME='2000-01-01' WHERE ID=$id");
        $queue->changed($id);
        $scheduler->run($run);
    }
    check((int)$scheduler->get($id)['EXPIRED'] === 1 && (int)$scheduler->get($id)['ATTEMPTS'] === 3, 'Через Redis тоже ровно три попытки');
    $id = $scheduler->schedule('redis-recover', 'return true;', time());
    // Потеря только sorted set при сохранившемся ключе поколения.
    $redis->del($connection->key('scheduler:due'));
    $scheduler->run($run);
    check((int)$scheduler->get($id)['PROCESSED'] === 1, 'Потерянный индекс восстанавливается из журнала SQL');
    $id = $scheduler->schedule('after-commit-crash', 'return true;', time());
    $redis->zRem($connection->key('scheduler:due'), $member($id));
    $pdo->exec("UPDATE jobs SET DISPATCH_GENERATION='' WHERE ID=$id");
    (new ReflectionProperty(SchedulerQueue::class, 'syncedAt'))->setValue($queue, 0);
    $scheduler->run($run);
    check((int)$scheduler->get($id)['PROCESSED'] === 1, 'Сверка подбирает обрыв между SQL и Redis');
    $unit = $db->beginUnit();
    $rolledBack = $scheduler->schedule('rollback-redis', 'return true;', time());
    check($redis->zScore($connection->key('scheduler:due'), $member($rolledBack)) === false, 'Незакоммиченное задание не публикуется');
    $db->rollbackUnit($unit);
    check($scheduler->get($rolledBack) === null, 'Откат не оставляет задание');
    $id = $scheduler->schedule('redis-claim-crash', 'return true;', time());
    check(in_array((string)$id, $queue->due(100, 360), true), 'Выданное задание получает аренду Redis');
    $redis->zAdd($connection->key('scheduler:due'), time() - 1, $member($id));
    $scheduler->run($run);
    check((int)$scheduler->get($id)['PROCESSED'] === 1, 'После истечения аренды погибшего планировщика задание возвращается');
    $id = $scheduler->schedule('sql-lease-survives', 'return true;', time());
    $pdo->exec("UPDATE jobs SET STARTED='" . date('Y-m-d H:i:s') . "',CLAIM_TOKEN='active',ATTEMPTS=1 WHERE ID=$id");
    $redis->del($connection->key('scheduler:due'), $connection->key('scheduler:generation'));
    $scheduler->run($run);
    check((int)$scheduler->get($id)['ATTEMPTS'] === 1 && $scheduler->get($id)['CLAIM_TOKEN'] === 'active', 'Потеря Redis не крадёт действующую SQL-аренду');
    $id = $scheduler->schedule('cancel-redis', 'return true;', time());
    $scheduler->cancel($id);
    check($redis->zScore($connection->key('scheduler:due'), $member($id)) === false, 'Отмена удаляет срок из Redis');
    $ordered = array(); $at = time();
    for ($i = 0; $i < 12; $i++) $ordered[] = (string)$scheduler->schedule('order-' . $i, 'return true;', $at);
    check($queue->due(100, 360) === $ordered, 'Одинаковые сроки сохраняют числовой порядок ID');
    foreach ($ordered as $jobId) $scheduler->cancel((int)$jobId);
    // Недоступное соединение подменено объектом, не способным открыть сокет.
    $offline = new RedisConnection();
    (new ReflectionProperty(RedisConnection::class, 'retryAfter'))->setValue($offline, microtime(true) + 60);
    $fallback = new Scheduler($db, new ProcessRunner(), $audit, new SchedulerQueue($db, $offline));
    $id = $fallback->schedule('redis-offline', 'return true;', time());
    $fallback->run(fn($job, $token) => $fallback->execute($job['ID'], $token));
    check((int)$fallback->get($id)['PROCESSED'] === 1, 'При недоступном Redis работает SQL-режим');
    echo "Scheduler Redis: 14 checks passed (isolated real Redis + SQLite).\n";
} finally {
    $redis->del($connection->key('scheduler:due'), $connection->key('scheduler:generation'));
}
