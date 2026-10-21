<?php

namespace MajorDoMo\Core;

use \Throwable;
use \RuntimeException;
use \InvalidArgumentException;

final class OperationWorker
{
    public function __construct(
        private Database $db,
        private RedisConnection $redis,
        private OperationsQueue $queue,
        private ProcessRunner $processes
    ) {}

    public function execute($id, $token)
    {
        try {
            $item = $this->queue->claimed('core_runtime', $id, $token);
            $data = json_decode($item['DATAVALUE'], true, 512, JSON_THROW_ON_ERROR);
            $params = $data['params'];
            $_GET = $params;
            $_GET['r_s_m'] = 1;
            $_REQUEST = $_GET;
            $_SERVER['REQUEST_URI'] = $data['path'];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            if ($item['DATANAME'] === 'method') {
                $result = Core::objects()->call($data['target'], $params);
            } elseif ($item['DATANAME'] === 'modulepropertyset') {
                $name = $data['target'];
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) throw new InvalidArgumentException('Invalid module name');
                $file = DIR_MODULES . $name . '/' . $name . '.class.php';
                if (!is_file($file)) throw new RuntimeException('Linked module is not installed');
                require_once $file;
                $module = new $name();
                if (!method_exists($module, 'propertySetHandle')) throw new RuntimeException('Linked module has no property handler');
                $result = $module->propertySetHandle($params['object'], $params['property'], $params['value']);
            } else throw new InvalidArgumentException('Unknown core operation');
            if ($item['DATANAME'] === 'modulepropertyset' && $result === false) throw new RuntimeException('Module handler returned false');
            return array('success' => true);
        } catch (Throwable $e) {
            return array('success' => false, 'error' => get_class($e) . ': ' . $e->getMessage());
        }
    }

    public function process($execute = null)
    {
        $timeout = defined('CORE_OPERATION_TIMEOUT') ? max(1, (int)CORE_OPERATION_TIMEOUT) : 60;
        // One reservation at a time: no message waits in a local batch while its lease expires.
        $items = $this->queue->claim('core_runtime', 1, $timeout + 30);
        foreach ($items as $item) {
            $result = $execute ? $execute($item) : $this->processes->run('operation', $item['QUEUE_ID'], $item['CLAIM_TOKEN'], $timeout);
            if (!empty($result['success'])) $this->queue->acknowledge($item);
            elseif ((int)($item['ATTEMPTS'] ?? 1) >= 3) {
                $error = substr((string)($result['error'] ?? 'Unknown worker error'), 0, 4096);
                if (Config::get('USE_REDIS')) {
                    $this->redis->requireConnection()->hSet($this->redis->key('queue:failed:core_runtime'), $item['QUEUE_ID'], json_encode(array('item' => $item, 'error' => $error), JSON_THROW_ON_ERROR));
                } else {
                    $record = array('TOPIC' => 'core_runtime', 'PAYLOAD' => json_encode($item, JSON_THROW_ON_ERROR), 'LAST_ERROR' => $error, 'ADDED' => date('Y-m-d H:i:s'));
                    if (!$this->db->insert('operations_failed', $record)) throw new RuntimeException('Cannot preserve failed operation');
                }
                $this->queue->acknowledge($item);
                DebMes('Core operation failed permanently: ' . $error, 'core_runtime');
            } else {
                $this->queue->release($item, 5);
                DebMes('Core operation will retry: ' . ($result['error'] ?? 'unknown error'), 'core_runtime');
            }
        }
        return count($items);
    }
}
