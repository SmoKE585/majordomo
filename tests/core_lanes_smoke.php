<?php

/**
 * Parallel core queue on real isolated Redis: per-object order, serial lane,
 * acknowledgement and retry. Requires MJD_TEST_REDIS_PORT.
 */
putenv('APP_ENV=testing');
putenv('USE_REDIS=false');
require_once __DIR__ . '/RedisTestClient.php';
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Database;
use MajorDoMo\Core\LaneEventQueue;
use MajorDoMo\Core\OperationWorker;
use MajorDoMo\Core\OperationsQueue;
use MajorDoMo\Core\ProcessRunner;
use MajorDoMo\Core\RedisConnection;

$port = (int)getenv('MJD_TEST_REDIS_PORT');
if ($port < 1) {
    echo 'SKIP: MJD_TEST_REDIS_PORT is not set.' . PHP_EOL;
    exit(0);
}

$fixture = sys_get_temp_dir() . '/mjd-core-lanes-' . bin2hex(random_bytes(6));
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
$values['CORE_WORKERS'] = 3;
(new ReflectionProperty(Config::class, 'values'))->setValue(null, $values);
$present = (new ReflectionProperty(Config::class, 'present'))->getValue();
foreach (array('USE_REDIS', 'REDIS_PORT', 'REDIS_DB', 'REDIS_PREFIX', 'CORE_WORKERS') as $key) $present[$key] = true;
(new ReflectionProperty(Config::class, 'present'))->setValue(null, $present);

function check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}
function DebMes($message, $source = '', $severity = '') {}

$connection = new RedisConnection();
$lanes = new LaneEventQueue($connection, 'core_verify', 5);
$queue = new OperationsQueue(new Database(new stdClass()), $connection, $lanes);
$worker = new OperationWorker(new Database(new stdClass()), $connection, $queue, new ProcessRunner());
check($queue->parallelEnabled(), 'Parallel core queue must be enabled');

$queue->enqueueRuntime('method', array(), 'A.one');
$queue->enqueueRuntime('method', array(), 'A.two');
$queue->enqueueRuntime('method', array(), 'B.one');
$queue->enqueueRuntime('script', array('id' => 1), 'script');

$order = array();
$iterations = 0;
do {
    $processed = $worker->process(function (array $item) use (&$order): array {
        $payload = json_decode((string)$item['DATAVALUE'], true);
        $order[] = (string)($payload['target'] ?? '');
        return array('success' => true);
    });
    $iterations++;
} while ($processed > 0 && $iterations < 20);

check($order === array('script', 'A.one', 'B.one', 'A.two') || $order === array('script', 'A.one', 'A.two', 'B.one'),
    'Serial first, then per-object order; got ' . implode(',', $order));
$posA1 = array_search('A.one', $order, true);
$posA2 = array_search('A.two', $order, true);
check($posA1 !== false && $posA2 !== false && $posA1 < $posA2, 'Method order must be preserved per object');
check($queue->claimRuntime(1, 10) === array(), 'Queue must be empty');

// The subprocess fetches its reserved event by id/token from the lane queue.
$queue->enqueueRuntime('method', array(), 'E.one');
$reserved = $queue->claimRuntime(1, 10)[0];
$fetched = $queue->claimedRuntime('core_runtime', $reserved['QUEUE_ID'], $reserved['CLAIM_TOKEN']);
check(($fetched['LANE'] ?? '') === 'object:E', 'Subprocess must read the reserved lane event');
$queue->acknowledgeRuntime($reserved);

// A failed operation keeps the lane and is retried, not acknowledged.
$queue->enqueueRuntime('method', array(), 'C.one');
$queue->enqueueRuntime('method', array(), 'C.two');
$attempts = 0;
do {
    $processed = $worker->process(function (array $item) use (&$attempts): array {
        $payload = json_decode((string)$item['DATAVALUE'], true);
        if (($payload['target'] ?? '') === 'C.one' && $attempts < 1) {
            $attempts++;
            return array('success' => false, 'error' => 'synthetic retry');
        }
        return array('success' => true);
    });
} while ($processed > 0);
check($attempts === 1, 'First C.one attempt must fail');
check($lanes->inflightCount() === 0, 'Retried event must not stay in flight');

// The retry delay is respected; promote it manually and finish both events.
$promoted = 0;
for ($i = 0; $i < 20 && $promoted === 0; $i++) {
    sleep(1);
    $promoted = $lanes->promoteDelayed(10);
}
check($promoted >= 1, 'Retried method must be promoted after its delay');
$iterations = 0;
do {
    $processed = $worker->process(function (array $item): array { return array('success' => true); });
    $iterations++;
} while ($processed > 0 && $iterations < 10);
check($queue->claimRuntime(1, 10) === array() && $lanes->delayedCount() === 0, 'Retried queue must drain');

// Upgrade compatibility: an item left in the old ZSET is moved into its lane.
$queue->enqueue('core_runtime', 'method', json_encode(array('target' => 'D.one', 'path' => 'core:method/D.one', 'params' => array())), false, 3600);
check($queue->migrateLegacyRuntime(10) === 1, 'Legacy item must be moved to a lane');
$migrated = $queue->claimRuntime(1, 10);
check(count($migrated) === 1 && ($migrated[0]['LANE'] ?? '') === 'object:D', 'Migrated item must keep its object lane');
$queue->acknowledgeRuntime($migrated[0]);

echo 'Core lane queue smoke tests passed: 11 checks on real Redis.' . PHP_EOL;
