<?php

require_once __DIR__ . '/core.class.php';

function saveCycleToCache($key, $value)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->saveCycle($key, $value);
    }

function checkCycleFromCache($key)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->cycle($key);
    }

function ensureCachedValuesTable()
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->ensureSqlTable();
    }



/**
 * Summary of clearCacheData
 * @param mixed $prefix prefix
 * @return void
 */
function clearCacheData($prefix = '')
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->clear($prefix);
    }

/**
 *  Return all Cache Data from prefix
 * Summary of getAllCache
 * @param mixed $prefix
 * @return array
 */
function getAllCache($prefix = '')
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->all($prefix);
    }


/**
 * Summary of saveToCache
 * @param mixed $key Key
 * @param mixed $value Value
 * @return void
 */
function saveToCache($key, $value, $only_if_missing = false)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->set($key, $value, $only_if_missing);
    }

function deleteFromCache($key)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->delete($key);
    }

/**
 * Summary of checkFromCache
 * @param mixed $key Key
 * @return mixed
 */
function checkFromCache($key)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->get($key);
    }

function postToWebSocketQueue($property, $value, $post_action = 'PostProperty')
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::websockets()->post($property, $value, $post_action);
    }

function getWebSocketClientPath()
{
    $path = '/majordomo';
    $token = '';
    if (defined('SETTINGS_SYSTEM_WEBSOCKETS_TOKEN') && SETTINGS_SYSTEM_WEBSOCKETS_TOKEN != '') {
        $token = SETTINGS_SYSTEM_WEBSOCKETS_TOKEN;
    } elseif (defined('WEBSOCKETS_AUTH_TOKEN') && WEBSOCKETS_AUTH_TOKEN != '') {
        $token = WEBSOCKETS_AUTH_TOKEN;
    } elseif (defined('WEBSOCKETS_TOKEN') && WEBSOCKETS_TOKEN != '') {
        $token = WEBSOCKETS_TOKEN;
    }
    if ($token != '') {
        $path .= '?token=' . rawurlencode((string)$token);
    }
    return $path;
}

function postToWebSocket($property, $value, $post_action = 'PostProperty')
{

    if (defined('DISABLE_WEBSOCKETS') && DISABLE_WEBSOCKETS == 1) {
        return false;
    }

    global $websockets_script_started;
    if ($websockets_script_started) {
        return false;
    }

    require_once ROOT . 'lib/websockets/client/lib/class.websocket_client.php';

    global $wsClient;

    if (!Is_Object($wsClient)) {
        $wsClient = new WebsocketClient;
        if (!(@$wsClient->connect('127.0.0.1', WEBSOCKETS_PORT, getWebSocketClientPath()))) {
            $wsClient = false;
            if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                DebMes("Failed to connect to websocket");
                echo date('Y-m-d H:i:s') . " Failed to connect to websocket\n";
            }
        }
    }

    if (!Is_Object($wsClient) && isset($_SERVER['REQUEST_METHOD'])) {
        return false;
    }

    if (is_array($property) && is_array($value)) {
        $data = array();
        $total = count($property);
        for ($i = 0; $i < $total; $i++) {
            $data[] = array('NAME' => $property[$i], 'VALUE' => $value[$i]);
        }
    } else {
        $data = array('NAME' => $property, 'VALUE' => $value);
    }


    $payload = json_encode(array(
        'action' => $post_action,
        'data' => $data
    ));

    $data_sent = false;
    if (Is_Object($wsClient)) {
        $data_sent = @$wsClient->sendData($payload);
        if (!$data_sent) {
            if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                DebMes("Failed to send data to websocket");
                echo date('Y-m-d H:i:s') . " Failed to send data to websocket\n";
            }
        }
    }

    if (!$data_sent && !isset($_SERVER['REQUEST_METHOD'])) {
        //reconnect
        if (Is_Object($wsClient)) {
            $wsClient->disconnect();
        }
        $wsClient = new WebsocketClient;
        if ((@$wsClient->connect('127.0.0.1', WEBSOCKETS_PORT, getWebSocketClientPath()))) {
            $data_sent = @$wsClient->sendData($payload);
        } else {
            if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                DebMes("Failed to reconnect to websocket");
                echo date('Y-m-d H:i:s') . " Failed to reconnect to websocket\n";
            }
        }
    }

    return $data_sent;

}


function createHistoryTable($value_id)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::historyStorage()->table($value_id);
    }

function moveDataFromMainHistoryToTable($value_id)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::historyStorage()->moveFromMain($value_id);
    }

function moveDataFromTableToMainHistory($value_id)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::historyStorage()->moveToMain($value_id);
    }
