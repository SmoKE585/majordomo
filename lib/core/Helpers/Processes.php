<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Summary of execInBackground
 * @param mixed $cmd Command
 * @return void
 */
function execInBackground($cmd)
{
    if (IsWindowsOS()) {
        //pclose(popen("start /B ". $cmd, "r"));
        try {
            //pclose(popen("start /B ". $cmd, "r"));
            if (class_exists('COM')) {
                $WshShell = new COM("WScript.Shell");
                $oExec = $WshShell->Run("cmd /C \"" . $cmd . "\"", 0, false);
            } else {
                system($cmd);
            }
        } catch (Exception $e) {
            DebMes('Error: exception ' . get_class($e) . ', ' . $e->getMessage() . '.', 'execs', 'error');
        }
    } else {
        try {
            exec($cmd . " > /dev/null &");
        } catch (Exception $e) {
            DebMes('Error: exception ' . get_class($e) . ', ' . $e->getMessage() . '.', 'execs', 'error');
        }
    }
}

/**
 * Summary of safe_exec
 * @param mixed $command Command
 * @param mixed $exclusive Exclusive (default 0)
 * @param mixed $priority Priority (default 0)
 * @param mixed $on_complete On_Complete code (default '')
 * @return mixed
 */
function safe_exec($command, $exclusive = 0, $priority = 0, $on_complete = '')
{
    $rec = array();

    $rec['ADDED'] = date('Y-m-d H:i:s');
    $rec['COMMAND'] = $command;
    $rec['EXCLUSIVE'] = (int)$exclusive;
    $rec['PRIORITY'] = (int)$priority;
    $rec['ON_COMPLETE'] = $on_complete;

    $rec['ID'] = SQLInsert('safe_execs', $rec);
    return $rec['ID'];
}
