<?php

chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);

include_once("./load_settings.php");

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
    $message = date('Y-m-d H:i:s') . ' WebSocket server crashed: ' . $e->getMessage();

    echo $message . PHP_EOL;

    if (function_exists('DebMes')) {
        DebMes($message, 'websockets');
    }

    exit(1);
}
