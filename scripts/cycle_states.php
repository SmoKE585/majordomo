<?php

chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);

include_once("./load_settings.php");
include_once(DIR_MODULES . "control_modules/control_modules.class.php");

$ctl = new control_modules();

$checked_time = 0;

$last_run = getGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run');
$cycleVarName = 'ThisComputer.' . str_replace('.php', '', basename(__FILE__)) . 'Run';

$objects = getObjectsByClass('systemStates');
if (is_array($objects) || $objects instanceof Countable) {
    $total = count($objects);
    echo $total . " objects by class systemStates." . "\n";
} else {
    $total = 0;
    echo "No systemStates objects found...." . "\n";
}

$modesTick = static function (): void {
    if (!isModuleInstalled('modes')) return;
    try { \MajorDoMo\Core\Modes\ModeManager::shared()->tick(); }
    catch (Throwable $error) { DebMes($error->getMessage(), 'modes', 'error'); }
};

if (isset($_GET['once'])) {

    echo "Runing once.......";
    $modesTick();

    if ((time() - $last_run) > 5 * 60) {
        setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
        for ($i = 0; $i < $total; $i++) {
            try { callMethod($objects[$i]['TITLE'] . '.checkState'); }
            catch (Throwable $error) { DebMes('Error in checkState for ' . $objects[$i]['TITLE'] . ': ' . $error->getMessage(), 'cycle_states', 'error'); }
        }
    }

    echo "OK" . "\n";
    exit;
} else {
    echo date("H:i:s") . " running " . basename(__FILE__) . PHP_EOL;

    $objects_refreshed = time();
    while (1) {
        $modesTick();
        if (time() - $objects_refreshed >= 60) {
            $objects = getObjectsByClass('systemStates');
            if (!is_array($objects)) $objects = array();
            $total = count($objects);
            $objects_refreshed = time();
        }
        if (time() - $checked_time > 10) {
            setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
            $checked_time = time();
            // saveToCache("MJD:$cycleVarName", $checked_time);

            for ($i = 0; $i < $total; $i++) {
                try {
                    callMethod($objects[$i]['TITLE'] . '.checkState');
                } catch (Throwable $e) {
                    DebMes('Error in checkState for ' . $objects[$i]['TITLE'] . ': ' . $e->getMessage(), 'cycle_states', 'error');
                }
            }
        }

        if (isRebootRequired() || isset($_GET['onetime'])) {
            exit;
        }

        sleep(1);
    }

    DebMes("Unexpected close of cycle: " . basename(__FILE__), 'cycle_states', 'error');
}
