<?php
/**
 * Timer Cycle script
 *
 * @package MajorDoMo
 * @author Serge Dzheigalo <sergejey@gmail.com> https://majordomohome.com/
 * @version 1.4
 */

chdir(dirname(__FILE__));

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

DebMes('Main cycle starting, pid=' . getmypid() . ', root=' . ROOT, 'boot');

$mainCycleStarted = time();
$mainCycleShutdownExpected = false;
$threads = null;

register_shutdown_function(function () use (&$threads, &$mainCycleStarted, &$mainCycleShutdownExpected) {
    $error = error_get_last();
    $reason = $mainCycleShutdownExpected ? 'expected' : 'unexpected';
    $details = 'Main cycle shutdown, pid=' . getmypid()
        . ', reason=' . $reason
        . ', uptime=' . (time() - (int)$mainCycleStarted) . 's';
    if (isset($threads) && is_object($threads) && isset($threads->handles)) {
        $details .= ', active_threads=' . count($threads->handles);
    }
    if (is_array($error) && isset($error['type'])) {
        $details .= ', last_error=' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'];
    }
    DebMes($details, 'boot');
});

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach (array(SIGTERM, SIGINT, SIGHUP) as $mainCycleSignal) {
        pcntl_signal($mainCycleSignal, function ($signal) use (&$mainCycleShutdownExpected) {
            $mainCycleShutdownExpected = true;
            DebMes('Main cycle got signal ' . (int)$signal . ', pid=' . getmypid(), 'boot');
            exit;
        });
    }
}

function buildCycleStopReason($cycleTitle, $closedThread, $exitCode = null, $termSig = null, $stopRequested = false, $restartRequested = false, $lastError = '')
{
    $reasons = array();
    if ($stopRequested && $restartRequested) {
        $reasons[] = LANG_CYCLE_STOP_REASON_RESTART_COMMAND;
    } elseif ($stopRequested) {
        $reasons[] = LANG_CYCLE_STOP_REASON_STOP_COMMAND;
    } elseif ($restartRequested) {
        $reasons[] = LANG_CYCLE_STOP_REASON_PRE_RESTART;
    } elseif ($lastError != '') {
        $reasons[] = sprintf(LANG_CYCLE_STOP_REASON_CRASH_ERROR, $lastError);
    } elseif ((int)$termSig > 0) {
        $reasons[] = sprintf(LANG_CYCLE_STOP_REASON_SIGNAL, (int)$termSig);
    } elseif ($exitCode !== null && (int)$exitCode !== 0 && (int)$exitCode !== -1) {
        $reasons[] = sprintf(LANG_CYCLE_STOP_REASON_CRASH_EXIT_CODE, (int)$exitCode);
    } elseif ($exitCode !== null && (int)$exitCode === 0) {
        $reasons[] = LANG_CYCLE_STOP_REASON_UNEXPECTED_EXIT_ZERO;
    } else {
        $reasons[] = LANG_CYCLE_STOP_REASON_UNEXPECTED_UNKNOWN;
    }

    $details = sprintf(LANG_CYCLE_STOP_DETAILS_HEADER, $cycleTitle, implode(', ', $reasons));
    if ($exitCode !== null) {
        $details .= "\nExit code: " . (int)$exitCode . '.';
    }
    if ($termSig !== null && (int)$termSig > 0) {
        $details .= "\nTerm signal: " . (int)$termSig . '.';
    }
    if ($lastError != '') {
        $details .= "\n" . sprintf(LANG_CYCLE_STOP_DETAILS_LAST_ERROR, $lastError);
    }
    $details .= "\n" . sprintf(LANG_CYCLE_STOP_DETAILS_COMMAND, $closedThread);

    return $details;
}

function setCycleRuntimeStatus($cycleTitle, $status, $details = '')
{
    saveCycleToCache($cycleTitle . 'Status', $status);
    saveCycleToCache($cycleTitle . 'StatusUpdated', time());
    saveCycleToCache($cycleTitle . 'StatusDetails', substr((string)$details, 0, 240));
}

