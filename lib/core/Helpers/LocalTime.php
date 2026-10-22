<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Summary of getLocalTime
 * @param mixed $diff (default 0)
 * @return string
 */
function getLocalTime($diff = 0)
{
    // LOCALTIME (with GMT offset)
    $cur_dif = date("Z");
    $nowgm = gmdate("U") - $cur_dif;
    $now = $nowgm + $diff * 60 * 60;
    $res = date("Y-m-d H:i:s", $now);

    return $res;
}

/**
 * Summary of recLocalTime
 * @param mixed $table Table
 * @param mixed $id Id
 * @param mixed $gmt Gmt
 * @param mixed $field Field
 * @return void
 */
function recLocalTime($table, $id, $gmt, $field = "ADDED")
{
    // UPDATES TIMESTAMP FIELD USING GMT
    $rec = SQLSelectOne("SELECT ID, DATE_FORMAT($field, '%Y-%m-%d %H:%i') as DAT FROM $table WHERE ID='$id'");

    if (isset($rec["ID"])) {
        $new_dat = setLocalTime($rec['DAT'], $gmt);
        SQLExec("UPDATE $table SET $field='$new_dat' WHERE ID='$id'");
    }
}

/**
 * Summary of setLocalTime
 * @param mixed $now_date Current time
 * @param mixed $diff Diff (default 0)
 * @return string
 */
function setLocalTime($now_date, $diff = 0)
{
    // CONVERT FROM CURRENT TIME TO CORRECT TIME USING GMT
    $cur_dif = date("Z");

    if ($now_date == "") {
        $nowgm = date("U") - $cur_dif;
    } else {
        $nowgm = strtotime($now_date) - $cur_dif;
    }

    $now = $nowgm + $diff * 60 * 60;
    $res = date("Y-m-d H:i:s", $now);

    return $res;
}
