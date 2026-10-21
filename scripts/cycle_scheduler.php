<?php

chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);

include_once("./load_settings.php");
include_once(DIR_MODULES . "control_modules/control_modules.class.php");

include_once(DIR_MODULES . 'scripts/scripts.class.php');

$ctl = new control_modules();
$sc = new scripts();
$checked_time = 0;
$heartbeat = static function () use (&$checked_time) {
    if (time() - $checked_time >= 5) {
        setGlobal('cycle_schedulerRun', time(), 1);
        $checked_time = time();
    }
};

setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
$cycleVarName = 'ThisComputer.' . str_replace('.php', '', basename(__FILE__)) . 'Run';

echo date("H:i:s") . " running " . basename(__FILE__) . PHP_EOL;

while (1) {
    $heartbeat();
    try { runScheduledJobs($heartbeat); } catch (Throwable $e) { DebMes('Error in runScheduledJobs: ' . $e->getMessage(), 'cycle_scheduler', 'error'); }
    try { $sc->checkScheduledScripts(); } catch (Throwable $e) { DebMes('Error in checkScheduledScripts: ' . $e->getMessage(), 'cycle_scheduler', 'error'); }
    if (isRebootRequired() || isset($_GET['onetime'])) {
        exit;
    }
    sleep(1);
}

DebMes("Unexpected close of cycle: " . basename(__FILE__), 'cycle_scheduler', 'error');