function addCycleRuntimeLog($cycleTitle, $message)
{
    $message = trim((string)$message);
    if ($message == '') {
        return;
    }
    SQLExec('CREATE TABLE IF NOT EXISTS `cached_cycle_logs` (`ID` int(10) unsigned NOT NULL AUTO_INCREMENT,`CYCLE` char(100) NOT NULL,`ADDED` int(10) unsigned NOT NULL,`MESSAGE` varchar(1024) NOT NULL,PRIMARY KEY (`ID`),KEY `CYCLE_ADDED` (`CYCLE`,`ADDED`)) ENGINE=MEMORY DEFAULT CHARSET=utf8;');
    $lines = preg_split('/\r\n|\r|\n/', $message);
    $total = count($lines);
    for ($i = 0; $i < $total; $i++) {
        $line = trim($lines[$i]);
        if ($line == '') {
            continue;
        }
        $rec = array(
            'CYCLE' => $cycleTitle,
            'ADDED' => time(),
            'MESSAGE' => substr($line, 0, 1000),
        );
        SQLInsert('cached_cycle_logs', $rec);
    }
    $oldLogs = SQLSelect("SELECT ID FROM cached_cycle_logs WHERE CYCLE='" . DBSafe($cycleTitle) . "' ORDER BY ID DESC LIMIT 80, 1000");
    $totalOldLogs = count($oldLogs);
    for ($i = 0; $i < $totalOldLogs; $i++) {
        SQLExec('DELETE FROM cached_cycle_logs WHERE ID=' . (int)$oldLogs[$i]['ID']);
    }
}

function startCycleThread($threads, $title, $cmd)
{
    if (!file_exists($cmd)) {
        $message = 'Cycle file not found: ' . $cmd;
        DebMes($message, 'boot');
        setCycleRuntimeStatus($title, 'stopped', $message);
        addCycleRuntimeLog($title, $message);
        setGlobal($title . 'Run', '');
        return false;
    }

    $pipeId = $threads->newThread($cmd);
    if (!$pipeId) {
        $message = 'Failed to start cycle process: ' . $cmd;
        DebMes($message, 'boot');
        setCycleRuntimeStatus($title, 'stopped', $message);
        addCycleRuntimeLog($title, $message);
        setGlobal($title . 'Run', '');
        return false;
    }

    return $pipeId;
}

resetRebootRequired();

set_time_limit(0);

$db_filename = ROOT . 'database_backup/db.sql';
if ((!file_exists($db_filename) || filesize($db_filename) == 0) && file_exists($db_filename . '.prev') && filesize($db_filename . '.prev') > 0) {
    DebMes("Main backup file is empty, restoring from previous: " . $db_filename . '.prev', 'boot');
    $db_filename = $db_filename . '.prev';
}

$db_history_filename = ROOT . 'database_backup/db_history.sql';
if ((!file_exists($db_history_filename) || filesize($db_history_filename) == 0) && file_exists($db_history_filename . '.prev') && filesize($db_history_filename . '.prev') > 0) {
    DebMes("Main history backup file is empty, restoring from previous: " . $db_history_filename . '.prev', 'boot');
    $db_history_filename = $db_history_filename . '.prev';
}

$connected = false;
$total_restarts = 0;
while (!$connected) {
    echo "Connecting to database..." . PHP_EOL;
    $connected = $db->Connect();
    if (!$connected) {
        if (file_exists($db_filename) && !IsWindowsOS() && $total_restarts < 3) {
            echo "Restarting mysql service..." . PHP_EOL;
            DebMes('Restarting mysql service...', 'boot');
            exec("sudo service mysql restart"); // trying to restart mysql
            $total_restarts++;
            sleep(10);
        } else {
            sleep(5);
        }
    }
}


echo "CONNECTED TO DB" . PHP_EOL;

//restoring database backup (if exists)
if (file_exists($db_filename) && filesize($db_filename) > 0) {
    echo "Running: mysql main db restore from file: " . $db_filename . PHP_EOL;
    DebMes("Running: mysql main db restore from file: " . $db_filename, 'boot');
    $mysql_path = (substr(php_uname(), 0, 7) == "Windows") ? SERVER_ROOT . "/server/mysql/bin/mysql" : 'mysql';
    $mysqlParam = " -h " . DB_HOST;
    $mysqlParam .= " -u " . DB_USER;
    if (DB_PASSWORD != '') $mysqlParam .= " --password=\"" . DB_PASSWORD . "\"";
    $mysqlParam .= " " . DB_NAME . " <" . $db_filename;
    exec($mysql_path . $mysqlParam, $output);
    echo "Output: " . implode("\n", $output) . PHP_EOL;
    DebMes("Main DB restore output: " . implode("\n", $output), 'boot');

    if (file_exists($db_history_filename) && filesize($db_history_filename) > 0) {
        echo "Running: mysql history db restore from file: " . $db_history_filename . PHP_EOL;
        DebMes("Running: mysql history db restore from file: " . $db_history_filename, 'boot');
        $mysql_path = (substr(php_uname(), 0, 7) == "Windows") ? SERVER_ROOT . "/server/mysql/bin/mysql" : 'mysql';
        $mysqlParam = " -h " . DB_HOST;
        $mysqlParam .= " -u " . DB_USER;
        if (DB_PASSWORD != '') $mysqlParam .= " --password=\"" . DB_PASSWORD . "\"";
        $mysqlParam .= " " . DB_NAME . " <" . $db_history_filename;
        exec($mysql_path . $mysqlParam);
    } else {
        echo "History backup file not found or invalid: " . $db_history_filename . PHP_EOL;
        DebMes("History backup file not found or invalid: " . $db_history_filename, 'boot');
    }
} else {
    echo "Backup file not found or invalid: " . $db_filename . PHP_EOL;
    DebMes("Backup file not found or invalid: " . $db_filename, 'boot');
}

