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

set_time_limit(0);

// 2026-10-22 — MQTT-клиент загружается Composer; цикл остаётся системным.
include_once(DIR_MODULES . "mqtt/mqtt.class.php");
require_once DIR_MODULES . 'mqtt/MqttIntegrations.php';

$latest_data_received = time();
$max_no_data_timeout = 5 * 60; // 5 minutes
$mqtt_reconnect_delay = 5;
$cycle_name = str_replace('.php', '', basename(__FILE__));
$mqtt_repeating_cache = array();
$mqtt_linked_paths = array();
$mqtt_async = false;
$mqtt_runtime_index = null;
$mqtt_incoming_queue = null;
$mqtt_handlers_alive = true;
$mqtt_handlers_checked = 0;

setGlobal($cycle_name . 'Run', time(), 1);
echo date("H:i:s") . " running " . basename(__FILE__) . PHP_EOL;

while (1) {
    if (mqttShouldStop()) {
        mqttLogStop();
        break;
    }
    try {
        mqttRunOnce();
    } catch (Throwable $e) {
        DebMes("MQTT cycle error: " . $e->getMessage(), 'mqtt_error');
        echo date('Y-m-d H:i:s') . " MQTT cycle error: " . $e->getMessage() . PHP_EOL;
    }
    if (mqttShouldStop()) {
        mqttLogStop();
        break;
    }
    sleep($mqtt_reconnect_delay);
}

/**
 * Process message
 * @param mixed $topic Topic
 * @param mixed $msg Message
 * @return void
 */
function mqttRuntimeWarn(string $message): void
{
    static $last = array();
    $key = md5($message);
    if (($last[$key] ?? 0) > time() - 60) return;
    $last[$key] = time();
    DebMes($message, 'mqtt_runtime', 'warning');
}

