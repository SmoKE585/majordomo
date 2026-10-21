<?php

chdir(dirname(__DIR__));
require_once './config.php';
require_once './lib/loader.php';
require_once './load_settings.php';
set_time_limit(0);
$heartbeat = 0;
while (true) {
    try {
        if (time() - $heartbeat >= 5) {
            setGlobal('cycle_coreRun', time(), 1);
            $heartbeat = time();
        }
        if (!\MajorDoMo\Core\Core::worker()->process()) usleep(100000);
    } catch (Throwable $e) {
        DebMes('Core worker: ' . $e->getMessage(), 'core_runtime');
        sleep(1);
    }
    if (isRebootRequired() || isset($_GET['onetime'])) break;
}
