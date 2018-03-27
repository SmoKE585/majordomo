<?php

chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);
// connecting to database
$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);

include_once("./load_settings.php");
include_once(DIR_MODULES . "control_modules/control_modules.class.php");
$ctl = new control_modules();
setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);

//SQLExec("TRUNCATE TABLE phistory_queue;");

debug_echo("Optimizing phistory");
SQLExec("OPTIMIZE TABLE phistory;");
debug_echo("Done");

$checked_time = 0;
echo date("H:i:s") . " running " . basename(__FILE__) . "\n";

while (1) {
    if (time() - $checked_time > 5) {
        $checked_time = time();
        setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
        setGlobal('ThisComputer.uptime', time() - getGlobal('ThisComputer.started_time'));
    }


    /*
    $keep = SQLSelect("SELECT DISTINCT VALUE_ID, KEEP_HISTORY FROM phistory_queue");
    if ($keep[0]['VALUE_ID']) {
        $total = count($keep);
        for ($i = 0; $i < $total; $i++) {
            $keep_rec = $keep[$i];
            if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
                $table_name = createHistoryTable($keep_rec['VALUE_ID']);
            } else {
                $table_name = 'phistory';
            }
            if ($keep_rec['KEEP_HISTORY'] == 0) continue;
            $start_tm = date('Y-m-d H:i:s',(time()-(int)$keep_rec['KEEP_HISTORY']*24*60*60));
            echo date('Y-m-d H:i:s').' '.("DELETE FROM $table_name WHERE VALUE_ID='" . $keep_rec['VALUE_ID'] . "' AND ADDED<('$start_tm')\n");
            SQLExec("DELETE FROM $table_name WHERE VALUE_ID='" . $keep_rec['VALUE_ID'] . "' AND ADDED<('$start_tm')");
            echo date('Y-m-d H:i:s ')." Done \n";
        }
    }
    */

    $queue = SQLSelect("SELECT * FROM phistory_queue ORDER BY ID LIMIT 500");
    if ($queue[0]['ID']) {
        $total = count($queue);
        $processed = array();
        for ($i = 0; $i < $total; $i++) {
            $q_rec = $queue[$i];
            $value = $q_rec['VALUE'];
            $old_value = $q_rec['OLD_VALUE'];

            debug_echo("Queue $i / $total");

            $queue_error_status=gg('phistory_queue_problem');
            if ($total>200 && !$queue_error_status) {
                sg('phistory_queue_problem',1);
                registerError('phistory_queue','Properties history queue is too long ('.$total.')');
            } elseif ($total<=200 && $queue_error_status) {
                sg('phistory_queue_problem',0);
            }

            SQLExec("DELETE FROM phistory_queue WHERE ID='" . $q_rec['ID'] . "'");

            if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
                $table_name = 'phistory_value_'.$q_rec['VALUE_ID'];
            } else {
                $table_name = 'phistory';
            }

            if ($value != $old_value || (defined('HISTORY_NO_OPTIMIZE') && HISTORY_NO_OPTIMIZE == 1)) {
                if (!isset($processed[$q_rec['VALUE_ID']])) {
                    $processed[$q_rec['VALUE_ID']]=time();
                }
                if ((time() - $processed[$q_rec['VALUE_ID']]) > 8 * 60 * 60) {
                    $start_tm = date('Y-m-d H:i:s',(time()-(int)$q_rec['KEEP_HISTORY']*24*60*60));
                    debug_echo("processing DELETE FROM $table_name WHERE VALUE_ID='" . $q_rec['VALUE_ID'] . "' AND ADDED<('".$start_tm."')\n");
                    SQLExec("DELETE FROM $table_name WHERE VALUE_ID='" . $q_rec['VALUE_ID'] . "' AND ADDED<('".$start_tm."')");
                    $processed[$q_rec['VALUE_ID']] = time();
                    debug_echo(" Done ");
                }
                $h = array();
                $h['VALUE_ID'] = $q_rec['VALUE_ID'];
                $h['ADDED'] = $q_rec['ADDED'];
                $h['VALUE'] = $value;
                debug_echo(" Insert new value ".$h['VALUE_ID']);
                $h['ID'] = SQLInsert($table_name, $h);
                debug_echo(" Done ");
            } elseif ($value == $old_value) {

                //debug_echo(" Check history for same value ".$h['VALUE_ID']);
                $tmp_history = SQLSelect("SELECT * FROM $table_name WHERE VALUE_ID='" . $q_rec['VALUE_ID'] . "' ORDER BY ID DESC LIMIT 2");
                $prev_value = $tmp_history[0]['VALUE'];
                $prev_prev_value = $tmp_history[1]['VALUE'];
                //debug_echo(" Done ");

                if ($prev_value == $prev_prev_value && $tmp_history[0]['ID']) {
                    debug_echo(" Update same value ".$h['VALUE_ID']);
                    SQLExec("UPDATE $table_name SET ADDED='".$q_rec['ADDED']."' WHERE ID=".$tmp_history[0]['ID']);
                    /*
                    $tmp_history[0]['ADDED'] = $q_rec['ADDED'];
                    foreach($tmp_history[0] as $k=>$v) {
                        if ($k=='ID' || $k=='ADDED') continue;
                        unset($tmp_history[0][$k]);
                    }
                    */
                    //SQLUpdate($table_name, $tmp_history[0]);
                    debug_echo(" Done ");
                } else {
                    debug_echo(" Insert same new value ".$h['VALUE_ID']);
                    $h = array();
                    $h['VALUE_ID'] = $q_rec['VALUE_ID'];
                    $h['ADDED'] = $q_rec['ADDED'];
                    $h['VALUE'] = $value;
                    $h['ID'] = SQLInsert($table_name, $h);
                    debug_echo(" Done ");
                }
            }

        }
    }

    if (file_exists('./reboot') || IsSet($_GET['onetime'])) {
        $db->Disconnect();
        exit;
    }
    sleep(1);
}

function debug_echo($line) {
    //echo date('Y-m-d H:i:s').' '.$line."\n";
}

DebMes("Unexpected close of cycle: " . basename(__FILE__));