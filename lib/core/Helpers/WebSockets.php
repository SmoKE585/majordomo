<?php

/** System helpers. Public names and argument defaults are part of the module API. */

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
            DebMes("Failed to connect to websocket", 'websockets', 'error');
            if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
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
            DebMes("Failed to send data to websocket", 'websockets', 'error');
            if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
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
            DebMes("Failed to reconnect to websocket", 'websockets', 'error');
            if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                echo date('Y-m-d H:i:s') . " Failed to reconnect to websocket\n";
            }
        }
    }

    return $data_sent;

}

function postToWebSocketQueue($property, $value, $post_action = 'PostProperty')
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::websockets()->post($property, $value, $post_action);
    }
