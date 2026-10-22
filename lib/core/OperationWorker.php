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
            } elseif (in_array($item['DATANAME'], array('modulepropertyset', 'moduleevent'), true)) {
                $name = $data['target'];
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) throw new InvalidArgumentException('Invalid module name');
                $file = DIR_MODULES . $name . '/' . $name . '.class.php';
                if (!is_file($file)) {
                    if ($item['DATANAME'] === 'moduleevent') throw new RuntimeException('Subscribed module is not installed');
                    // A module that is still registered may be in the middle of a
                    // deployment (files replaced, container starting). Never unlink
                    // it on a short retry window: keep the binding and surface the
                    // failure through operations_failed instead.
                    if ($this->isInstalled($name)) throw new RuntimeException('Linked module files are temporarily unavailable');
                    // A deployment can briefly remove the file; confirm through normal retries.
                    if ((int)($item['ATTEMPTS'] ?? 1) < 3) throw new RuntimeException('Linked module is not installed');
                    return $this->removeStaleBinding($name, $params, 'module is not installed');
                }
                require_once $file;
                if (!class_exists($name, false)) throw new RuntimeException('Linked module class was not loaded');
                if ($item['DATANAME'] === 'moduleevent') {
                    if (!method_exists($name, 'processSubscription')) throw new RuntimeException('Module has no subscription handler');
                    $module = new $name();
                    $details = $params['details'];
                    if ($module->processSubscription($params['event'], $details) === false) throw new RuntimeException('Subscription handler returned false');
                    return array('success' => true);
                }
                if (!method_exists($name, 'propertySetHandle')) {
                    return $this->removeStaleBinding($name, $params, 'module has no property handler');
                }
                $module = new $name();
                $result = $module->propertySetHandle($params['object'], $params['property'], $params['value']);
            } else throw new InvalidArgumentException('Unknown core operation');
            if ($item['DATANAME'] === 'modulepropertyset' && $result === false) throw new RuntimeException('Module handler returned false');
            return array('success' => true);
        } catch (Throwable $e) {
            $context = array('target' => $data['target'] ?? null);
            foreach (array('object', 'property') as $key) {
                if (isset($params[$key]) && is_string($params[$key])) $context[$key] = $params[$key];
            }
            return array('success' => false, 'error' => get_class($e) . ': ' . $e->getMessage()
                . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    /** Marker or project registry row means the module is expected to exist. */
    private function isInstalled(string $module): bool
    {
        if (function_exists('isModuleInstalled') && defined('ROOT')) {
            try {
                if (isModuleInstalled($module)) return true;
            } catch (Throwable $e) {
                // Fall through to the registry lookup.
            }
        }
        try {
            return (bool)$this->db->one("SELECT ID FROM project_modules WHERE NAME='" . $this->db->escape($module) . "' LIMIT 1");
        } catch (Throwable $e) {
            return false;
        }
    }

    private function removeStaleBinding(string $module, array $params, string $reason): array
    {
        $object = $params['object'] ?? null;
        $property = $params['property'] ?? null;
        if (!is_string($object) || !is_string($property) || $object === '' || $property === '') {
            throw new InvalidArgumentException('Object and property are required to remove a stale module binding');
        }
        if (Core::bindings()->detach($object, $property, $module)) {
            DebMes('Removed stale module binding: ' . json_encode(array('module' => $module,
                'object' => $object, 'property' => $property, 'reason' => $reason),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'core_runtime', 'warning');
        }
        return array('success' => true, 'skipped' => $reason);
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
                DebMes('Core operation failed permanently: ' . $error, 'core_runtime', 'error');
            } else {
                $this->queue->release($item, 5);
                DebMes('Core operation will retry: ' . ($result['error'] ?? 'unknown error'), 'core_runtime', 'warning');
            }
        }
        return count($items);
    }
}
