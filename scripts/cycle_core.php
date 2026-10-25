<?php

/**
 * Исполнитель очереди core_runtime.
 *
 * При CORE_WORKERS > 1 и включённом Redis работает супервизором: держит
 * несколько дочерних процессов, которые забирают операции из независимых
 * полос. Произвольный PHP (сценарии, API, события модулей) идёт в
 * эксклюзивную полосу serial, поэтому не пересекается с остальными.
 *
 * При CORE_WORKERS = 1 или без Redis сохраняется прежний последовательный
 * цикл — это аварийный и совместимый режим.
 *
 * 2026-10-25 — исполнители используют долговечную SQL-очередь, Redis для их работы не требуется.
 * 2026-10-23 — добавлен параллельный режим с порядком по сущности.
 */

echo date('Y-m-d H:i:s') . ' cycle_core starting, pid=' . getmypid() . PHP_EOL;
$cycleCoreStarted = time();
register_shutdown_function(function () use ($cycleCoreStarted) {
    $message = 'cycle_core stopped, uptime=' . (time() - $cycleCoreStarted) . 's';
    $error = error_get_last();
    if (is_array($error) && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        $message .= ', fatal=' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'];
    }
    echo date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
});

chdir(dirname(__DIR__));
require_once './config.php';
require_once './lib/loader.php';
require_once './load_settings.php';
set_time_limit(0);

$coreWorkers = max(1, min(16, \MajorDoMo\Core\Config::int('CORE_WORKERS', 3)));
$parallel = $coreWorkers > 1;

if (!$parallel) {
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach (array(SIGTERM, SIGINT) as $signal) pcntl_signal($signal, static function (): void {
            \MajorDoMo\Core\Core::processes()->stop();
            exit;
        });
    }
    $heartbeat = 0;
    $eventsDrainedAt = 0;
    while (true) {
        try {
            if (time() - $heartbeat >= 5) {
                setGlobal('cycle_coreRun', time(), 1);
                if (!$heartbeat) echo date('Y-m-d H:i:s') . ' cycle_core running (serial)' . PHP_EOL;
                $heartbeat = time();
            }
            // Deliver queued system events to module subscribers (subscribeToEvent()).
            // The queue lives in the database, so a web request that emitted the event
            // never blocks on a slow module.
            if (time() - $eventsDrainedAt >= 2) {
                $eventsDrainedAt = time();
                \MajorDoMo\Core\Events\EventBus::flushDeferred(ROOT, 20);
                \MajorDoMo\Core\Events\EventBus::drain(20);
            }
            if (!\MajorDoMo\Core\Core::worker()->process()) usleep(100000);
        } catch (Throwable $e) {
            $message = 'Core worker: ' . $e->getMessage();
            echo date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
            DebMes($message, 'cycle_core', 'error');
            sleep(1);
        }
        if (isRebootRequired() || isset($_GET['onetime'])) break;
    }
    exit(0);
}

require_once __DIR__ . '/../lib/core/ProcessPool.php';

$logDirectory = \MajorDoMo\Core\Config::string('LOG_DIRECTORY');
if ($logDirectory === '') $logDirectory = ROOT . 'cms/debmes';
$pool = new \MajorDoMo\Core\ProcessPool($logDirectory);
$php = defined('PATH_TO_PHP') && is_string(PATH_TO_PHP) && PATH_TO_PHP !== '' ? PATH_TO_PHP : PHP_BINARY;
for ($i = 1; $i <= $coreWorkers; $i++) {
    $pool->add('core_worker_' . $i, array($php, ROOT . 'scripts/core_runtime_worker.php'));
}

register_shutdown_function(function () use ($pool) {
    $pool->stop();
});

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach (array(SIGTERM, SIGINT) as $signal) {
        pcntl_signal($signal, function () use ($pool) {
            $pool->stop();
            exit;
        });
    }
}

$pool->start();
echo date('Y-m-d H:i:s') . ' cycle_core running (parallel workers=' . $coreWorkers . ')' . PHP_EOL;

$heartbeat = 0;
$eventsDrainedAt = 0;
$migratedAt = 0;
$lanesMaintainedAt = 0;
$workersCheckedAt = 0;
while (true) {
    try {
        if (time() - $heartbeat >= 5) {
            setGlobal('cycle_coreRun', time(), 1);
            $heartbeat = time();
        }
        if (time() - $eventsDrainedAt >= 2) {
            $eventsDrainedAt = time();
            \MajorDoMo\Core\Events\EventBus::flushDeferred(ROOT, 20);
            \MajorDoMo\Core\Events\EventBus::drain(20);
        }
        if (\MajorDoMo\Core\Config::get('USE_REDIS') && time() - $lanesMaintainedAt >= 5) {
            $lanesMaintainedAt = time();
            try {
                $lanes = \MajorDoMo\Core\Core::coreLanes();
                $lanes->promoteDelayed(200);
                $lanes->reap(200, 120);
            } catch (Throwable $error) {
                DebMes('Core lane maintenance: ' . $error->getMessage(), 'cycle_core', 'warning');
            }
        }
        foreach ($pool->maintain() as $event) {
            DebMes('Core worker pool: ' . $event, 'cycle_core', 'warning');
            echo date('Y-m-d H:i:s') . ' Core worker pool: ' . $event . PHP_EOL;
        }
        if (time() - $workersCheckedAt >= 5) {
            $workersCheckedAt = time();
            $timeout = max(120, (defined('CORE_OPERATION_TIMEOUT') ? (int)CORE_OPERATION_TIMEOUT : 60) + 65);
            foreach ($pool->pids() as $name => $pid) {
                if ($pid > 0 && \MajorDoMo\Core\WorkerHeartbeat::stalled(ROOT, $pid, $pool->uptime($name), $timeout)) {
                    $pool->restart($name);
                    \MajorDoMo\Core\WorkerHeartbeat::remove(ROOT, $pid);
                    DebMes('Контроллер ' . $name . ' перезапущен: нет прогресса ' . $timeout . ' секунд', 'cycle_core', 'error');
                }
            }
        }
    } catch (Throwable $e) {
        $message = 'Core supervisor: ' . $e->getMessage();
        echo date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
        DebMes($message, 'cycle_core', 'error');
        sleep(1);
    }
    if (isRebootRequired() || isset($_GET['onetime'])) break;
    usleep(100000);
}

$pool->stop();
