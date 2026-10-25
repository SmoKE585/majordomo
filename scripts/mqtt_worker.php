<?php

/**
 * Внутренний процесс быстрого пути MQTT.
 *
 * Роли:
 *  - handler   — разбирает входящее событие, пишет свойства и ставит автоматизации;
 *  - state     — переносит состояние и историю в MySQL;
 *  - publisher — публикует исходящие команды на отдельном соединении.
 *
 * Запускается только cycle_mqtt_runtime.php; вручную не предназначен.
 * 2026-10-25 — издатель обслуживает keepalive при пустой очереди и закрывает соединение после ошибки.
 * 2026-10-23 — добавлено для быстрого пути MQTT.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));
require_once './config.php';
require_once './lib/loader.php';
require_once './load_settings.php';

set_time_limit(0);
$role = $argv[1] ?? 'handler';
$slot = $argv[2] ?? ($role . ':' . getmypid());
$stopFile = ROOT . 'reboot';

require_once DIR_MODULES . 'mqtt/mqtt.class.php';
require_once DIR_MODULES . 'mqtt/MqttPublisher.php';

MqttRuntime::heartbeat($slot);

function mqttWorkerShouldStop(string $stopFile): bool
{
    if (function_exists('isRebootRequired') && isRebootRequired()) return true;
    return file_exists($stopFile);
}

function mqttWorkerLog(string $message, string $severity = 'warning'): void
{
    static $last = array();
    $key = md5($severity . "\0" . $message);
    if (($last[$key] ?? 0) > time() - 60) return;
    $last[$key] = time();
    DebMes($message, 'mqtt_runtime', $severity);
    echo date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
}

if ($role === 'publisher') {
    $module = new mqtt();
    $module->getConfig();
    $publisher = new MqttPublisher($module->config);
    $publisher->connect();
    echo date('Y-m-d H:i:s') . ' MQTT publisher started' . PHP_EOL;
    $lastHeartbeat = 0;
    while (true) {
        if (mqttWorkerShouldStop($stopFile)) break;
        if (time() - $lastHeartbeat >= 5) {
            $lastHeartbeat = time();
            MqttRuntime::heartbeat('publisher');
        }
        try {
            $publisher->tick();
            $items = \MajorDoMo\Core\Core::queue()->claim('mqtt_queue', 1, 60);
            if (!$items) {
                usleep(50000);
                continue;
            }
            foreach ($items as $item) {
                if (mqttWorkerShouldStop($stopFile)) break;
                $topic = (string)($item['DATANAME'] ?? '');
                if ($topic === '') {
                    \MajorDoMo\Core\Core::queue()->acknowledge($item);
                    continue;
                }
                $payload = json_decode((string)($item['DATAVALUE'] ?? ''), true);
                $value = is_array($payload) && array_key_exists('v', $payload) ? (string)$payload['v'] : '';
                $qos = is_array($payload) ? (int)($payload['q'] ?? 0) : 0;
                $retain = is_array($payload) ? (int)($payload['r'] ?? 0) : 0;
                $started = microtime(true);
                $ok = $publisher->publish($topic, $value, $qos, $retain);
                if ($ok) {
                    \MajorDoMo\Core\Core::queue()->acknowledge($item);
                    MqttRuntime::count('published');
                    MqttRuntime::sample('publish', (microtime(true) - $started) * 1000);
                } else {
                    \MajorDoMo\Core\Core::queue()->release($item, 5);
                    MqttRuntime::count('publish_retry');
                    break;
                }
            }
        } catch (Throwable $error) {
            mqttWorkerLog('MQTT publisher: ' . $error->getMessage());
            $publisher->close();
            sleep(1);
        }
    }
    $publisher->close();
    exit(0);
}

if ($role === 'state') {
    MqttSchema::ensure();
    echo date('Y-m-d H:i:s') . ' MQTT state writer started' . PHP_EOL;
    $queue = MqttRuntime::queue('incoming');
    $lastMaintenance = 0;
    $lastHeartbeat = 0;
    while (true) {
        if (mqttWorkerShouldStop($stopFile)) break;
        if (time() - $lastHeartbeat >= 5) {
            $lastHeartbeat = time();
            MqttRuntime::heartbeat('state');
        }
        if (!MqttRuntime::enabled()) {
            sleep(5);
            continue;
        }
        try {
            $started = microtime(true);
            $state = MqttRuntime::flushState(200);
            $history = MqttRuntime::flushHistory(500);
            if ($state + $history > 0) {
                MqttRuntime::sample('project', (microtime(true) - $started) * 1000);
            } else {
                usleep(200000);
            }
            if (time() - $lastMaintenance >= 5) {
                $lastMaintenance = time();
                $queue->promoteDelayed(100);
                $queue->reap(100);
            }
        } catch (Throwable $error) {
            mqttWorkerLog('MQTT state writer: ' . $error->getMessage());
            sleep(1);
        }
    }
    exit(0);
}

// handler
MqttSchema::ensure();
$module = new mqtt();
$module->getConfig();
$index = $module->getRuntimeIndex();
if (!$index->ready()) {
    mqttWorkerLog('MQTT handler cannot load bindings, exiting for supervisor restart', 'error');
    exit(1);
}
$queue = MqttRuntime::queue('incoming');
$maxAttempts = max(2, \MajorDoMo\Core\Config::int('MQTT_MAX_ATTEMPTS', 5));
$lease = max(30, \MajorDoMo\Core\Config::int('MQTT_HANDLER_LEASE', 120));
$lastMaintenance = 0;
echo date('Y-m-d H:i:s') . ' MQTT handler started, lane queue ready' . PHP_EOL;

$lastHeartbeat = 0;
while (true) {
    if (mqttWorkerShouldStop($stopFile)) break;
    if (time() - $lastHeartbeat >= 5) {
        $lastHeartbeat = time();
        MqttRuntime::heartbeat($slot);
    }
    if (!MqttRuntime::enabled()) {
        sleep(5);
        continue;
    }
    try {
        $events = $queue->claim(1, $lease);
        if (!$events) {
            if (time() - $lastMaintenance >= 5) {
                $lastMaintenance = time();
                $queue->promoteDelayed(100);
                $queue->reap(100);
            }
            usleep(30000);
            continue;
        }
        $event = $events[0];
        $queuedAt = (float)($event['QUEUED_AT'] ?? 0);
        if ($queuedAt > 0) {
            MqttRuntime::sample('claim', (microtime(true) - $queuedAt) * 1000);
        }
        try {
            $index->ensureFresh(0.5);
            $started = microtime(true);
            $hasRequestUri = isset($_SERVER['REQUEST_URI']);
            $oldRequestUri = $hasRequestUri ? $_SERVER['REQUEST_URI'] : '';
            $_SERVER['REQUEST_URI'] = $module->buildIncomingSource(
                (string)($event['path'] ?? ''),
                (string)($event['value'] ?? '')
            );
            try {
                $module->processRuntimeMessage(
                    (string)($event['path'] ?? ''),
                    (string)($event['value'] ?? ''),
                    false,
                    $index
                );
            } finally {
                if ($hasRequestUri) {
                    $_SERVER['REQUEST_URI'] = $oldRequestUri;
                } else {
                    unset($_SERVER['REQUEST_URI']);
                }
            }
            MqttRuntime::sample('handle', (microtime(true) - $started) * 1000);
            if (!$queue->acknowledge($event)) {
                MqttRuntime::count('ack_expired');
                mqttWorkerLog('MQTT event lease expired before acknowledgement; it will be retried', 'error');
            }
            MqttRuntime::count('handled');
        } catch (Throwable $error) {
            $attempts = (int)($event['ATTEMPTS'] ?? 1);
            if ($attempts >= $maxAttempts) {
                $queue->fail($event, $error->getMessage());
                MqttRuntime::count('failed');
                mqttWorkerLog('MQTT event moved to failed after ' . $attempts . ' attempts: '
                    . $error->getMessage() . ' [' . ($event['path'] ?? '') . ']', 'error');
            } else {
                $delay = min(60, 2 ** min(6, $attempts));
                $queue->release($event, $delay);
                MqttRuntime::count('retried');
                mqttWorkerLog('MQTT event will retry in ' . $delay . 's: ' . $error->getMessage()
                    . ' [' . ($event['path'] ?? '') . ']');
            }
        }
    } catch (Throwable $error) {
        mqttWorkerLog('MQTT handler loop: ' . $error->getMessage(), 'error');
        sleep(1);
    }
}
