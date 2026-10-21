<?php
/**
 * Keenetic polling cycle.
 *
 * The heavy lifting lives in keenetic::processCycle(); this file only keeps the
 * process alive, writes the heartbeat and lets MajorDoMo stop/restart it.
 */
chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);

include_once("./load_settings.php");
include_once(DIR_MODULES . "control_modules/control_modules.class.php");
$ctl = new control_modules();

include_once(DIR_MODULES . 'keenetic/keenetic.class.php');
$keenetic_module = new keenetic();
$keenetic_module->getConfig();

// Creates missing columns after a module update.
$keenetic_module->ensureSchema();

$hasRouters = SQLSelectOne("SELECT ID FROM keenetic_routers LIMIT 1");
if (empty($hasRouters['ID'])) {
    // Nothing to poll yet; exit and let the cycle start again after a router is added.
    exit;
}

echo date("H:i:s") . " running " . basename(__FILE__) . PHP_EOL;

$lastHeartbeat = 0;
$cycleName = str_replace('.php', '', basename(__FILE__));

while (true) {
    if (time() - $lastHeartbeat > 20) {
        setGlobal($cycleName . 'Run', time(), 1);
        $lastHeartbeat = time();
    }

    try {
        $keenetic_module->processCycle();
    } catch (Throwable $e) {
        DebMes('Keenetic cycle error: ' . $e->getMessage(), 'keenetic_error');
        echo date('Y-m-d H:i:s') . ' Keenetic cycle error: ' . $e->getMessage() . PHP_EOL;
    }

    if (file_exists('./reboot') || isset($_GET['onetime'])) {
        break;
    }

    sleep(1);
}

DebMes("Unexpected close of cycle: " . basename(__FILE__));
