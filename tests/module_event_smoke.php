<?php

/** Generic worker dispatch with an isolated fixture module; no app boot. */
namespace MajorDoMo\Core {
    class Database {}
    class RedisConnection {}
    class ProcessRunner {}
    class OperationsQueue {
        public array $item;
        public function claimed($topic, $id, $token) {
            if ($topic !== 'core_runtime' || $token !== 'fixture-token') throw new \RuntimeException('Bad queue claim');
            return $this->item;
        }
    }
}
namespace {
    putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('USE_REDIS=false');
    $pdo = new PDO('sqlite::memory:');
    if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Isolation failed');
    $fixture = sys_get_temp_dir() . '/mjd-module-event-' . bin2hex(random_bytes(6));
    mkdir($fixture . '/fixture_event', 0700, true);
    define('DIR_MODULES', $fixture . '/');
    $source = <<<'PHP'
<?php
class fixture_event {
    public function processSubscription($event, &$details) {
        $GLOBALS['received_event'] = array($event, $details, $_SERVER['REQUEST_URI']);
        if (($details['mode'] ?? '') === 'throw') throw new RuntimeException('fixture handler failed');
        return ($details['mode'] ?? '') === 'false' ? false : null;
    }
}
PHP;
    file_put_contents($fixture . '/fixture_event/fixture_event.class.php', $source);
    require dirname(__DIR__) . '/lib/core/OperationWorker.php';
    $queue = new MajorDoMo\Core\OperationsQueue();
    $worker = new MajorDoMo\Core\OperationWorker(new MajorDoMo\Core\Database(), new MajorDoMo\Core\RedisConnection(), $queue, new MajorDoMo\Core\ProcessRunner());
    $checks = 0;
    function check($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
    try {
        $data = array('target' => 'fixture_event', 'path' => '/mqtt/module/fixture_event',
            'params' => array('event' => 'MQTT_MESSAGE', 'details' => array('topic' => 'root/sensor', 'payload' => '{"occupancy":true}')));
        $queue->item = array('DATANAME' => 'moduleevent', 'DATAVALUE' => json_encode($data));
        check($worker->execute(1, 'fixture-token')['success'] === true, 'Module event dispatched');
        check($GLOBALS['received_event'] === array('MQTT_MESSAGE', $data['params']['details'], $data['path']), 'Original message and source passed to module');
        foreach (array('throw', 'false') as $mode) {
            $data['params']['details']['mode'] = $mode;
            $queue->item['DATAVALUE'] = json_encode($data);
            $result = $worker->execute(1, 'fixture-token');
            check($result['success'] === false && str_contains($result['error'], $mode === 'throw' ? 'fixture handler failed' : 'returned false'), 'Module failures remain available for retry');
        }
        $data['target'] = '../fixture_event'; $queue->item['DATAVALUE'] = json_encode($data);
        check($worker->execute(1, 'fixture-token')['success'] === false, 'Module path traversal rejected');
        $data['target'] = 'missing_fixture'; $queue->item['DATAVALUE'] = json_encode($data);
        check($worker->execute(1, 'fixture-token')['success'] === false, 'Missing module is not acknowledged');
        check($worker->execute(1, 'invalid-token')['success'] === false, 'Invalid queue claim rejected');
    } finally {
        unlink($fixture . '/fixture_event/fixture_event.class.php'); rmdir($fixture . '/fixture_event'); rmdir($fixture);
    }
    echo 'Module event smoke tests passed: ' . $checks . " checks; no external connections.\n";
}
