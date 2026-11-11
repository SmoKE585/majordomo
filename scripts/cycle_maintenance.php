<?php

// 2026-11-11 — планировщик обслуживания живёт отдельно от main и обновляет heartbeat во время дочерних заданий.
chdir(dirname(__DIR__));
require_once './config.php';
require_once './lib/loader.php';
require_once './load_settings.php';

set_time_limit(0);
$stopping = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stopping): void { $stopping = true; });
}
$checked = 0;
$maintenanceCycleName = $maintenanceCycleName ?? 'cycle_maintenance';
$heartbeat = static function () use (&$checked, &$stopping, $maintenanceCycleName): void {
    if ($stopping || isRebootRequired()) throw new RuntimeException('Обслуживание прервано для перезапуска');
    if (time() - $checked >= 5) {
        \MajorDoMo\Core\Core::cache()->saveCycle($maintenanceCycleName . 'Run', (string)time());
        $checked = time();
    }
};
$maintenance = new \MajorDoMo\Core\Maintenance\AutomaticMaintenance(ROOT);
while (true) {
    try {
        $heartbeat();
        $maintenance->runDue($heartbeat);
    } catch (Throwable $error) {
        DebMes('Автообслуживание: ' . $error->getMessage(), 'maintenance', 'error');
    }
    if ($stopping || isRebootRequired() || isset($_GET['onetime'])) break;
    sleep(5);
}
