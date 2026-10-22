<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Summary of addScheduledJob
 * @param mixed $title Title
 * @param mixed $commands Commands
 * @param mixed $datetime Date
 * @param mixed $expire Expire time (default 60)
 * @return mixed
 */
function addScheduledJob($title, $commands, $datetime, $expire = 1800)
{
    clearScheduledJob($title);
    $rec = array();
    $rec['TITLE'] = $title;
    $rec['COMMANDS'] = $commands;
    $rec['RUNTIME'] = date('Y-m-d H:i:s', $datetime);
    if ((int)$expire > 0) {
        $rec['EXPIRE'] = date('Y-m-d H:i:s', $datetime + (int)$expire);
    }
    $rec['ID'] = SQLInsert('jobs', $rec);

    return $rec['ID'];
}

/**
 * Summary of clearScheduledJob
 * @param mixed $title Title
 * @return mixed
 */
function clearScheduledJob($title)
{
    return SQLExec("DELETE FROM jobs WHERE TITLE = '" . DBSafe($title) . "'");
}

/**
 * Summary of clearTimeOut
 * @param mixed $title Title
 * @return mixed
 */
function clearTimeOut($title)
{
    return clearScheduledJob($title);
}

/**
 * Delete job from schedule
 * @param mixed $id Job id
 * @return mixed
 */
function deleteScheduledJob($id)
{
    return SQLExec("DELETE FROM jobs WHERE ID = " . (int)$id);
}

/**
 * Summary of runScheduledJobs
 * @return mixed
 */
function runScheduledJobs(?callable $progress = null)
{
    // TODO: compatibility facade; new code uses Core::scheduler()->run().
    return \MajorDoMo\Core\Core::scheduler()->run(null, $progress);
}

/**
 * Summary of setTimeOut
 * @param mixed $title Title
 * @param mixed $commands Commands
 * @param mixed $timeout Timeout
 * @return mixed
 */
function setTimeOut($title, $commands, $timeout = 0, $expire = 0)
{
    startMeasure('setTimeout');
    $res = addScheduledJob($title, $commands, time() + $timeout, $expire);
    endMeasure('setTimeout');
    return $res;
}

/**
 * Summary of timeOutExists
 * @param mixed $title Title
 * @return int
 */
function timeOutExists($title)
{
    $job = SQLSelectOne("SELECT ID FROM jobs WHERE PROCESSED = 0 AND TITLE = '" . DBSafe($title) . "'");
	if(!isset($job)) return false;
    return isset($job['ID']) ? (int)$job['ID'] : 0;
}