//если есть "поломанные" таблицы, попытаться их "вылечить"
echo "CHECK/REPAIR TABLES\n";
$tables = SQLSelect("select TABLE_NAME Tbl from information_schema.tables where TABLE_SCHEMA='" . DB_NAME . "' AND ENGINE !='MEMORY';");
$total = count($tables);
$checked = 0;
$broken = 0;
$repaired = 0;
$fatal = 0;
for ($i = 0; $i < $total; $i++) {
    $table = $tables[$i]['Tbl'];

    //echo 'Checking table [' . $table . '] ...';
    $result = SQLSelectOne("CHECK TABLE " . $table . ";");
    if ($result['Msg_text'] == 'OK') {
        //echo "OK\n";
        $checked = $checked + 1;
    } else {
        echo "Checking table [" . $table . "]... broken ... try to repair ...";
        DebMes("Checking table [" . $table . "]... broken (" . $result['Msg_text'] . ")... try to repair ...", 'boot');
        $broken = $broken + 1;
        SQLExec("REPAIR TABLE " . $table . ";");
        sleep(10);
        $result = SQLSelectOne("CHECK TABLE " . $table . ";");
        if ($result['Msg_text'] == 'OK') {
            echo "OK\n";
            DebMes("$table repaired OK", 'boot');
            $repaired = $repaired + 1;
        } else {
            DebMes("Repair failed (" . $result['Msg_text'] . "), trying extended repair...", 'boot');
            echo "try to repair extended...";
            SQLExec("REPAIR TABLE " . $table . " EXTENDED;");
            sleep(10);
            $result = SQLSelectOne("CHECK TABLE " . $table . ";");
            if ($result['Msg_text'] == 'OK') {
                DebMes("$table repaired OK", 'boot');
                echo "OK\n";
                $repaired = $repaired + 1;
            } else {
                DebMes("Repair failed (" . $result['Msg_text'] . "), trying repair using frm...", 'boot');
                echo "try to repair use_frm...";
                SQLExec("REPAIR TABLE " . $table . " USE_FRM;");
                sleep(5);
                $result = SQLSelectOne("CHECK TABLE " . $table . ";");
                if ($result['Msg_text'] == 'OK') {
                    DebMes("$table repaired OK", 'boot');
                    echo "OK\n";
                    $repaired = $repaired + 1;
                } else {
                    DebMes("Repair of $table failed!", 'boot');
                    echo "NO RESULT(...try restore from backup\n";
                    $fatal = $fatal + 1;
                }
            }
        }
    }
}
echo "CHECK/REPAIR TABLES RESULT -> Total: " . $total . ", checked Ok: " . $checked . ", broken: " . $broken . ", repaired: " . $repaired . ", FATAL Errors : " . $fatal;
echo "\n";

Debmes("CHECK/REPAIR TABLES RESULT -> Total: " . $total . ", checked Ok: " . $checked . ", broken: " . $broken . ", repaired: " . $repaired . ", FATAL Errors : " . $fatal, 'boot');

DebMes("Loading settings...", 'boot');
include_once("./load_settings.php");
DebMes("Settings loaded.", 'boot');