function procmsg($topic, $msg)
{
    global $latest_data_received;
    $latest_data_received = time();

    if (!isset($topic) || !isset($msg)) return false;
    global $mqtt;
    global $stripmode;
    global $mqtt_delay;
    global $mqtt_repeating_cache;
    global $mqtt_linked_paths;
    global $mqtt_integrations;
    global $mqtt_async;
    global $mqtt_runtime_index;
    global $mqtt_incoming_queue;
    global $mqtt_handlers_alive;
    global $mqtt_handlers_checked;

    // Subscribers receive the original event before MQTT strip/dedup filters.
    if ($mqtt_integrations) {
        try {
            $mqtt_integrations->receive($topic, $msg);
        } catch (Throwable $error) {
            DebMes('MQTT module event was not queued: ' . $error->getMessage(), 'mqtt_error', 'warning');
        }
    }

    if ($mqtt_async && $mqtt_runtime_index) {
        if ($stripmode && !$mqtt_runtime_index->isLinkedOrPrefix($topic)) {
            if (isset($mqtt->config['DEBUG_MODE']) && (int)$mqtt->config['DEBUG_MODE']) {
                echo date("Y-m-d H:i:s") . " Ignore received from {$topic} : $msg\n";
            }
            return false;
        }
        if ($mqtt_delay > 0) {
            $topic_key = hash('sha256', $topic, true);
            $message_hash = hash('sha256', $msg, true);
            if (isset($mqtt_repeating_cache[$topic_key]) && $mqtt_repeating_cache[$topic_key]['hash'] === $message_hash && $mqtt_repeating_cache[$topic_key]['expires'] >= time()) {
                return false;
            }
            if (!isset($mqtt_repeating_cache[$topic_key]) && count($mqtt_repeating_cache) >= 65536) {
                unset($mqtt_repeating_cache[array_key_first($mqtt_repeating_cache)]);
            }
            $mqtt_repeating_cache[$topic_key] = array('hash' => $message_hash, 'expires' => time() + $mqtt_delay);
        }
        $debug_mode = isset($mqtt->config['DEBUG_MODE']) ? (int)$mqtt->config['DEBUG_MODE'] : 0;
        if ($debug_mode) {
            echo date("Y-m-d H:i:s") . " Queued from {$topic} : $msg\n";
        }
        $now = time();
        if ($now - $mqtt_handlers_checked >= 5) {
            $mqtt_handlers_checked = $now;
            $mqtt_handlers_alive = MqttRuntime::handlersAlive();
        }
        if (!$mqtt_handlers_alive) {
            if (isset($topic_key)) unset($mqtt_repeating_cache[$topic_key]);
            // Обработчики не подают признаков жизни: не копим очередь, а
            // обрабатываем сообщение старым путём до восстановления runtime.
            mqttRuntimeWarn('MQTT handlers have no heartbeat, falling back to sync processing');
        } else {
            $started = microtime(true);
            try {
                if (!$mqtt_incoming_queue) $mqtt_incoming_queue = MqttRuntime::queue('incoming');
                $mqtt_incoming_queue->push(array(
                    'path' => (string)$topic,
                    'value' => (string)$msg,
                    'received' => microtime(true),
                ), $mqtt_runtime_index->laneFor((string)$topic));
                MqttRuntime::count('intake');
                MqttRuntime::sample('intake', (microtime(true) - $started) * 1000);
                return true;
            } catch (Throwable $error) {
                // Redis недоступен: не теряем сообщение и обрабатываем его старым
                // путём, даже если это временно вернёт MySQL в критический путь.
                if (isset($topic_key)) unset($mqtt_repeating_cache[$topic_key]);
                mqttRuntimeWarn('MQTT intake fast path unavailable, falling back to sync: ' . $error->getMessage());
            }
        }
    }

    if ($stripmode) {
        if (!is_array($mqtt_linked_paths)) $mqtt_linked_paths = $mqtt->getLinkedPathsSnapshot();
        if (!$mqtt->hasLinkedPathWithPrefixInList($topic, $mqtt_linked_paths)) {
            if (isset($mqtt->config['DEBUG_MODE']) && (int)$mqtt->config['DEBUG_MODE']) {
                echo date("Y-m-d H:i:s") . " Ignore received from {$topic} : $msg\n";
            }
            return false;
        }
    }

    if ($mqtt_delay > 0) {
        $topic_key = hash('sha256', $topic, true);
        $message_hash = hash('sha256', $msg, true);
        if (isset($mqtt_repeating_cache[$topic_key]) && $mqtt_repeating_cache[$topic_key]['hash'] === $message_hash && $mqtt_repeating_cache[$topic_key]['expires'] >= time()) {
            return false;
        }
        if (!isset($mqtt_repeating_cache[$topic_key]) && count($mqtt_repeating_cache) >= 65536) {
            unset($mqtt_repeating_cache[array_key_first($mqtt_repeating_cache)]);
        }
        $mqtt_repeating_cache[$topic_key] = array('hash' => $message_hash, 'expires' => time() + $mqtt_delay);
    }

    $debug_mode = isset($mqtt->config['DEBUG_MODE']) ? (int)$mqtt->config['DEBUG_MODE'] : 0;
    if ($debug_mode) {
        echo date("Y-m-d H:i:s") . " Received from {$topic} : $msg\n";
    }

    $source_url = '/api.php/module/mqtt?topic=' . urlencode($topic) . '&msg=' . urlencode($msg) . '&no_session=1';
    $has_request_uri = isset($_SERVER['REQUEST_URI']);
    $old_request_uri = $has_request_uri ? $_SERVER['REQUEST_URI'] : '';
    $_SERVER['REQUEST_URI'] = $source_url;
    try {
        if (is_array($mqtt_linked_paths)) {
            $mqtt->processMessage($topic, $msg, false, $mqtt_linked_paths);
        } else {
            $mqtt->processMessage($topic, $msg, false);
        }
    } catch (Throwable $e) {
        if (isset($topic_key)) unset($mqtt_repeating_cache[$topic_key]);
        DebMes("Error processing MQTT message $topic: " . $e->getMessage(), 'mqtt_error');
    } finally {
        if ($has_request_uri) {
            $_SERVER['REQUEST_URI'] = $old_request_uri;
        } else {
            unset($_SERVER['REQUEST_URI']);
        }
    }
}

function mqttShouldStop()
{
    if (isset($_GET['onetime'])) {
        return true;
    }
    if (function_exists('isRebootRequired')) {
        return (bool)isRebootRequired();
    }
    return file_exists('./reboot');
}

