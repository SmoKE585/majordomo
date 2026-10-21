<?php

chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);

include_once("./load_settings.php");
include_once(DIR_MODULES . "control_modules/control_modules.class.php");
$ctl = new control_modules();
setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);

ensureHistorySchema();

//SQLTruncateTable('phistory_queue');

// Intentionally do not run OPTIMIZE TABLE on each cycle start.
// It is expensive on large installations and should be done via maintenance windows.

$limit = (int)gg('phistory_queue_limit');
if (!$limit) {
    $limit = 200;
}
$queue_problem_limit = (int)gg('phistory_queue_problem_limit');
if (!$queue_problem_limit) {
    $queue_problem_limit = max($limit * 5, 1000);
}

$checked_time = 0;
setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
$cycleVarName = 'ThisComputer.' . str_replace('.php', '', basename(__FILE__)) . 'Run';

echo date("H:i:s") . " running " . basename(__FILE__) . "\n";

$queue_check_time = 0;
$cleanup_time = 0;
while (1) {
    try {
        if (time() - $checked_time > 5) {
            $checked_time = time();
            setGlobal('cycle_phistoryRun', time(), 1);
        }
        if (time() - $queue_check_time > 10) {
            $queue_check_time = time();
            $row = SQLSelectOne('SELECT COUNT(*) AS TOTAL FROM phistory_queue');
            $count = (int)($row['TOTAL'] ?? 0);
            $problem = gg('phistory_queue_problem');
            if ($count > $queue_problem_limit && !$problem) {
                sg('phistory_queue_problem', 1);
                registerError('phistory_queue', 'Properties history queue is too long (' . $count . ')');
            } elseif ($count <= $limit && $problem) sg('phistory_queue_problem', 0);
        }
        $processed = \MajorDoMo\Core\Core::history()->process($limit);
        if (time() - $cleanup_time >= 60) {
            $cleanup_time = time();
            \MajorDoMo\Core\Core::history()->cleanup();
        }
        if (!$processed) sleep(1);
    } catch (Throwable $e) {
        DebMes('History queue: ' . $e->getMessage(), 'cycle_phistory');
        sleep(1);
    }
    if (isRebootRequired() || isset($_GET['onetime'])) exit;
}

function ensureHistorySchema()
{
    ensureTableIndex('phistory', 'idx_phistory_value_added', 'VALUE_ID,ADDED');
    ensureTableIndex('phistory', 'idx_phistory_value_id', 'VALUE_ID,ID');
    ensureTableIndex('phistory_queue', 'idx_phistory_queue_value_id', 'VALUE_ID');
    ensureTableIndex('phistory_queue', 'idx_phistory_queue_added', 'ADDED');
    ensureTableIndex('history', 'idx_history_object_added', 'OBJECT_ID,ADDED');
    ensureTableIndex('history', 'idx_history_method_added', 'METHOD_ID,ADDED');
    ensureTableIndex('history', 'idx_history_value_added', 'VALUE_ID,ADDED');
}

function ensureTableIndex($table_name, $index_name, $index_columns)
{
    $table_name_safe = preg_replace('/[^a-z0-9_]/i', '', (string)$table_name);
    $index_name_safe = preg_replace('/[^a-z0-9_]/i', '', (string)$index_name);
    $index_columns_safe = preg_replace('/[^a-z0-9_,]/i', '', (string)$index_columns);
    if ($table_name_safe == '' || $index_name_safe == '' || $index_columns_safe == '') {
        return;
    }

    $table_exists = SQLSelectOne("SHOW TABLES LIKE '" . DBSafe($table_name_safe) . "'");
    if (!$table_exists) {
        return;
    }

    $check = SQLSelectOne("SHOW INDEX FROM `$table_name_safe` WHERE Key_name='" . DBSafe($index_name_safe) . "'");
    if (!isset($check['Key_name'])) {
        SQLExec("ALTER TABLE `$table_name_safe` ADD INDEX `$index_name_safe` ($index_columns_safe)");
    }
}
