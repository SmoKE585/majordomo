<?php

/**
 * Один параллельный исполнитель core_runtime. Запускается cycle_core.php.
 * В отличие от прежнего цикла, здесь нет EventBus и heartbeat: ими владеет
 * родитель, а процесс занят только очередью операций.
 *
 * 2026-10-23 — добавлено для параллельного core_runtime.
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

echo date('Y-m-d H:i:s') . ' core_runtime worker started, pid=' . getmypid() . PHP_EOL;

while (true) {
    if (isRebootRequired() || file_exists(ROOT . 'reboot')) break;
    try {
        if (!\MajorDoMo\Core\Core::worker()->process()) usleep(100000);
    } catch (Throwable $error) {
        DebMes('core_runtime worker: ' . $error->getMessage(), 'cycle_core', 'error');
        sleep(1);
    }
}