// создаем табличку cyclesRun, если её нет
SQLExec('CREATE TABLE IF NOT EXISTS `cached_cycles` (`TITLE` char(100) NOT NULL,`VALUE` char(255) NOT NULL,PRIMARY KEY (`TITLE`)) ENGINE=MEMORY DEFAULT CHARSET=utf8;');
SQLExec('CREATE TABLE IF NOT EXISTS `cached_cycle_logs` (`ID` int(10) unsigned NOT NULL AUTO_INCREMENT,`CYCLE` char(100) NOT NULL,`ADDED` int(10) unsigned NOT NULL,`MESSAGE` varchar(1024) NOT NULL,PRIMARY KEY (`ID`),KEY `CYCLE_ADDED` (`CYCLE`,`ADDED`)) ENGINE=MEMORY DEFAULT CHARSET=utf8;');
SQLExec('DELETE FROM cached_cycle_logs');
SQLExec('DROP TABLE IF EXISTS cyclesRun;');


$old_mask = umask(0);
if (is_dir(ROOT . 'cached')) {
    DebMes("Removing cache from " . ROOT . 'cached', 'boot');
    removeTree(ROOT . 'cached');
}
if (is_dir(ROOT . 'cms/cached')) {
    DebMes("Removing cache from " . ROOT . 'cms/cached', 'boot');
    removeTree(ROOT . 'cms/cached');
}

// moving some folders to ./cms/
$move_folders = array(
    'debmes',
    'saverestore',
    'sounds',
    'texts');
foreach ($move_folders as $folder) {
    if (is_dir(ROOT . $folder)) {
        echo "Moving " . ROOT . $folder . ' to ' . ROOT . 'cms/' . $folder . "\n";
        DebMes('Moving ' . ROOT . $folder . ' to ' . ROOT . 'cms/' . $folder, 'boot');
        copyTree(ROOT . $folder, ROOT . 'cms/' . $folder);
        removeTree(ROOT . $folder);
    }
}

// removing some 3rd-party directories
$check_folders = array(
    'blockly' => '3rdparty/blockly',
    'bootstrap' => '3rdparty/bootstrap',
    'js/codemirror' => '3rdparty/codemirror',
    'freeboard' => '3rdparty/freeboard',
    'jquerymobile' => '3rdparty/jquerymobile',
    'jpgraph' => '3rdparty/jpgraph',
    'js/threejs' => '3rdparty/threejs',
    'pdw' => '3rdparty',
    '3rdparty/pdw' => '3rdparty',
);
foreach ($check_folders as $k => $v) {
    if (is_dir(ROOT . $v) && is_dir(ROOT . $k)) {
        echo "Removing " . ROOT . $k . "\n";
        DebMes('Removing ' . ROOT . $k, 'boot');
        removeTree(ROOT . $k);
    }
}


// check/recreate folders
$dirs_to_check = array(
    ROOT . 'backup',
    ROOT . 'cms/debmes',
    ROOT . 'cms/cached',
    ROOT . 'cms/cached/voice',
    ROOT . 'cms/cached/urls',
    ROOT . 'cms/cached/templates_c',
);

if (defined('SETTINGS_SYSTEM_DEBMES_PATH') && SETTINGS_SYSTEM_DEBMES_PATH != '') {
    $path = SETTINGS_SYSTEM_DEBMES_PATH;
} elseif (defined('LOG_DIRECTORY') && LOG_DIRECTORY != '') {
    $path = LOG_DIRECTORY;
} else {
    $path = ROOT . 'cms/debmes';
}
$dirs_to_check[] = $path;

if (defined('SETTINGS_BACKUP_PATH') && SETTINGS_BACKUP_PATH != '') {
    $dirs_to_check[] = SETTINGS_BACKUP_PATH;
}


foreach ($dirs_to_check as $d) {
    if (!is_dir($d)) {
        DebMes("Creating dir " . $d, 'boot');
        mkdir($d, 0777);
    } else {
        chmod($d, 0777);
    }
}


echo "Checking modules.\n";

//force check installed data
$source = ROOT . 'modules';
if ($dir = @opendir($source)) {
    while (($file = readdir($dir)) !== false) {
        $installed_file = ROOT . "cms/modules_installed/" . $file . ".installed";
        if (Is_Dir($source . "/" . $file) && ($file != '.') && ($file != '..') && is_file($installed_file)) {
            DebMes("Re-installing module: " . $file, 'boot');
            unlink($installed_file);
        }
    }
}

if (is_file(ROOT . "cms/modules_installed/control_modules.installed")) {
    DebMes("Re-installing control_modules: " . $file, 'boot');
    unlink(ROOT . "cms/modules_installed/control_modules.installed");
}

// continue startup
include_once(DIR_MODULES . "control_modules/control_modules.class.php");
$ctl = new control_modules();


//removing cached data
echo "Clearing the cache.\n";
DebMes("Clearing the cache.", 'boot');
clearCacheData();