/**
 * Причина остановки цикла для журнала.
 *
 * Без неё цикл, завершившийся по флагу reboot, выглядит для супервизора как
 * «неожиданное завершение (exit code 0, вероятна внутренняя ошибка в цикле)»:
 * сообщение не указывает ни на причину, ни на файл-флаг.
 */
function mqttStopReason(): string
{
    if (isset($_GET['onetime'])) return 'onetime';
    if (function_exists('isRebootRequired') && isRebootRequired()) return 'reboot flag';
    if (file_exists(ROOT . 'reboot')) return 'reboot flag';
    return 'stop requested';
}

function mqttLogStop(): void
{
    static $logged = false;
    if ($logged) return;
    $logged = true;
    $reason = mqttStopReason();
    echo date("H:i:s") . " stopping cycle (reason: " . $reason . ")" . PHP_EOL;
    DebMes('MQTT cycle stopped: ' . $reason, 'mqtt_runtime');
}

function mqttRunOnce()
{
    global $mqtt;
    global $stripmode;
    global $mqtt_delay;
    global $latest_data_received;
    global $max_no_data_timeout;
    global $mqtt_linked_paths;
    global $mqtt_repeating_cache;
    global $mqtt_integrations;
    global $mqtt_async;
    global $mqtt_runtime_index;
    global $mqtt_incoming_queue;
    global $mqtt_handlers_alive;
    global $mqtt_handlers_checked;

    $mqtt = new mqtt();
    $mqtt->getConfig();
    $mqtt_async = MqttRuntime::enabled();
    MqttSchema::ensure();
    if ($mqtt_async) {
        try {
            $mqtt_runtime_index = $mqtt->getRuntimeIndex();
            if (!$mqtt_runtime_index->ready()) {
                $mqtt_async = false;
                mqttRuntimeWarn('MQTT binding index is not ready, using sync path');
            }
        } catch (Throwable $error) {
            $mqtt_async = false;
            DebMes('MQTT binding index unavailable, using sync path: ' . $error->getMessage(), 'mqtt_runtime', 'warning');
        }
    }
    $mqtt_incoming_queue = null;
    $mqtt_handlers_alive = true;
    $mqtt_handlers_checked = 0;

    $client_name = !empty($mqtt->config['MQTT_CLIENT']) ? $mqtt->config['MQTT_CLIENT'] : "MajorDoMo MQTT Cycle";
    $client_name = $client_name . ' (#' . uniqid() . ')';

    $username = '';
    $password = '';
    if (!empty($mqtt->config['MQTT_AUTH'])) {
        $username = (string)($mqtt->config['MQTT_USERNAME'] ?? '');
        $password = (string)($mqtt->config['MQTT_PASSWORD'] ?? '');
    }

    $host = !empty($mqtt->config['MQTT_HOST']) ? $mqtt->config['MQTT_HOST'] : 'localhost';
    $port = !empty($mqtt->config['MQTT_PORT']) ? $mqtt->config['MQTT_PORT'] : 1883;
    $query = !empty($mqtt->config['MQTT_QUERY']) ? $mqtt->config['MQTT_QUERY'] : '/var/now/#';
    $stripmode = !empty($mqtt->config['MQTT_STRIPMODE']) ? (int)$mqtt->config['MQTT_STRIPMODE'] : 0;
    $mqtt_delay = isset($mqtt->config['MQTT_DELAY']) ? (int)$mqtt->config['MQTT_DELAY'] : 5;
    // В быстром пути привязки живут в версионированном индексе; старый снимок
    // нужен только синхронной обработке.
    $mqtt_linked_paths = $mqtt_async ? null : $mqtt->getLinkedPathsSnapshot(true);
    $mqtt_integrations = new MqttIntegrations();

    $mqtt_client = new Bluerhinos\phpMQTT($host, $port, $client_name);
    setGlobal('mqttConnected', 0, 1);
    $mqtt_client->setReadLimits(\MajorDoMo\Core\Config::int('MQTT_MAX_PACKET_BYTES'), \MajorDoMo\Core\Config::float('MQTT_PACKET_TIMEOUT'));
    try {
    if (!empty($mqtt->config['MQTT_AUTH'])) {
        $connect = $mqtt_client->connect(true, NULL, $username, $password);
    } else {
        $connect = $mqtt_client->connect();
    }
    if (!$connect) {
        throw new Exception("Failed to connect to MQTT broker {$host}:{$port}");
    }
    setGlobal('mqttConnected', 1, 1);

    $topics = array();
    $query_list = explode(',', $query);
    $integration_topics = $mqtt_integrations->subscriptions();
    $query_list = array_values(array_unique(array_merge($query_list, $integration_topics)));
    $total = count($query_list);
    echo date('H:i:s') . " Topics to watch: $query (Total: $total)\n";
    for ($i = 0; $i < $total; $i++) {
        $path = trim($query_list[$i]);
        if ($path == '') {
            continue;
        }
        echo date('H:i:s') . " Path: $path\n";
        $topics[$path] = array("qos" => 0, "function" => "procmsg");
    }
    foreach ($topics as $k => $v) {
        echo date('H:i:s') . " Subscribing to: $k\n";
        $mqtt_client->subscribe(array($k => $v), 0);
    }

    $latest_data_received = time();
    $previous_heartbeat = 0;
    while ($mqtt_client->proc()) {
        if ((time() - $latest_data_received) > $max_no_data_timeout) {
            setGlobal('cycle_mqttControl', 'restart');
        }
        // Shared transport also sends commands from built-in integrations.
        // В быстром пути публикацией занимается отдельный издатель, поэтому
        // приём не ждёт отправки и наоборот. Если издатель не подаёт признаков
        // жизни, приём временно сам разбирает очередь команд.
        if ((!$mqtt_async || !MqttRuntime::isAlive('publisher'))
            && ($integration_topics || (!empty($mqtt->config['MQTT_WRITE_METHOD']) && (int)$mqtt->config['MQTT_WRITE_METHOD'] === 2) || MqttRuntime::enabled())) {
        try {
            $queue = \MajorDoMo\Core\Core::queue()->claim('mqtt_queue', 100, 60);
            foreach ($queue as $mqtt_data) {
                $topic = $mqtt_data['DATANAME'];
                if ($topic == '') {
                    \MajorDoMo\Core\Core::queue()->acknowledge($mqtt_data);
                    continue;
                }
                $data_value = json_decode($mqtt_data['DATAVALUE'], true);
                $value = isset($data_value['v']) ? $data_value['v'] : '';
                $qos = isset($data_value['q']) ? (int)$data_value['q'] : 0;
                $retain = isset($data_value['r']) ? (int)$data_value['r'] : 0;
                if (isset($mqtt->config['DEBUG_MODE']) && (int)$mqtt->config['DEBUG_MODE']) {
                    echo "Publishing to $topic : $value\n";
                }
                $result = $mqtt_client->publish($topic, $value, $qos, $retain);
                if (!is_null($result) && !$result) {
                    \MajorDoMo\Core\Core::queue()->release($mqtt_data);
                    DebMes("Error writing from queue '$value' to $topic", 'mqtt_error');
                } else {
                    \MajorDoMo\Core\Core::queue()->acknowledge($mqtt_data);
                }
            }
        } catch (Throwable $e) {
            mqttRuntimeWarn('Error in MQTT queue processing: ' . $e->getMessage());
        }
        }

        if ((time() - $previous_heartbeat) >= 1) {
            $previous_heartbeat = time();
            // Bounded fingerprints only; inactive topics do not accumulate forever.
            foreach ($mqtt_repeating_cache as $topic_key => $cached) {
                if ($cached['expires'] < $previous_heartbeat) unset($mqtt_repeating_cache[$topic_key]);
            }
            if ($mqtt_async && $mqtt_runtime_index) {
                try {
                    // Версия привязок проверяется без переподключения к MQTT.
                    $mqtt_runtime_index->ensureFresh(1.0);
                } catch (Throwable $error) {
                    mqttRuntimeWarn('MQTT binding index refresh failed: ' . $error->getMessage());
                }
            }
            setGlobal(str_replace('.php', '', basename(__FILE__)) . 'Run', time(), 1);
            if (mqttShouldStop()) {
                mqttLogStop();
                $mqtt_client->close();
                return;
            }
        }
    }
    } finally {
        setGlobal('mqttConnected', 0, 1);
        $mqtt_client->close();
    }
}

$db->Disconnect(); // closing database connection
