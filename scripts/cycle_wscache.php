<?php
chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);

include_once("./load_settings.php");

function cycleWsCacheLog($message)
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

SQLTruncateTable('cached_ws');
echo date("H:i:s") . " running " . basename(__FILE__) . PHP_EOL;

$checked_time = 0;
$latest_sent = time();
$cycle_wscache_started = time();
setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
$cycleVarName = 'ThisComputer.' . str_replace('.php', '', basename(__FILE__)) . 'Run';
if (defined('SETTINGS_SYSTEM_WEBSOCKETS_RESTART_TIMEOUT') && (int)SETTINGS_SYSTEM_WEBSOCKETS_RESTART_TIMEOUT >= 0) {
    $websocket_restart_timeout = (int)SETTINGS_SYSTEM_WEBSOCKETS_RESTART_TIMEOUT;
} else {
    $websocket_restart_timeout = 0;
}

if (defined('WEBSOCKETS_QUEUE_LIMIT') && (int)WEBSOCKETS_QUEUE_LIMIT > 0) {
    $websocket_queue_limit = (int)WEBSOCKETS_QUEUE_LIMIT;
} else {
    $websocket_queue_limit = 500;
}

clearTimeout('restartWebSocket');

cycleWsCacheLog('cycle_wscache started, pid=' . getmypid() . ', queue_limit=' . $websocket_queue_limit . ', restart_timeout=' . $websocket_restart_timeout);

register_shutdown_function(function () use ($cycle_wscache_started) {
    $error = error_get_last();
    $uptime = time() - $cycle_wscache_started;
    $message = 'cycle_wscache shutdown, pid=' . getmypid()
        . ', uptime=' . $uptime . 's'
        . ', memory=' . round(memory_get_usage(true) / 1024 / 1024, 2) . 'Mb'
        . ', peak=' . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'Mb';
    if (is_array($error) && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        $message .= ', fatal=' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'];
    }
    cycleWsCacheLog($message);
});

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach (array(SIGTERM, SIGINT, SIGHUP) as $signal) {
        pcntl_signal($signal, function ($signal) use ($cycle_wscache_started) {
            cycleWsCacheLog('cycle_wscache got signal ' . $signal . ', pid=' . getmypid() . ', uptime=' . (time() - $cycle_wscache_started) . 's');
            exit(128 + $signal);
        });
    }
}

while (1) {
    if ($checked_time != time()) {
        $checked_time = time();
        try {
            $queue = SQLSelect("SELECT * FROM cached_ws ORDER BY ADDED LIMIT " . $websocket_queue_limit);
            if (is_array($queue) && !empty($queue)) {
                $total = count($queue);
                $sent_ok = 1;
                $properties = array();
                $values = array();
                $post_property_keys = array();

                for ($i = 0; $i < $total; $i++) {
                    $row = $queue[$i];
                    $property = $row['PROPERTY'] ?? '';
                    $postAction = $row['POST_ACTION'] ?? 'PostProperty';
                    $dataValue = $row['DATAVALUE'] ?? '';

                    if ($property === '') {
                        continue;
                    }

                    if ($postAction == 'PostProperty') {
                        $decoded = json_decode($dataValue, true);
                        if (is_array($decoded)) {
                            $dataValue = $decoded;
                        }
                        $properties[] = $property;
                        $values[] = $dataValue;
                        $post_property_keys[] = $property;
                        continue;
                    }

                    $decoded = json_decode($dataValue, true);
                    if (is_array($decoded)) {
                        $dataValue = $decoded;
                    }

                    $sent = postToWebSocket($property, $dataValue, $postAction);
                    if ($sent) {
                        SQLExec("DELETE FROM cached_ws WHERE PROPERTY='" . DBSafe($property) . "'");
                    } else {
                        $sent_ok = 0;
                    }
                }

                if (count($properties) > 0) {
                    $sent = postToWebSocket($properties, $values, 'PostProperty');
                    if ($sent) {
                        foreach ($post_property_keys as $property) {
                            SQLExec("DELETE FROM cached_ws WHERE PROPERTY='" . DBSafe($property) . "'");
                        }
                    } else {
                        $sent_ok = 0;
                    }
                }

                if ($sent_ok) {
                    $latest_sent = time();
                    // saveToCache("MJD:$cycleVarName", $latest_sent);
                    setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', $latest_sent, 1);
                    if ($websocket_restart_timeout > 0) {
                        setTimeout('restartWebSocket', 'sg("cycle_websocketsRun","");sg("cycle_websocketsControl","restart");', $websocket_restart_timeout);
                    } else {
                        clearTimeout('restartWebSocket');
                    }
                } else {
                    echo date("H:i:s") . ' Error while posting to websocket.' . "\n";
                }
            }
            unset($queue, $properties, $values, $post_property_keys);
        } catch (Throwable $e) {
            DebMes('cycle_wscache error: ' . $e->getMessage(), 'websockets');
            echo date("H:i:s") . ' cycle_wscache exception: ' . $e->getMessage() . "\n";
        }
    }
    if (isRebootRequired() || isset($_GET['onetime'])) {
        exit;
    }
    sleep(1);
}

DebMes("Unexpected close of cycle: " . basename(__FILE__));