if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
    // split data into multiple tables
    $phistory_values = SQLSelect("SELECT VALUE_ID, COUNT(*) AS TOTAL FROM phistory GROUP BY VALUE_ID");
    $total = count($phistory_values);
    for ($i = 0; $i < $total; $i++) {
        $value_id = $phistory_values[$i]['VALUE_ID'];
        $total_data = $phistory_values[$i]['TOTAL'];
        DebMes("Processing data for value $value_id ($total_data) ... ", 'boot');
        echo "Processing data for value $value_id ($total_data) ... ";
        $table_name = createHistoryTable($value_id);
        moveDataFromMainHistoryToTable($value_id);
        DebMes("Processing of $value_id finished.", 'boot');
        echo "OK\n";
    }
} else {
    //combine data into single table
    $data = SQLSelect("SHOW TABLES;");
    $tables = array();
    foreach ($data as $v) {
        foreach ($v as $k => $v2) {
            $tables[] = $v2;
        }
    }
    foreach ($tables as $table) {
        if (preg_match('/phistory_value_(\d+)/', $table, $m)) {
            $value_id = $m[1];
            echo "Processing table: $table ($value_id) ...\n";
            DebMes("Processing data for value $value_id ($table) ... ", 'boot');
            moveDataFromTableToMainHistory($value_id);
            DebMes("Processing of $value_id finished.", 'boot');
            echo "OK\n";
        }
    }
}

// Removing cycles properties
$qry = "1 AND (TITLE LIKE 'cycle%Run' OR TITLE LIKE 'cycle%Control' OR TITLE LIKE 'cycle%Disabled' OR TITLE LIKE 'cycle%AutoRestart' OR TITLE LIKE 'cycle%Status' OR TITLE LIKE 'cycle%StatusUpdated' OR TITLE LIKE 'cycle%StatusDetails')";
$thisCompObject = getObject('ThisComputer');
$cycles_records = SQLSelect("SELECT properties.* FROM properties WHERE $qry ORDER BY TITLE");
SQLExec("DELETE FROM cached_cycles");

$total = count($cycles_records);
for ($i = 0; $i < $total; $i++) {
    $property = $cycles_records[$i]['TITLE'];
    echo "Removing ThisComputer.$property (object " . $thisCompObject->id . ")";
    DebMes("Removing property ThisComputer.$property (object " . $thisCompObject->id . ")", 'boot');
    $property_id = $thisCompObject->getPropertyByName($property, $thisCompObject->class_id, $thisCompObject->id);
    if ($property_id) {
        $sqlQuery = "SELECT ID FROM pvalues WHERE PROPERTY_ID = " . (int)$property_id;
        $pvalue = SQLSelectOne($sqlQuery);
        if (!empty($pvalue['ID'])) {
            DebMes("Deleting Pvalue: " . $pvalue['ID'], 'boot');
            cleanUpValueHistory($pvalue['ID'], 0);
            SQLExec("DELETE FROM pvalues WHERE ID=" . $pvalue['ID']);
        } else {
            DebMes("NO Pvalue for " . $property_id, 'boot');
        }
        SQLExec("DELETE FROM properties WHERE ID=" . $property_id);
        DebMes("REMOVED $property_id", 'boot');
        echo " REMOVED $property_id\n";
    } else {
        DebMes("No property record found for $property", 'boot');
        echo " FAILED\n";
    }
}
clearCacheData();

// getting list of /scripts/cycle_*.php files to run each in separate thread
$cycles = array();
$reboot_timer = 0;

if (is_dir("./scripts")) {

    if (file_exists('./scripts/cycle_periodical_db_save.php') && file_exists('./scripts/periodical_db_save.php')) {
        copyFile('./scripts/periodical_db_save.php', './scripts/cycle_periodical_db_save.php');
    }
    if (file_exists('./scripts/cycle_db_save.php') && file_exists('./scripts/periodical_db_save.php')) {
        copyFile('./scripts/periodical_db_save.php', './scripts/cycle_db_save.php');
    }

    if ($lib_dir = opendir("./scripts")) {
        while (($lib_file = readdir($lib_dir)) !== false) {
            if ((preg_match("/^cycle_.+?\.php$/", $lib_file)))
                $cycles[] = './scripts/' . $lib_file;
        }
        closedir($lib_dir);
    }
}

$threads = new Threads;

if (defined('PATH_TO_PHP'))
    $threads->phpPath = PATH_TO_PHP;
