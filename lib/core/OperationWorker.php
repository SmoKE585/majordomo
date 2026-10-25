<?php

namespace MajorDoMo\Core;

use \Throwable;
use \RuntimeException;
use \InvalidArgumentException;

/**
 * Выполнение фоновых операций из очереди (методы, модули, сообщения, подписки).
 *
 * Изменения:
 * 2026-10-25 — промежуточные повторы остаются в логе, окончательный отказ содержит источник задания.
 * 2026-10-25 — отказы и повторы сохраняются в SQL вместе с заданием, исключение исполнителя не оставляет скрытое владение.
 * 2026-10-25 — исключения NO_LINKED восстанавливаются с областью свойства,
 *              в том числе у заданий, поставленных прежней версией ядра.
 * 2026-10-23 — явный отказ метода или сценария больше не подтверждается как успешная операция.
 * 2026-10-22 — воркер восстанавливает точку доставки (модуль + свойство), чтобы
 *              исключение эха не распространялось на вложенные привязки.
 * 2026-10-22 — сценарии, сообщения и подписки вызывают функции ядра напрямую.
 * 2026-10-22 — ошибка внутреннего API показывает путь и причину отказа,
 *              а не только тип операции.
 * 2026-10-23 — ошибка получения задания из очереди больше не обращается к
 *              неинициализированному $item и не пишет предупреждение PHP.
 */
final class OperationWorker
{
    public function __construct(
        private Database $db,
        private RedisConnection $redis,
        private OperationsQueue $queue,
        private ProcessRunner $processes,
        private ?ErrorReporter $errors = null,
        private ?PropertyAudit $audit = null
    ) {}

