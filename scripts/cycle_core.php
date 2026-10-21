<?php

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
$heartbeat = 0;
while (true) {
    try {
        if (time() - $heartbeat >= 5) {
            setGlobal('cycle_coreRun', time(), 1);
            if (!$heartbeat) echo date('Y-m-d H:i:s') . ' cycle_core running' . PHP_EOL;
            $heartbeat = time();
        }
        if (!\MajorDoMo\Core\Core::worker()->process()) usleep(100000);
    } catch (Throwable $e) {
        $message = 'Core worker: ' . $e->getMessage();
        echo date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
        DebMes($message, 'cycle_core');
        sleep(1);
    }
    if (isRebootRequired() || isset($_GET['onetime'])) break;
}
