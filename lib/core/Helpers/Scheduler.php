<?php

/** Совместимые функции таймеров. 2026-10-25 — общий планировщик, Redis и сохранение цепочки. */

/**
 * Summary of addScheduledJob
 * @param mixed $title Title
 * @param mixed $commands Commands
 * @param mixed $datetime Date
 * @param mixed $expire Expire time (default 1800)
 * @return mixed
 */
function addScheduledJob($title, $commands, $datetime, $expire = 1800)
{
    return \MajorDoMo\Core\Core::scheduler()->schedule((string)$title, (string)$commands, (int)$datetime, (int)$expire);
}

/**
 * Summary of clearScheduledJob
 * @param mixed $title Title
 * @return mixed
 */
function clearScheduledJob($title)
{
    return \MajorDoMo\Core\Core::scheduler()->cancelTitle((string)$title);
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
    return \MajorDoMo\Core\Core::scheduler()->cancel((int)$id);
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
    return \MajorDoMo\Core\Core::scheduler()->exists((string)$title);
}
