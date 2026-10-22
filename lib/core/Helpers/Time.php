<?php

/** System helpers. Public names and argument defaults are part of the module API. */

function getNumberWord($number, $suffix)
{
    $keys = array(2, 0, 1, 1, 1, 2);
    $mod = abs($number) % 100;
    $suffix_key = ($mod > 7 && $mod < 20) ? 2 : $keys[min($mod % 10, 5)];
    return $suffix[$suffix_key];
}

function getPassedText($updatedTime)
{
    if (!is_int($updatedTime)) {
        $updatedTime = strtotime($updatedTime);
    }
    $passed = time() - $updatedTime;
    $passedText = '';
    if ($passed < 10) {
        $passedText = LANG_DEVICES_PASSED_NOW;
    } elseif ($passed < 60) {
        $passedText = $passed . ' ' . LANG_DEVICES_PASSED_SECONDS_AGO;
    } elseif ($passed < 60 * 60) {
        $passedText = round($passed / 60) . ' ' . LANG_DEVICES_PASSED_MINUTES_AGO;
    } elseif ($passed < 20 * 60 * 60) {
        //just time
        $passedText = date('H:i', $updatedTime);
    } else {
        //time and date
        $passedText = date('d.m.Y H:i', $updatedTime);
    }
    return $passedText;
}

/**
 * Summary of isWeekDay
 * @return bool
 */
function isWeekDay()
{
    if (IsWeekEnd())
        return false;

    return true;
}

/**
 * Summary of isWeekEnd
 * @return bool
 */
function isWeekEnd()
{
    if (date('w') == 0 || date('w') == 6)
        return true; // sunday, saturday


    return false;
}

/**
 * Summary of recognizeTime
 * @param mixed $text Text
 * @param mixed $newText New text
 * @return array|double|int
 */
function recognizeTime($text, &$newText = '')
{
    $result = 0;
    $found = 0;
    $new_time = time();
    #$text = ($text); #???
    $text = trim($text);

    if (preg_match('/' . LANG_PATTERN_DO_AFTER . ' (\d+) ' . LANG_PATTERN_SECOND . '.?/isu', $text, $m)) {
        $new_time = time() + $m[1];
        $newText = trim(str_replace($m[0], '', $text));
        $found = 1;
    } elseif (preg_match('/' . LANG_PATTERN_DO_AFTER . ' (\d+) ' . LANG_PATTERN_MINUTE . '.?/isu', $text, $m)) {
        $new_time = time() + $m[1] * 60;
        $newText = trim(str_replace($m[0], '', $text));
        $found = 1;
    } elseif (preg_match('/' . LANG_PATTERN_DO_AFTER . ' (\d+) ' . LANG_PATTERN_HOUR . '.?/isu', $text, $m)) {
        $new_time = time() + $m[1] * 60 * 60;
        $newText = trim(str_replace($m[0], '', $text));
        $found = 1;
    } elseif (preg_match('/' . LANG_PATTERN_DO_FOR . ' (\d+):(\d+)/isu', $text, $m)) {
        $new_time = mktime($m[1], $m[2], 0, (int)date('m'), (int)date('d'), (int)date('Y'));
        $newText = trim(str_replace($m[0], '', $text));
        $found = 1;
    }

    #$newText = ($newText); #???

    if ($found) {
        $result = $new_time;
    }

    return $result;
}

/**
 * Summary of textToNumbers
 * @param mixed $text Text
 * @return mixed
 */
function textToNumbers($text)
{
    $newtext = ($text);

    return $newtext;
}

/**
 * Summary of timeAfter
 * @param mixed $tm Time
 * @return bool
 */
function timeAfter($tm)
{
    $trueTime = timeConvert($tm);

    if (time() >= $trueTime)
        return true;

    return false;
}

/**
 * Summary of timeBefore
 * @param mixed $tm Time
 * @return bool
 */
function timeBefore($tm)
{
    $trueTime = timeConvert($tm);

    if (time() <= $trueTime)
        return true;

    return false;
}

/**
 * Summary of timeBetween
 * @param mixed $tm1 Time 1
 * @param mixed $tm2 Time 2
 * @return bool
 */
function timeBetween($tm1, $tm2)
{
    $trueTime1 = timeConvert($tm1);
    $trueTime2 = timeConvert($tm2);

    if ($trueTime1 > $trueTime2) {
        if ($trueTime2 < time()) {
            $trueTime2 += 24 * 60 * 60;
        } else {
            $trueTime1 -= 24 * 60 * 60;
        }
    }

    if ((time() >= $trueTime1) && (time() <= $trueTime2))
        return true;

    return false;
}

/**
 * Summary of timeConvert
 * @param mixed $tm Time
 * @return int|string
 */
function timeConvert($tm)
{
    $tm = trim($tm);

    if (preg_match('/^(\d+):(\d+)$/', $tm, $m)) {
        $hour = $m[1];
        $minute = $m[2];
        $trueTime = mktime($hour, $minute, 0, (int)date('m'), (int)date('d'), (int)date('Y'));
    } elseif (preg_match('/^(\d+)$/', $tm, $m)) {
        $trueTime = $tm;
    }

    return $trueTime;
}

/**
 * Summary of timeIs
 * @param mixed $tm Time
 * @return bool
 */
function timeIs($tm)
{
    if (date('H:i') == $tm)
        return true;

    return false;
}

/**
 * Summary of timeNow
 * @param mixed $tm time (default 0)
 * @return string
 */
function timeNow($tm = 0)
{
    if (!$tm) {
        $tm = time();
    }

    $h = (int)date('G', $tm);
    $m = (int)date('i', $tm);
    $ms = '';

    if (LANG_HOURS_ARRAY and LANG_MINUTE_ARRAY) {
        $array = explode(',', LANG_HOURS_ARRAY);
        $hw = $h . ' ' . getNumberWord($h, $array);
        if ($m > 0) {
            $array = explode(',', LANG_MINUTE_ARRAY);
            $ms = $m . ' ' . getNumberWord($m, $array);
        }
    } else {
        $hw = date('H:i', $tm);
    }

    $res = trim($hw . " " . $ms);
    return $res;
}
