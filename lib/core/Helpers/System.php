<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Возвращает true если ОС - Windows
 * @return bool
 */
function IsWindowsOS()
{
    if (substr(php_uname(), 0, 7) === "Windows")
        return true;

    return false;
}

function getSystemSerial($force_update = 0)
{
    $serial = gg('Serial');
    if (!$serial || $serial == '0' || $force_update) {
        $serial = '';
        if (IsWindowsOS()) {
            $data = exec('vol c:');
            if (preg_match('/[\w]+\-[\w]+/', $data, $m)) {
                $serial = strtolower($m[0]);
            }
        } else {
            $data = trim(exec("cat /proc/cpuinfo | grep Serial | cut -d '':'' -f 2"));
            if ($data == '') {
                $data = trim(exec("sudo cat /proc/cpuinfo | grep Serial | cut -d '':'' -f 2"));
            }
            $serial = ltrim($data, '0');
        }
        if (!$serial) {
            $serial = uniqid('uniq');
        }
        sg('Serial', $serial);
    }
    return $serial;
}

function isRebootRequired()
{
    $path_to_flag = ROOT . 'reboot';
    return file_exists($path_to_flag);
}

function resetRebootRequired()
{
    $path_to_flag = ROOT . 'reboot';
    if (file_exists($path_to_flag)) {
        $old_reason = trim((string)@file_get_contents($path_to_flag));
        $res = @unlink($path_to_flag);
        if (function_exists('DebMes')) {
            DebMes('Reboot flag reset: ' . ($res ? 'OK' : 'FAILED') . ', reason=' . $old_reason . ', path=' . $path_to_flag, 'boot', $res ? 'info' : 'error');
        }
    }
}

/**
 * Title
 * @return string
 */
function return_memory_usage()
{
    $size = memory_get_usage(true);
    $unit = array('b', 'kb', 'mb', 'gb', 'tb', 'pb');
    return @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
}

function setRebootRequired($reason = '')
{
    $path_to_flag = ROOT . 'reboot';
    if (!$reason) $reason = time();
    $source = '';
    if (function_exists('debug_backtrace')) {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        if (isset($trace[1]['file'])) {
            $source = ' from ' . $trace[1]['file'] . ':' . (isset($trace[1]['line']) ? $trace[1]['line'] : 0);
        }
    }
    if (function_exists('DebMes')) {
        DebMes('Reboot flag set: ' . $reason . $source, 'boot');
    }
    @SaveFile($path_to_flag, $reason);
}
