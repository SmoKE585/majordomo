<?php

chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);

include_once("./load_settings.php");

function cycleWebSocketsLog($message)
{
    $message = date('Y-m-d H:i:s') . ' ' . $message;
    echo $message . PHP_EOL;
    if (function_exists('DebMes')) {
        DebMes($message, 'websockets');
    }
}

if (defined('DISABLE_WEBSOCKETS') && DISABLE_WEBSOCKETS == 1) {
    echo "Web-sockets disabled\n";
    exit;
}

include_once(DIR_MODULES . "control_modules/control_modules.class.php");

include_once(DIR_MODULES . 'scenes/scenes.class.php');
$scenes = new scenes();

include_once(DIR_MODULES . 'plans/plans.class.php');
$plans = new plans();

include_once(DIR_MODULES . 'commands/commands.class.php');
$commands = new commands();

if (file_exists(DIR_MODULES . 'devices/devices.class.php')) {
    include_once(DIR_MODULES . 'devices/devices.class.php');
    $devices = new devices();
}

include_once(DIR_MODULES . 'objects/objects.class.php');
$objects_module = new objects();

$websockets_script_started = time();

$cycleName = str_replace('.php', '', basename(__FILE__)) . 'Run';
setGlobal($cycleName, time(), 1);

require_once('./lib/websockets/server/server.php');

cycleWebSocketsLog('cycle_websockets started, pid=' . getmypid() . ', port=' . (int)WEBSOCKETS_PORT);

register_shutdown_function(function () use ($websockets_script_started) {
    $error = error_get_last();
    $uptime = time() - $websockets_script_started;
    $message = 'cycle_websockets shutdown, pid=' . getmypid()
        . ', uptime=' . $uptime . 's'
        . ', memory=' . round(memory_get_usage(true) / 1024 / 1024, 2) . 'Mb'
        . ', peak=' . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'Mb';
    if (is_array($error) && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        $message .= ', fatal=' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'];
    }
    cycleWebSocketsLog($message);
});

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach (array(SIGTERM, SIGINT, SIGHUP) as $signal) {
        pcntl_signal($signal, function ($signal) use ($websockets_script_started) {
            cycleWebSocketsLog('cycle_websockets got signal ' . $signal . ', pid=' . getmypid() . ', uptime=' . (time() - $websockets_script_started) . 's');
            exit(128 + $signal);
        });
    }
    if (defined('SIGALRM') && function_exists('pcntl_alarm')) {
        pcntl_signal(SIGALRM, function () {
            if (isset($GLOBALS['websockets_busy_since'])) {
                $busyFor = microtime(true) - (float)$GLOBALS['websockets_busy_since'];
                if ($busyFor > 5) {
                    $info = isset($GLOBALS['websockets_busy_info']) ? $GLOBALS['websockets_busy_info'] : 'unknown';
                    cycleWebSocketsLog('cycle_websockets busy for ' . round($busyFor, 3) . 's: ' . $info);
                }
            }
            pcntl_alarm(15);
        });
        pcntl_alarm(15);
    }
}


function killProcessesOnPort($port)
{
    $port = (int)$port;
    $currentPid = getmypid();

    $output = array();

    exec("ss -ltnp 2>/dev/null | grep ':" . $port . " '", $output);

    if (empty($output)) {
        return;
    }

    foreach ($output as $line) {
        if (!preg_match_all('/pid=([0-9]+)/', $line, $matches)) {
            continue;
        }

        foreach ($matches[1] as $pid) {
            $pid = (int)$pid;

            if ($pid <= 0 || $pid == $currentPid) {
                continue;
            }

            $message = date('Y-m-d H:i:s') . " Killing process PID {$pid} on port {$port}";
            echo $message . PHP_EOL;

            if (function_exists('DebMes')) {
                DebMes($message, 'websockets');
            }

            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            } else {
                @exec('kill -TERM ' . $pid . ' 2>/dev/null');
            }
        }
    }

    sleep(2);

    foreach ($output as $line) {
        if (!preg_match_all('/pid=([0-9]+)/', $line, $matches)) {
            continue;
        }

        foreach ($matches[1] as $pid) {
            $pid = (int)$pid;

            if ($pid <= 0 || $pid == $currentPid) {
                continue;
            }

            if (file_exists('/proc/' . $pid)) {
                $message = date('Y-m-d H:i:s') . " Force killing process PID {$pid} on port {$port}";
                echo $message . PHP_EOL;

                if (function_exists('DebMes')) {
                    DebMes($message, 'websockets');
                }

                if (function_exists('posix_kill')) {
                    @posix_kill($pid, SIGKILL);
                } else {
                    @exec('kill -KILL ' . $pid . ' 2>/dev/null');
                }
            }
        }
    }
}


killProcessesOnPort((int)WEBSOCKETS_PORT);

try {
    $server = majordomoCreateWebSocketServer();
    $server->run();

    throw new Exception('WebSocket server loop exited unexpectedly');
} catch (Throwable $e) {
    cycleWebSocketsLog('WebSocket server crashed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());

    exit(1);
}