else
    $threads->phpPath = IsWindowsOS() ? '..\server\php\php.exe' : 'php';

foreach ($cycles as $path) {

    if (file_exists($path)) {

        if (preg_match('/(cycle_.+?)\.php/is', $path, $m)) {
            $title = $m[1];
            if (getGlobal($title . 'Disabled')) {
                DebMes("Cycle " . $title . " disabled. Skipping.", 'boot');
                continue;
            }
            if (getGlobal($title . 'Control') != '') {
                setGlobal($title . 'Control', '');
            }
        }


        DebMes("Starting " . $path . " ... ", 'boot');
        setCycleRuntimeStatus($title, 'starting');
        addCycleRuntimeLog($title, 'Starting ' . $path);
        echo "Starting " . $path . " ... \n";

        $pipe_id = false;
        if ((preg_match("/_X/", $path))) {
            if (!IsWindowsOS()) {
                $display = '101';
                if ((preg_match("/_X(.+)_/", $path, $displays))) {
                    if (count($displays) > 1) {
                        $display = $displays[1];
                    }
                }
                $pipe_id = $threads->newXThread($path, $display);
            } else {
                setCycleRuntimeStatus($title, 'stopped', 'X display cycles are not supported on Windows');
                addCycleRuntimeLog($title, 'X display cycles are not supported on Windows');
            }
        } else {
            $pipe_id = startCycleThread($threads, $title, $path);
        }
        if ($pipe_id && isset($title)) {
            saveCycleToCache($title . 'LastError', '');
            setCycleRuntimeStatus($title, 'starting');
            $pipes[$pipe_id] = $path;
            echo "OK" . PHP_EOL;
        } else {
            echo "FAILED" . PHP_EOL;
        }
    }
}

DebMes("ALL CYCLES STARTED", 'boot');
echo "ALL CYCLES STARTED" . PHP_EOL;

$last_restart = array();

$last_cycles_control_check = time();

$auto_restarts = array();
$to_start = array();
$to_stop = array();
$started_when = array();
$is_running = array();
$last_no_threads_log = 0;

$thisComputerObject = getObject('Computer.ThisComputer');

