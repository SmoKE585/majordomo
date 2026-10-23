<?php

/**
 * Настоящий Redis, изолированная база и уникальный префикс.
 * Требует MJD_TEST_REDIS_PORT; без него проверка пропускается.
 *
 * Проверяет Lua-контракт LaneEventQueue: порядок в полосе, параллельность
 * разных полос, эксклюзивность serial, подтверждение, повтор, отложенные
 * события и возврат зависших задач.
 */
putenv('APP_ENV=testing');
putenv('USE_REDIS=false');
require_once __DIR__ . '/RedisTestClient.php';
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\LaneEventQueue;
use MajorDoMo\Core\RedisConnection;

$port = (int)getenv('MJD_TEST_REDIS_PORT');
if ($port < 1) {
    echo 'SKIP: MJD_TEST_REDIS_PORT is not set.' . PHP_EOL;
    exit(0);
}

$fixture = sys_get_temp_dir() . '/mjd-lane-' . bin2hex(random_bytes(6));
Config::boot($fixture);
register_shutdown_function(static function () use ($fixture) {
    @unlink($fixture . '/cms/cached/config.php');
    @rmdir($fixture . '/cms/cached');
    @rmdir($fixture . '/cms');
    @rmdir($fixture);
});

$values = (new ReflectionProperty(Config::class, 'values'))->getValue();
$values['USE_REDIS'] = '127.0.0.1';
$values['REDIS_PORT'] = $port;
$values['REDIS_DB'] = 15;
$values['REDIS_PREFIX'] = 'mjdt:' . bin2hex(random_bytes(4)) . ':';
(new ReflectionProperty(Config::class, 'values'))->setValue(null, $values);
$present = (new ReflectionProperty(Config::class, 'present'))->getValue();
foreach (array('USE_REDIS', 'REDIS_PORT', 'REDIS_DB', 'REDIS_PREFIX') as $key) $present[$key] = true;
(new ReflectionProperty(Config::class, 'present'))->setValue(null, $present);

function check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

$connection = new RedisConnection();
$queue = new LaneEventQueue($connection, 'verify', 2);
$name = static fn(array $event): string => (string)($event['n'] ?? '');

// Порядок внутри полосы и независимость полос.
$queue->push(array('n' => 'A1'), 'object:A');
$queue->push(array('n' => 'A2'), 'object:A');
$queue->push(array('n' => 'B1'), 'object:B');
$first = $queue->claim(1, 2);
$second = $queue->claim(1, 2);
check(count($first) === 1 && $name($first[0]) === 'A1', 'First event of lane A must be A1');
check(count($second) === 1 && $name($second[0]) === 'B1', 'Other lane must be processed in parallel');
$third = $queue->claim(1, 2);
check($third === array(), 'The same lane must not be claimed twice concurrently');
$queue->acknowledge($first[0]);
$next = $queue->claim(1, 2);
check(count($next) === 1 && $name($next[0]) === 'A2', 'Ack must release lane A for A2');
$queue->acknowledge($next[0]);
$queue->acknowledge($second[0]);
check($queue->size() === 0, 'All events acknowledged');

// serial не пересекается с объектными полосами и не голодает.
$queue->push(array('n' => 'C1'), 'object:C');
$queue->push(array('n' => 'S1'), 'serial');
$serial = $queue->claim(1, 2);
check(count($serial) === 1 && $name($serial[0]) === 'S1', 'Pending serial event must run before new entity work');
$blocked = $queue->claim(1, 2);
check($blocked === array(), 'Entity lanes must not run while serial is active');
$queue->acknowledge($serial[0]);
$afterSerial = $queue->claim(1, 2);
check(count($afterSerial) === 1 && $name($afterSerial[0]) === 'C1', 'Entity work resumes after serial ack');
$queue->acknowledge($afterSerial[0]);

// Подтверждение и токен.
$queue->push(array('n' => 'D1'), 'object:D');
$claimed = $queue->claim(1, 2)[0];
check($name($claimed) === 'D1', 'D1 must be claimed');
$again = $queue->claim(1, 2);
check($again === array(), 'Lane D must stay locked');
check($queue->acknowledge(array_merge($claimed, array('CLAIM_TOKEN' => 'wrong'))) === false, 'Foreign token must not acknowledge');
$queue->acknowledge($claimed);

// Зависшая аренда возвращается в голову полосы.
$queue->push(array('n' => 'E1'), 'object:E');
$stuck = $queue->claim(1, 1)[0];
check($name($stuck) === 'E1', 'E1 must be claimed');
sleep(2);
check($queue->reap(10) >= 1, 'Expired lease must be reaped');
$recovered = $queue->claim(1, 2);
check(count($recovered) === 1 && $name($recovered[0]) === 'E1', 'Reaped event must keep its position and payload');
$queue->acknowledge($recovered[0]);

// Отложенный повтор.
$queue->push(array('n' => 'F1'), 'object:F');
$delayed = $queue->claim(1, 2)[0];
$queue->release($delayed, 2);
check($queue->promoteDelayed(10) === 0, 'Delayed event must not be promoted early');
check($queue->delayedCount() === 1, 'Delayed event must be counted');
sleep(3);
check($queue->promoteDelayed(10) === 1, 'Due delayed event must be promoted');
$promoted = $queue->claim(1, 2);
check(count($promoted) === 1 && $name($promoted[0]) === 'F1', 'Promoted event must be claimable');
$queue->acknowledge($promoted[0]);

// Неудачное событие уходит в журнал и освобождает полосу.
$queue->push(array('n' => 'G1'), 'object:G');
$failed = $queue->claim(1, 2)[0];
$queue->fail($failed, 'synthetic failure');
check($queue->failedCount() === 1, 'Failed event must be stored');
check($queue->size() === 0, 'Failed event must not stay in the queue');
$failedRows = $queue->failed(1);
check(($failedRows[0]['event']['n'] ?? '') === 'G1', 'Failed journal must keep the event payload');

// Немедленный повтор сохраняет порядок.
$queue->push(array('n' => 'H1'), 'object:H');
$queue->push(array('n' => 'H2'), 'object:H');
$h1 = $queue->claim(1, 2)[0];
check($name($h1) === 'H1', 'H1 must be first');
$queue->release($h1);
$retry = $queue->claim(1, 2);
check(count($retry) === 1 && $name($retry[0]) === 'H1', 'Immediate release must put the event back at the head');
$queue->acknowledge($retry[0]);
$h2 = $queue->claim(1, 2);
check(count($h2) === 1 && $name($h2[0]) === 'H2', 'H2 must follow H1');
$queue->acknowledge($h2[0]);

check($queue->size() === 0, 'Queue must be empty at the end');
echo 'Lane queue smoke tests passed: 24 checks on real Redis.' . PHP_EOL;