    public function execute($id, $token)
    {
        $data = array();
        $params = array();
        $item = null;
        $auditStep = null;
        $auditError = null;
        $result = null;
        try {
            $item = $this->queue->claimedRuntime('core_runtime', $id, $token);
            $data = json_decode($item['DATAVALUE'], true, 512, JSON_THROW_ON_ERROR);
            $params = $data['params'];
            $chain = is_array($params['_chain'] ?? null) ? $params['_chain'] : array();
            $delivery = is_array($params['_delivery'] ?? null) ? $params['_delivery'] : array();
            $exclusions = is_array($delivery['exclusions'] ?? null) ? $delivery['exclusions'] : array();
            $source = '';
            $point = '';
            if (in_array($item['DATANAME'], array('modulepropertyset', 'moduleevent'), true)) {
                // Модуль уведомили о конкретном свойстве: именно на нём гасится
                // его собственное эхо, а не на всей дальнейшей цепочке.
                $source = (string)($data['target'] ?? '');
                if ($item['DATANAME'] === 'modulepropertyset') {
                    $point = PropertyChain::token((string)($params['object'] ?? ''), (string)($params['property'] ?? ''));
                }
            } else {
                // Метод/сценарий продолжает доставку, начатую модулем: точку
                // нужно сохранить, иначе на следующем уровне модуль-источник
                // снова выпадет из собственных привязок.
                $source = (string)($delivery['module'] ?? '');
                $point = (string)($delivery['point'] ?? '');
            }
            // Старые ONCHANGE уже могли попасть в очередь без области NO_LINKED.
            // Объект метода и PROPERTY позволяют восстановить её без миграции.
            if ($item['DATANAME'] === 'method' && !isset($delivery['exclusions'])
                && is_array($params['NO_LINKED'] ?? null)
                && is_string($params['PROPERTY'] ?? null) && $params['PROPERTY'] !== '') {
                $target = (string)($data['target'] ?? '');
                $separator = strrpos($target, '.');
                if ($separator !== false) {
                    foreach (array_keys($params['NO_LINKED']) as $module) {
                        if (isset($params['NO_LINKED'][$module])) {
                            $exclusions[$module] = PropertyChain::token(substr($target, 0, $separator), (string)$params['PROPERTY']);
                        }
                    }
                }
            }
            PropertyChain::enter($chain, $source, $point, $exclusions);
            if (is_array($params['NO_LINKED'] ?? null)
                && !isset($params['NO_LINKED'][PropertyChain::EXCLUSION_SCOPE])) {
                $params['NO_LINKED'] = PropertyChain::scopedExclusions($params['NO_LINKED'], $exclusions);
            }
            $_GET = $params;
            $_GET['r_s_m'] = 1;
            $_REQUEST = $_GET;
            $_SERVER['REQUEST_URI'] = $data['path'];
            $_SERVER['REQUEST_METHOD'] = 'GET';

            if ($this->audit) {
                $traceId = PropertyAudit::normalizeTraceId((string)($params['_trace'] ?? ''));
                if ($traceId !== '') {
                    $this->audit->enter($traceId, 'resume', 'Продолжение');
                } else {
                    $trigger = match ($item['DATANAME']) {
                        'moduleevent', 'subscriptions' => 'event',
                        'modulepropertyset' => 'module',
                        'script' => 'script',
                        'say', 'sayreply', 'sayto' => 'message',
                        default => 'method',
                    };
                    $title = (string)($data['target'] ?? 'Операция');
                    $this->audit->begin($trigger, $title, $params);
                }
            }

            if ($item['DATANAME'] === 'method') {
                $result = Core::objects()->call($data['target'], $params);
            } elseif ($item['DATANAME'] === 'script') {
                $target = (string)$data['target'];
                $script = $this->db->one('SELECT ID FROM scripts WHERE ' . (ctype_digit($target)
                    ? 'ID=' . (int)$target : "TITLE='" . $this->db->escape($target) . "'"));
                if (!$script) throw new RuntimeException('Сценарий не найден: ' . $target);
                $result = runScript((int)$script['ID'], $params);
            } elseif ($item['DATANAME'] === 'say') {
                $result = say($params['message'], $params['level'], $params['member_id'], $params['source']);
            } elseif ($item['DATANAME'] === 'sayreply') {
                $result = sayReply($params['message'], $params['level'], $params['replyto']);
            } elseif ($item['DATANAME'] === 'sayto') {
                $result = sayTo($params['message'], $params['level'], $params['destination']);
            } elseif ($item['DATANAME'] === 'subscriptions') {
                $result = SubscriptionDelivery::run($params['event'], $params['details']);
            } elseif (in_array($item['DATANAME'], array('modulepropertyset', 'moduleevent'), true)) {
                $name = $data['target'];
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) throw new InvalidArgumentException('Invalid module name');
                $file = DIR_MODULES . $name . '/' . $name . '.class.php';
                if (!is_file($file)) {
                    if ($item['DATANAME'] === 'moduleevent') throw new RuntimeException('Subscribed module is not installed');
                    // A module that is still registered may be in the middle of a
                    // deployment (files replaced, container starting). Never unlink
                    // it on a short retry window: keep the binding and surface the
                    // Отказ останется в долговечном журнале; привязка при этом сохраняется.
                    if ($this->isInstalled($name)) throw new RuntimeException('Linked module files are temporarily unavailable');
                    // A deployment can briefly remove the file; confirm through normal retries.
                    if ((int)($item['ATTEMPTS'] ?? 1) < 3) throw new RuntimeException('Linked module is not installed');
                    $result = $this->removeStaleBinding($name, $params, 'module is not installed');
                }
                if ($result === null) {
                    require_once $file;
                    if (!class_exists($name, false)) throw new RuntimeException('Linked module class was not loaded');
                    $auditStep = $this->audit?->stepStart('module', $name, array(
                        'module' => $name,
                        'subtitle' => $item['DATANAME'] === 'moduleevent' ? 'Обработчик события' : 'Обработчик свойства',
                        'params' => $params,
                    ));
                    if ($item['DATANAME'] === 'moduleevent') {
                        if (!method_exists($name, 'processSubscription')) throw new RuntimeException('Module has no subscription handler');
                        $module = new $name();
                        $details = $params['details'];
                        if ($module->processSubscription($params['event'], $details) === false) throw new RuntimeException('Subscription handler returned false');
                        $result = array('success' => true);
                    } else {
                        if (!method_exists($name, 'propertySetHandle')) {
                            $result = $this->removeStaleBinding($name, $params, 'module has no property handler');
                        } else {
                            $module = new $name();
                            $result = $module->propertySetHandle($params['object'], $params['property'], $params['value']);
                        }
                    }
                }
            } elseif ($item['DATANAME'] === 'api') {
                $result = Core::api()->request($data['path'], $data['method'], $params, true);
                if ($result === false) {
                    // Без пути и причины такая ошибка не диагностируется: в журнале
                    // оставалось только «target: script», без имени сценария.
                    $path = (string)($data['path'] ?? '');
                    $reason = (string)(Core::api()->lastError() ?? '');
                    throw new RuntimeException('Internal API operation failed: ' . rawurldecode($path)
                        . ($reason !== '' ? ' (' . $reason . ')' : ''));
                }
            } else throw new InvalidArgumentException('Unknown core operation');
            if ($result === false && in_array($item['DATANAME'], array('method', 'script', 'modulepropertyset'), true)) {
                throw new RuntimeException('Обработчик операции ' . $item['DATANAME'] . ' вернул false: ' . (string)($data['target'] ?? ''));
            }
            if (!is_array($result) || !array_key_exists('success', $result)) $result = array('success' => true);
            return $result;
        } catch (Throwable $e) {
            $auditError = $e;
            $context = array('target' => $data['target'] ?? null);
            foreach (array('object', 'property') as $key) {
                if (isset($params[$key]) && is_string($params[$key])) $context[$key] = $params[$key];
            }
            if (($item['DATANAME'] ?? '') === 'api' && !empty($data['path'])) {
                $context['path'] = rawurldecode((string)$data['path']);
            }
            $result = array('success' => false, 'error' => get_class($e) . ': ' . $e->getMessage()
                . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            $result['diagnostic'] = ErrorDetails::capture($e->getMessage(), array('entity' => (string)($data['target'] ?? '')), $e);
            return $result;
        } finally {
            if ($this->audit) {
                $this->audit->stepFinish($auditStep, $result, $auditError);
                $this->audit->finishRun($auditError ? 'error' : 'done', $result, $auditError);
                $this->audit->leave();
            }
            PropertyChain::leave();
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
        if ($execute === null) $this->processes->prepare();
        $timeout = defined('CORE_OPERATION_TIMEOUT') ? max(1, (int)CORE_OPERATION_TIMEOUT) : 60;
        // One reservation at a time: no message waits in a local batch while its lease expires.
        // При CORE_WORKERS > 1 очередь выдаёт событие только из свободной полосы.
        $items = $this->queue->claimRuntime(1, $timeout + 60);
        foreach ($items as $item) {
            try {
                $result = $execute ? $execute($item) : $this->processes->run('operation', $item['QUEUE_ID'], $item['CLAIM_TOKEN'], $timeout);
                if (!is_array($result)) $result = array('success' => false, 'error' => 'Исполнитель не вернул результат');
            } catch (Throwable $error) {
                $result = array('success' => false, 'error' => get_class($error) . ': ' . $error->getMessage());
            }
            if (!empty($result['success'])) {
                $this->queue->acknowledgeRuntime($item);
            } elseif ((int)($item['ATTEMPTS'] ?? 1) >= 3) {
                $error = substr((string)($result['error'] ?? 'Unknown worker error'), 0, 4096);
                $this->queue->failRuntime($item, $error);
                if ($this->errors) {
                    $diagnostic = is_array($result['diagnostic'] ?? null) ? $result['diagnostic'] : array();
                    $operation = json_decode((string)($item['DATAVALUE'] ?? ''), true);
                    $triggerEvent = in_array($item['DATANAME'] ?? '', array('subscriptions', 'moduleevent'), true)
                        ? (string)($operation['params']['event'] ?? $operation['target'] ?? '') : '';
                    $this->errors->report('property_delivery',
                        'Фоновая операция не выполнена после повторов: ' . $error,
                        'worker:' . (string)($item['QUEUE_ID'] ?? $item['DATANAME'] ?? 'unknown'), 300,
                        array_merge(array_intersect_key($diagnostic, array_flip(array('file', 'line', 'entity'))),
                            array('source' => 'фоновая операция', 'trigger_event' => $triggerEvent,
                                'action' => 'Устраните причину в обработчике; проверьте окончательно отклонённое задание в очереди.')));
                } else {
                    DebMes('Core operation failed permanently: ' . $error, 'core_runtime', 'error');
                }
            } else {
                $attempt = max(1, (int)($item['ATTEMPTS'] ?? 1));
                $delay = min(300, 5 * (2 ** ($attempt - 1)));
                $this->queue->releaseRuntime($item, $delay, (string)($result['error'] ?? 'Unknown worker error'));
                DebMes('Core operation will retry: ' . ($result['error'] ?? 'unknown error'), 'core_runtime', 'warning');
            }
        }
        return count($items);
    }
}
