<?php

/**
 * Супервизор быстрого пути MQTT.
 *
 * Держит трёх обработчиков, писателя состояния и отдельного издателя.
 * Приём MQTT остаётся в cycle_mqtt.php — он не ждёт ни обработки, ни MySQL.
 *
 * 2026-10-23 — добавлено для быстрого пути MQTT.
 */

chdir(dirname(__DIR__));
require_once './config.php';
require_once './lib/loader.php';
require_once './load_settings.php';
set_time_limit(0);

require_once DIR_MODULES . 'mqtt/mqtt.class.php';
require_once __DIR__ . '/../lib/core/ProcessPool.php';

$startedAt = time();
$pool = null;
$enabled = false;
$redisDown = false;

register_shutdown_function(function () use (&$pool, $startedAt) {
    if ($pool instanceof \MajorDoMo\Core\ProcessPool) $pool->stop();
    echo date('Y-m-d H:i:s') . ' cycle_mqtt_runtime stopped, uptime=' . (time() - $startedAt) . 's' . PHP_EOL;
});

$logDirectory = \MajorDoMo\Core\Config::string('LOG_DIRECTORY');
if ($logDirectory === '') $logDirectory = ROOT . 'cms/debmes';
$pool = new \MajorDoMo\Core\ProcessPool($logDirectory);

$count = MqttRuntime::handlers();
$php = defined('PATH_TO_PHP') && is_string(PATH_TO_PHP) && PATH_TO_PHP !== '' ? PATH_TO_PHP : PHP_BINARY;
for ($i = 1; $i <= $count; $i++) {
    $pool->add('mqtt_handler_' . $i, array($php, ROOT . 'scripts/mqtt_worker.php', 'handler', 'mqtt_handler_' . $i));
}
$pool->add('mqtt_state', array($php, ROOT . 'scripts/mqtt_worker.php', 'state', 'state'));
$pool->add('mqtt_publisher', array($php, ROOT . 'scripts/mqtt_worker.php', 'publisher', 'publisher'));

$lastHeartbeat = 0;
$lastRedisCheck = 0;
$lastMaintenance = 0;
$lastHealthCheck = 0;
$enabledAt = 0;

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach (array(SIGTERM, SIGINT) as $signal) {
        pcntl_signal($signal, function () use (&$pool) {
            $pool->stop();
            exit;
        });
    }
}

while (true) {
    if (function_exists('isRebootRequired') && isRebootRequired()) break;
    if (time() - $lastHeartbeat >= 5) {
        $lastHeartbeat = time();
        setGlobal('cycle_mqtt_runtimeRun', time(), 1);
    }

    $want = MqttRuntime::enabled();
    if ($redisDown && time() - $lastRedisCheck < 10) $want = false;
    if ($want && time() - $lastRedisCheck >= 10) {
        $lastRedisCheck = time();
        try {
            \MajorDoMo\Core\Core::redis()->requireConnection()->ping();
            if ($redisDown) {
                DebMes('MQTT runtime: Redis is available again', 'mqtt_runtime');
                $redisDown = false;
            }
        } catch (Throwable $error) {
            if (!$redisDown) {
                DebMes('MQTT runtime: Redis unavailable, workers paused: ' . $error->getMessage(), 'mqtt_runtime', 'warning');
                $redisDown = true;
            }
            $want = false;
        }
    }

    if ($want !== $enabled) {
        if ($want) {
            $pool->start();
            $enabledAt = time();
            DebMes('MQTT runtime started: handlers=' . $count, 'mqtt_runtime');
            echo date('Y-m-d H:i:s') . ' MQTT runtime started, handlers=' . $count . PHP_EOL;
        } else {
            $pool->stop();
            DebMes('MQTT runtime paused (async disabled or Redis unavailable)', 'mqtt_runtime');
            echo date('Y-m-d H:i:s') . ' MQTT runtime paused' . PHP_EOL;
        }
        $enabled = $want;
    }

    if ($enabled) {
        foreach ($pool->maintain() as $event) {
            DebMes('MQTT runtime: ' . $event, 'mqtt_runtime', 'warning');
            echo date('Y-m-d H:i:s') . ' MQTT runtime: ' . $event . PHP_EOL;
        }
        // Процесс может быть жив, но зависнуть в SQL/брокере. Роль без
        // heartbeat перезапускается, не дожидаясь watchdog всего цикла.
        if (time() - $lastHealthCheck >= 10 && time() - $enabledAt > 15) {
            $lastHealthCheck = time();
            foreach ($pool->pids() as $name => $pid) {
                if ($pid < 1) continue;
                if ($pool->uptime($name) < 15) continue;
                if ($name === 'mqtt_state') {
                    $alive = MqttRuntime::isAlive('state');
                } elseif ($name === 'mqtt_publisher') {
                    $alive = MqttRuntime::isAlive('publisher');
                } else {
                    $alive = MqttRuntime::isAlive($name);
                }
                if ($alive) continue;
                DebMes('MQTT runtime: ' . $name . ' has no heartbeat, restarting', 'mqtt_runtime', 'warning');
                echo date('Y-m-d H:i:s') . ' MQTT runtime: ' . $name . ' has no heartbeat, restarting' . PHP_EOL;
                $pool->restart($name);
            }
        }
        if (time() - $lastMaintenance >= 5) {
            $lastMaintenance = time();
            try {
                $queue = MqttRuntime::queue('incoming');
                $queue->promoteDelayed(200);
                $queue->reap(200);
            } catch (Throwable $error) {
                // Redis мог исчезнуть между проверками; следующий цикл решит.
            }
        }
    } else {
        sleep(5);
    }
    usleep(200000);
}

$pool->stop();
