<?php

/**
 * Один параллельный исполнитель core_runtime. Запускается cycle_core.php.
 * В отличие от прежнего цикла, здесь нет EventBus и heartbeat: ими владеет
 * родитель, а процесс занят только очередью операций.
 *
 * 2026-10-23 — добавлено для параллельного core_runtime.
 * 2026-10-25 — штатная остановка завершает текущее задание и подготовленный процесс.
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
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach (array(SIGTERM, SIGINT) as $signal) pcntl_signal($signal, static function (): void {
        \MajorDoMo\Core\Core::processes()->stop();
        exit;
    });
}

echo date('Y-m-d H:i:s') . ' core_runtime worker started, pid=' . getmypid() . PHP_EOL;
register_shutdown_function(static function (): void { \MajorDoMo\Core\WorkerHeartbeat::remove(ROOT, getmypid()); });
$heartbeat = 0;

while (true) {
    if (isRebootRequired() || file_exists(ROOT . 'reboot')) break;
    try {
        if (time() - $heartbeat >= 5) {
            \MajorDoMo\Core\WorkerHeartbeat::touch(ROOT, getmypid());
            $heartbeat = time();
        }
        if (!\MajorDoMo\Core\Core::worker()->process()) usleep(100000);
    } catch (Throwable $error) {
        DebMes('core_runtime worker: ' . $error->getMessage(), 'cycle_core', 'error');
        sleep(1);
    }
}