while (true) {
    $result = $threads->iteration();
    if ($result === false) {
        if ((time() - $last_no_threads_log) >= 30) {
            DebMes('No active cycle threads left. Scheduling all enabled cycles for restart.', 'boot');
            addCycleRuntimeLog('cycle_controller', 'No active cycle threads left. Scheduling all enabled cycles for restart.');
            $last_no_threads_log = time();
        }
        $is_running = array();
        foreach ($cycles as $path) {
            if (!preg_match('/(cycle_.+?)\.php/is', $path, $m)) {
                continue;
            }
            $title = $m[1];
            if (getGlobal($title . 'Disabled')) {
                continue;
            }
            if (!isset($to_start[$title])) {
                $to_start[$title] = time() + 2;
                setCycleRuntimeStatus($title, 'starting', 'Scheduled after controller detected no active threads');
                addCycleRuntimeLog($title, 'Scheduled after controller detected no active threads');
            }
        }
        $result = '';
        sleep(1);
    }

    if ((time() - $last_cycles_control_check) >= 5 || !empty($result)) {

        $last_cycles_control_check = time();
        $cyclesControls = $cyclesTimestamps = array();
        $tmpcyclesTimestamps = SQLSelect("SELECT * FROM cached_cycles;");

        $total = count($tmpcyclesTimestamps);
        foreach ($tmpcyclesTimestamps as $k => $v) {
            if (strpos($v['TITLE'], 'Run') !== FALSE) {
                $cyclesTimestamps[$v['TITLE']] = $v['VALUE'];
            } else if (strpos($v['TITLE'], 'Control') !== FALSE) {
                $cyclesControls[$v['TITLE']] = $v['VALUE'];
            }
        }

        $seen = array();
        for ($i = 0; $i < $total; $i++) {
            $title = $tmpcyclesTimestamps[$i]['TITLE'];
            $title = preg_replace('/Run$/', '', $title);
            $title = preg_replace('/Control$/', '', $title);
            if (isset($seen[$title])) {
                continue;
            }
            $seen[$title] = 1;
            $control = '';

            if (isset($cyclesControls[$title . 'Control'])) $control = $cyclesControls[$title . 'Control'];
            if ($control != '') {
                DebMes("Got control command '$control' for " . $title, 'boot');
                addCycleRuntimeLog($title, "Got control command: " . $control);
                if ($control == 'stop') {
                    $to_stop[$title] = time();
                    setCycleRuntimeStatus($title, 'stopping');
                    // Explicit stop must cancel any delayed start/restart requests.
                    unset($to_start[$title]);
                    $key = array_search($title, $auto_restarts);
                    if ($key !== false) {
                        unset($auto_restarts[$key]);
                        $auto_restarts = array_values($auto_restarts);
                    }
                } elseif ($control == 'start') {
                    if (!isset($is_running[$title])) {
                        $to_start[$title] = time() + 2;
                        setCycleRuntimeStatus($title, 'starting');
                    } else {
                        setCycleRuntimeStatus($title, 'running');
                    }
                } elseif ($control == 'restart') {
                    $to_stop[$title] = time();
                    $to_start[$title] = time() + 30;
                    setCycleRuntimeStatus($title, isset($is_running[$title]) ? 'stopping' : 'starting');
                }
                setGlobal($title . 'Control', '');
            }

        }

        $is_running = array();

        foreach ($threads->commandLines as $id => $cmd) {
            if (preg_match('/(cycle_.+?)\.php/is', $cmd, $m)) {
                $title = $m[1];
                $is_running[$title] = $id;
                if (!isset($started_when[$title])) $started_when[$title] = time();
                $cycle_updated_timestamp = $cyclesTimestamps[$title . 'Run'] ?? null;
                if (isset($to_stop[$title])) {
                    setCycleRuntimeStatus($title, 'stopping');
                } elseif ($cycle_updated_timestamp && ((time() - (int)$cycle_updated_timestamp) <= 10 * 60)) {
                    setCycleRuntimeStatus($title, 'running');
                } elseif ((time() - $started_when[$title]) > 10 * 60) {
                    setCycleRuntimeStatus($title, 'hang');
                } else {
                    setCycleRuntimeStatus($title, 'starting');
                }
                if ((time() - $started_when[$title]) > 30 && !in_array($title, $auto_restarts)) {
                    DebMes("Adding $title to auto-recovery list", 'boot');
                    $auto_restarts[] = $title;
                }

                if (!isset($to_start[$title]) && $cycle_updated_timestamp && in_array($title, $auto_restarts) && ((time() - $cycle_updated_timestamp) > 30 * 60)) { //
                    DebMes("Looks like $title is dead (updated: " . date('Y-m-d H:i:s', $cycle_updated_timestamp) . "). Need to recovery", 'boot');
                    setCycleRuntimeStatus($title, 'hang', 'Last update: ' . date('Y-m-d H:i:s', $cycle_updated_timestamp));
                    addCycleRuntimeLog($title, 'Detected hang, requesting restart');
                    registerError('cycle_hang', $title);
                    setGlobal($title . 'Control', 'restart');
                }
            }
        }
    }


    if (isRebootRequired()) {
        if (!$reboot_timer) {
            $reboot_timer = time();
        } elseif ((time() - $reboot_timer) > 10) {
            $reboot_reason = trim((string)@file_get_contents(ROOT . 'reboot'));
            if ($reboot_reason == 'system_update') {
                DebMes("Reboot flag is set for system update, keeping services stopped until update completes.", 'boot');
                $reboot_timer = time();
            } else {
                $reboot_timer = 0;
                // force close all running threads
                DebMes("Force closing all running services. Reboot reason: " . $reboot_reason, 'boot');
                $to_start = array();
                foreach ($is_running as $k => $v) {
                    $to_stop[$k] = time();
                }
                resetRebootRequired();
                foreach ($cycles as $path) {
                    if (!preg_match('/(cycle_.+?)\.php/is', $path, $m)) {
                        continue;
                    }
                    $title = $m[1];
                    if (getGlobal($title . 'Disabled')) {
                        continue;
                    }
                    $to_start[$title] = time() + 5;
                    setCycleRuntimeStatus($title, 'starting', 'Scheduled after reboot flag reset');
                    addCycleRuntimeLog($title, 'Scheduled after reboot flag reset');
                }
            }
        }
    } else {
        $reboot_timer = 0;
    }

    foreach ($to_stop as $title => $tm) {

        $key = array_search($title, $auto_restarts);
        if ($key !== false) {
            unset($auto_restarts[$key]);
            $auto_restarts = array_values($auto_restarts);
        }

        if ($tm <= time()) {
            if (isset($is_running[$title])) {
                $id = $is_running[$title];
                DebMes("Force closing service " . $title . " (id: " . $id . ")", 'boot');
                setCycleRuntimeStatus($title, 'stopping');
                addCycleRuntimeLog($title, 'Force closing service');
                $threads->closeThread($id);
            }
            unset($to_stop[$title]);
        }
    }

    foreach ($to_start as $title => $tm) {
        if ($tm <= time()) {
            if (!isset($is_running[$title])) {
                $cmd = './scripts/' . $title . '.php';
                DebMes("Starting service " . $title . ' (' . $cmd . ')', 'boot');
                setCycleRuntimeStatus($title, 'starting');
                addCycleRuntimeLog($title, 'Starting service ' . $cmd);
                $pipe_id = startCycleThread($threads, $title, $cmd);
                if ($pipe_id) {
                    $is_running[$title] = $pipe_id;
                    $started_when[$title] = time();
                }
            } else {
                DebMes("Got to_start command for " . $title . ' but looks like it is already running', 'boot');
            }
            unset($to_stop[$title]);
            unset($to_start[$title]);
        }
    }

    if (!empty($result)) {
        if (preg_match_all('/THREAD OUTPUT:\s*\[(.*?)\]\s*\n(.*?)\nTHREAD OUTPUT END/is', $result, $outputMatches, PREG_SET_ORDER)) {
            $total_output = count($outputMatches);
            for ($io = 0; $io < $total_output; $io++) {
                if (preg_match('/(cycle_.+?)\.php/is', $outputMatches[$io][1], $m)) {
                    addCycleRuntimeLog($m[1], $outputMatches[$io][2]);
                }
            }
        }
        $closePattern = '/THREAD CLOSED:\s*\[(.*?)\](?:\s+EXIT_CODE=([-\d]+)\s+TERM_SIG=([-\d]+)\s+STOP_SIG=([-\d]+))?/is';
        if (preg_match_all($closePattern, $result, $matches, PREG_SET_ORDER) && !isRebootRequired()) {
            $total_m = count($matches);
            for ($im = 0; $im < $total_m; $im++) {
                $closed_thread = $matches[$im][1];
                $exit_code = isset($matches[$im][2]) && $matches[$im][2] !== '' ? (int)$matches[$im][2] : null;
                $term_sig = isset($matches[$im][3]) && $matches[$im][3] !== '' ? (int)$matches[$im][3] : null;
                $cycle_title = '';
                $need_restart = 0;
                $last_error = '';
                $stop_requested = false;
                if (preg_match('/(cycle_.+?)\.php/is', $closed_thread, $m)) {
                    $cycle_title = $m[1];
                    $last_error = checkCycleFromCache($cycle_title . 'LastError');
                    if ($last_error === false) {
                        $last_error = '';
                    }
                    DebMes("Thread closed: " . $cycle_title, 'boot');
                    $stop_requested = isset($to_stop[$cycle_title]);
                    unset($to_stop[$cycle_title]);
                    setGlobal($cycle_title . 'Run', '');
                    setCycleRuntimeStatus($cycle_title, 'stopped', 'Thread closed');
                    addCycleRuntimeLog($cycle_title, 'Thread closed. Exit code: ' . (string)$exit_code . ', term signal: ' . (string)$term_sig);
                    if (!$stop_requested) {
                        $key = array_search($cycle_title, $auto_restarts);
                        if ($key !== false) {
                            unset($auto_restarts[$key]);
                            $auto_restarts = array_values($auto_restarts);
                            $need_restart = 1;
                        } elseif (isset($to_start[$cycle_title])) {
                            $need_restart = 1;
                        }
                    }
                }
                if ($need_restart && $cycle_title) {
                    if (!isset($to_start[$cycle_title])) {
                        DebMes("AUTO-RECOVERY: " . $closed_thread, 'boot');
                        setCycleRuntimeStatus($cycle_title, 'starting', 'Auto recovery scheduled');
                        addCycleRuntimeLog($cycle_title, 'Auto recovery scheduled');
                        if (!preg_match('/websockets/is', $closed_thread)) {
                            $details = buildCycleStopReason(
                                $cycle_title,
                                $closed_thread,
                                $exit_code,
                                $term_sig,
                                $stop_requested,
                                isset($to_start[$cycle_title]),
                                $last_error
                            );
                            registerError('cycle_stop', $details);
                            saveCycleToCache($cycle_title . 'LastError', '');
                        }
                        $to_start[$cycle_title] = time() + 2;
                    }
                    $started_when[$cycle_title] = $to_start[$cycle_title];
                }
            }
        }
    }
}

$mainCycleShutdownExpected = true;
resetRebootRequired();
