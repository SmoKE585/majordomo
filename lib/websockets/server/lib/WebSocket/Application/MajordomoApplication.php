<?php

namespace WebSocket\Application;

use MajorDoMo\Core\Config;

/**
 * Websocket-Server demo and test application.
 *
 * @author Simon Samtleben <web@lemmingzshadow.net>
 */
class MajordomoApplication extends Application
{
    private $_clients = array();
    private $_cachedProperties = array();
    private $_filename = '';
    private $_latestAlive = 0;
    private $_started;
    private array $cacheSizes = array();
    private array $cacheExpires = array();
    private int $cacheBytes = 0;
    private int $cacheMaxEntries;
    private int $cacheMaxBytes;
    private int $cacheTtl;
    private int $maxSubscriptions;
    
    public function __construct() {
        $this->_started = date("Y-m-d H:i:s");
        $this->cacheMaxEntries = Config::int('WEBSOCKETS_CACHE_MAX_ENTRIES');
        $this->cacheMaxBytes = Config::int('WEBSOCKETS_CACHE_MAX_BYTES');
        $this->cacheTtl = Config::int('WEBSOCKETS_CACHE_TTL');
        $this->maxSubscriptions = Config::int('WEBSOCKETS_MAX_SUBSCRIPTIONS');
        if (min($this->cacheMaxEntries, $this->cacheMaxBytes, $this->cacheTtl, $this->maxSubscriptions) < 1) {
            throw new \InvalidArgumentException('WebSocket cache and subscription limits must be positive');
        }
    }

    private function forgetCachedProperty(string $name): void
    {
        $this->cacheBytes -= $this->cacheSizes[$name] ?? 0;
        unset($this->_cachedProperties[$name], $this->cacheSizes[$name], $this->cacheExpires[$name]);
    }

    private function prunePropertyCache(): void
    {
        $now = time();
        foreach ($this->cacheExpires as $name => $expires) {
            if ($expires <= $now) $this->forgetCachedProperty($name);
        }
    }

    private function hasCachedProperty(string $name): bool
    {
        if (!array_key_exists($name, $this->_cachedProperties)) return false;
        if ($this->cacheExpires[$name] <= time()) {
            $this->forgetCachedProperty($name);
            return false;
        }
        // PHP arrays preserve insertion order: moving a hit to the end makes
        // eviction LRU without keeping a second list of values.
        $value = $this->_cachedProperties[$name];
        unset($this->_cachedProperties[$name]);
        $this->_cachedProperties[$name] = $value;
        return true;
    }

    private function cacheProperty(string $name, $value): void
    {
        $size = strlen($name) + (is_string($value) ? strlen($value) : strlen(json_encode($value, JSON_THROW_ON_ERROR)));
        $this->forgetCachedProperty($name);
        // Large values are still delivered live; only the cache is bounded.
        if ($size > $this->cacheMaxBytes) return;
        while ($this->_cachedProperties && (count($this->_cachedProperties) >= $this->cacheMaxEntries || $this->cacheBytes + $size > $this->cacheMaxBytes)) {
            $this->forgetCachedProperty(array_key_first($this->_cachedProperties));
        }
        $this->_cachedProperties[$name] = $value;
        $this->cacheSizes[$name] = $size;
        $this->cacheExpires[$name] = time() + $this->cacheTtl;
        $this->cacheBytes += $size;
    }

    public function onConnect($client)
    {
        $id = $client->getClientId();
        $client->subscribedTo['properties']['*'] = 1;
        $client->watchedProperties['*']['properties'] = 1;
        $this->_clients[$id] = $client;
        $this->updateClientsTotal();
        echo "Client connected (" . $client->getClientIp() . "). Total clients: " . count($this->_clients) . "\n";
    }

    public function onDisconnect($client)
    {
        $id = $client->getClientId();
        if (isset($this->_clients[$id])) {
            unset($this->_clients[$id]);
            $this->updateClientsTotal();
        }
        echo "Client dicconnected (" . $client->getClientIp() . "). Total clients: " . count($this->_clients) . "\n";

    }

    public function onData($data, $client)
    {
        $decodedData = $this->_decodeData($data);
        if ($decodedData === false) {
            // @todo: invalid request trigger error...
            return;
        }

        $actionName = isset($decodedData['action']) ? '_action' . ucfirst($decodedData['action']) : '';
        if (method_exists($this, $actionName)) {
            $action = isset($decodedData['action']) ? (string)$decodedData['action'] : '';
            $details = $this->getActionLogDetails($action, $decodedData['data']);
            if (function_exists('DebMes')) {
                DebMes(date('Y-m-d H:i:s') . ' websocket action start: ' . $action . ', client=' . $client->getClientIp() . ', ' . $details, 'websockets');
            }
            $GLOBALS['websockets_busy_info'] = 'action=' . $action . ', client=' . $client->getClientIp() . ', ' . $details;
            call_user_func(array($this, $actionName), $decodedData['data'], $client->getClientId());
            if (function_exists('DebMes')) {
                DebMes(date('Y-m-d H:i:s') . ' websocket action done: ' . $action . ', client=' . $client->getClientIp(), 'websockets');
            }
        }
    }

    private function getActionLogDetails($action, $data)
    {
        if ($action === 'PostProperty') {
            if (is_array($data) && isset($data['NAME'])) {
                return 'property=' . $data['NAME'];
            }
            if (is_array($data) && isset($data[0]['NAME'])) {
                return 'properties=' . count($data) . ', first=' . $data[0]['NAME'];
            }
            return 'post_property_payload=' . (is_array($data) ? count($data) : 0);
        }
        if ($action === 'PostEvent' && is_array($data) && isset($data['NAME'])) {
            return 'event=' . $data['NAME'];
        }
        if (is_array($data)) {
            return 'payload_items=' . count($data);
        }
        return 'payload_type=' . gettype($data);
    }

    public function onBinaryData($data, $client)
    {
        $filePath = substr(__FILE__, 0, strpos(__FILE__, 'server')) . 'tmp/';
        $putfileResult = false;
        if (!empty($this->_filename)) {
            $putfileResult = file_put_contents($filePath . $this->_filename, $data);
        }
        if ($putfileResult !== false) {

            $msg = 'File received. Saved: ' . $this->_filename;
        } else {
            $msg = 'Error receiving file.';
        }
        $client->send($this->_encodeData('echo', $msg));
        $this->_filename = '';
    }

    private function cycleAlive()
    {
        if ((time() - $this->_latestAlive) < 3) {
            return;
        }
        $this->_latestAlive = time();
        $this->prunePropertyCache();
        global $cycleName;
        if ($cycleName) {
            $checked_time = time();
            setGlobal($cycleName, $checked_time, 1);
            //saveToCache('MJD:ThisComputer.'.$cycleName, $checked_time);
            $this->updateClientsTotal();
            // send ping
            foreach ($this->_clients as $client){
                if ($client->getClientIp() != "127.0.0.1")
                    $client->send('ping', 'ping', false);
            }
        }
        global $websockets_script_started;
        if ($websockets_script_started > 0 && (time() - $websockets_script_started) > 6 * 60 * 60) {
            if (function_exists('DebMes')) {
                DebMes(date('Y-m-d H:i:s') . ' cycle_websockets exiting by scheduled 6h restart, pid=' . getmypid(), 'websockets');
            }
            exit; // restart every 6 hours
        }
    }

    private function updateClientsTotal()
    {
        $ws_clients_total = count($this->_clients);
        $old_value = gg('WSClientsTotal');
        if ($ws_clients_total != $old_value) {
            setGlobal('WSClientsTotal', $ws_clients_total, 1);
        }
    }

    private function _actionStatus($data, $client_id)
    {
        $this->cycleAlive();
        $status = [];
        $clients = [];
        $status["YOUR_ID"] = $client_id;
        $status["STARTED"] = $this->_started;
        $status["COUNT_CLIENTS"] = count($this->_clients);
        foreach ($this->_clients as $client) {
            $data = [];
            $data['ID'] = $client->getClientId();
            $data['IP'] = $client->getClientIp();
            $data['CONNECTED'] = $client->connected;
            $traffic = [];
            $traffic['IN_PACKET'] = $client->inPacket;
            $traffic['OUT_PACKET'] = $client->outPacket;
            $traffic['IN_BYTES'] = $client->inBytes;
            $traffic['OUT_BYTES'] = $client->outBytes;
            $data['TRAFFIC'] = $traffic;
            $data['SUBCRIBED_TO'] = $client->subscribedTo;
            $data['WATCHED_PROPERTIES'] = $client->watchedProperties;
            $clients[] = $data;
        }
        $status["CLIENTS"] = $clients;
        $status["COUNT_CACHED"] = count($this->_cachedProperties);
        $status["CACHED"] = $this->_cachedProperties;
        $encodedData = $this->_encodeData('status', json_encode($status));
        $this->_clients[$client_id]->send($encodedData);
    }

    private function clearSubscriptionType(object $client, string $type): void
    {
        unset($client->subscribedTo[$type]);
        if ($type === 'events') return;
        foreach ($client->watchedProperties as $name => &$watch) {
            unset($watch[$type]);
            if (!$watch) unset($client->watchedProperties[$name]);
        }
        unset($watch);
    }

    private function subscriptionNames($list): array
    {
        if (!is_string($list) && !is_numeric($list)) throw new \InvalidArgumentException('Subscription list must be a string');
        $names = explode(',', (string)$list, $this->maxSubscriptions + 1);
        if (count($names) > $this->maxSubscriptions) throw new \InvalidArgumentException('WebSocket subscription limit exceeded');
        return array_values(array_unique(array_filter(array_map('trim', $names), static fn($name) => $name !== '')));
    }

    private function prepareSubscription(array $data, $client_id): array
    {
        $type = $data['TYPE'] ?? '';
        if (!in_array($type, array('properties', 'objects', 'events'), true)) throw new \InvalidArgumentException('Unknown subscription type');
        $client = $this->_clients[$client_id];
        // Stage changes first: a rejected subscription must retain the old one.
        $pending = new \stdClass();
        $pending->subscribedTo = $client->subscribedTo;
        $pending->watchedProperties = $client->watchedProperties;
        if ($type === 'objects' || in_array($data['REPLACE'] ?? false, array(true, 1, '1'), true)) $this->clearSubscriptionType($pending, $type);
        $objectProperties = array();
        if ($type === 'objects') {
            $ids = $this->subscriptionNames($data['OBJECT_ID'] ?? '0');
            global $objects_module;
            $objectProperties = $objects_module->getWatchedProperties(implode(',', $ids) ?: '0');
            if (count($objectProperties) > $this->maxSubscriptions) throw new \InvalidArgumentException('WebSocket subscription limit exceeded');
            $pending->subscribedTo['objects']['OBJECT_ID'] = implode(',', $ids) ?: '0';
            foreach ($objectProperties as $property) $pending->watchedProperties[$property['PROPERTY']]['objects'][$property['OBJECT_ID']] = 1;
        } else {
            $names = $this->subscriptionNames($data[$type === 'properties' ? 'PROPERTIES' : 'EVENTS'] ?? '');
            if (!$names && $type === 'properties') $names = array('*');
            foreach ($names as $name) {
                $name = mb_strtolower($name, 'UTF-8');
                $pending->subscribedTo[$type][$name] = 1;
                if ($type === 'properties') $pending->watchedProperties[$name]['properties'] = 1;
            }
        }
        $count = count($pending->subscribedTo['events'] ?? array());
        foreach ($pending->watchedProperties as $watch) $count += count($watch['objects'] ?? array()) + (isset($watch['properties']) ? 1 : 0);
        if ($count > $this->maxSubscriptions) throw new \InvalidArgumentException('WebSocket subscription limit exceeded');
        $client->subscribedTo = $pending->subscribedTo;
        $client->watchedProperties = $pending->watchedProperties;
        return $objectProperties;
    }

    private function _actionUnsubscribe($data, $client_id)
    {
        if (!is_array($data) || !isset($this->_clients[$client_id])) return;
        $type = $data['TYPE'] ?? '';
        if (!in_array($type, array('properties', 'objects', 'events'), true)) return;
        $client = $this->_clients[$client_id];
        try {
            $names = $this->subscriptionNames($data[$type === 'properties' ? 'PROPERTIES' : 'EVENTS'] ?? '');
        } catch (\InvalidArgumentException $e) {
            DebMes($e->getMessage(), 'websockets', 'warning');
            return;
        }
        if ($type === 'objects' || !$names) {
            $this->clearSubscriptionType($client, $type);
        } else {
            foreach ($names as $name) {
                $name = mb_strtolower($name, 'UTF-8');
                unset($client->subscribedTo[$type][$name]);
                if ($type === 'properties') {
                    unset($client->watchedProperties[$name]['properties']);
                    if (empty($client->watchedProperties[$name])) unset($client->watchedProperties[$name]);
                }
            }
        }
        $client->send($this->_encodeData('unsubscribed', json_encode($data)));
    }

    private function _actionSubscribe($data, $client_id)
    {
        $this->cycleAlive();
        if (!is_array($data) || !isset($this->_clients[$client_id])) return;
        try {
            $objectProperties = $this->prepareSubscription($data, $client_id);
        } catch (\InvalidArgumentException $e) {
            DebMes($e->getMessage(), 'websockets', 'warning');
            $this->_clients[$client_id]->send($this->_encodeData('subscribed', json_encode(array('SUCCESS' => 0, 'ERROR' => $e->getMessage()))));
            return;
        }
        if ($data['TYPE']) {

            if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                DebMes($this->_clients[$client_id]->getClientIp() . " Subscription from client to " . $data['TYPE'] . "\n" . json_encode($data), 'websockets');
            }

            if ($data['TYPE'] == 'objects') {
                if ($data['OBJECT_ID'] == '') {
                    $data['OBJECT_ID'] = '0';
                }
                if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                    DebMes($this->_clients[$client_id]->getClientIp() . " Subscribing to object: " . $data['OBJECT_ID'], 'websockets');
                }
                $properties = $objectProperties;
                if (is_array($properties)) {
                    if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                        DebMes($this->_clients[$client_id]->getClientIp() . " Watching:\n" . json_encode($properties), 'websockets');
                    }
                }
            }

            if ($data['TYPE'] == 'properties') {
                $properties_list = isset($data['PROPERTIES']) ? trim((string)$data['PROPERTIES']) : '';
                if ($properties_list == '') {
                    if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                        DebMes($this->_clients[$client_id]->getClientIp() . " Subscribing to all properties", 'websockets');
                    }
                    $send_data = $data;
                    $send_data['PROPERTIES'] = '*';
                    $encodedData = $this->_encodeData('subscribed', json_encode($send_data));
                    $this->_clients[$client_id]->send($encodedData);
                    return;
                }
                if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                    DebMes($this->_clients[$client_id]->getClientIp() . " Subscribing to properties: " . $properties_list, 'websockets');
                }
                $tmp = explode(',', $properties_list);
                if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                    DebMes($this->_clients[$client_id]->getClientIp() . " Watching:\n" . json_encode($tmp), 'websockets');
                }
                $send_data = array();
                foreach ($tmp as $property) {
                    $property = trim($property);
                    if ($property === '') {
                        continue;
                    }
                    $property_name_lc = mb_strtolower($property, 'UTF-8');
                    // If only object name is passed (without Object.Property),
                    // do not query getGlobal(Object): it always produces empty values.
                    if (strpos($property_name_lc, '.') === false) {
                        continue;
                    }
                    if ($this->hasCachedProperty($property_name_lc)) {
                        $send_data[] = array('PROPERTY' => $property, 'VALUE' => $this->_cachedProperties[$property_name_lc]);
                    } else {
                        $send_data[] = array('PROPERTY' => $property, 'VALUE' => getGlobal($property));
                    }
                }
                if (isset($send_data[0])) {
                    if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                        DebMes($this->_clients[$client_id]->getClientIp() . " Sending cached properties\n" . json_encode($send_data), 'websockets');
                    }
                    $encodedData = $this->_encodeData('properties', json_encode($send_data));
                    $this->_clients[$client_id]->send($encodedData);
                }
                
            }

            if ($data['TYPE'] == 'events') {
                if ($data['EVENTS'] == '') {
                    return;
                }
                if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                    DebMes($this->_clients[$client_id]->getClientIp() . " Subscribing to events: " . $data['EVENTS'], 'websockets');
                }
                $tmp = explode(',', $data['EVENTS']);
                if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                    DebMes($this->_clients[$client_id]->getClientIp() . " Watching:\n" . json_encode($tmp), 'websockets');
                }
            }

            $send_data = $data;
            $encodedData = $this->_encodeData('subscribed', json_encode($send_data));
            $this->_clients[$client_id]->send($encodedData);
        }
    }

    private function _actionGetProperty($data, $client_id)
    {
        $this->_actionGetProperties($data, $client_id);
    }

    private function _actionGetProperties($data, $client_id)
    {
        $this->cycleAlive();
        $properties = $this->extractPropertyNames($data);
        if (!isset($properties[0])) {
            $this->sendActionResult($client_id, 'property_values', array(
                'SUCCESS' => 0,
                'ERROR' => 'Property name is required',
                'REQUEST_ID' => $this->getRequestId($data),
                'DATA' => array()
            ));
            return;
        }

        $send_data = array();
        foreach ($properties as $property) {
            $property_lc = mb_strtolower($property, 'UTF-8');
            if ($this->hasCachedProperty($property_lc)) {
                $value = $this->_cachedProperties[$property_lc];
            } else {
                $value = getGlobal($property);
            }
            $send_data[] = array('PROPERTY' => $property, 'VALUE' => $value);
        }

        $payload = array(
            'REQUEST_ID' => $this->getRequestId($data),
            'DATA' => $send_data
        );
        $encodedData = $this->_encodeData('property_values', json_encode($payload));
        $this->_clients[$client_id]->send($encodedData);
    }

    private function _actionGetValues($data, $client_id)
    {
        $this->_actionGetProperties($data, $client_id);
    }

    private function _actionSetGlobal($data, $client_id)
    {
        $this->cycleAlive();
        if (!is_array($data)) {
            $data = array();
        }
        $property = isset($data['PROPERTY']) ? $data['PROPERTY'] : (isset($data['NAME']) ? $data['NAME'] : '');
        $property = trim((string)$property);
        if ($property === '' || !array_key_exists('VALUE', $data)) {
            $this->sendActionResult($client_id, 'setGlobal', array(
                'SUCCESS' => 0,
                'ERROR' => 'Property name and VALUE are required',
                'REQUEST_ID' => $this->getRequestId($data)
            ));
            return;
        }

        $no_linked = isset($data['NO_LINKED']) ? (int)$data['NO_LINKED'] : 0;
        $source = isset($data['SOURCE']) ? (string)$data['SOURCE'] : 'websocket';
        try {
            $result = setGlobal($property, $data['VALUE'], $no_linked, $source);
            $this->sendActionResult($client_id, 'setGlobal', array(
                'SUCCESS' => 1,
                'PROPERTY' => $property,
                'VALUE' => $data['VALUE'],
                'RESULT' => $result,
                'REQUEST_ID' => $this->getRequestId($data)
            ));
        } catch (\Throwable $e) {
            $this->sendActionResult($client_id, 'setGlobal', array(
                'SUCCESS' => 0,
                'ERROR' => $e->getMessage(),
                'REQUEST_ID' => $this->getRequestId($data)
            ));
        } catch (\Exception $e) {
            $this->sendActionResult($client_id, 'setGlobal', array(
                'SUCCESS' => 0,
                'ERROR' => $e->getMessage(),
                'REQUEST_ID' => $this->getRequestId($data)
            ));
        }
    }

    private function _actionCallMethod($data, $client_id)
    {
        $this->cycleAlive();
        if (!is_array($data)) {
            $data = array();
        }
        $method = isset($data['METHOD']) ? $data['METHOD'] : (isset($data['NAME']) ? $data['NAME'] : '');
        $method = trim((string)$method);
        if ($method === '') {
            $this->sendActionResult($client_id, 'callMethod', array(
                'SUCCESS' => 0,
                'ERROR' => 'Method name is required',
                'REQUEST_ID' => $this->getRequestId($data)
            ));
            return;
        }

        $params = isset($data['PARAMS']) ? $data['PARAMS'] : 0;
        try {
            $result = callMethod($method, $params);
            $this->sendActionResult($client_id, 'callMethod', array(
                'SUCCESS' => 1,
                'METHOD' => $method,
                'RESULT' => $result,
                'REQUEST_ID' => $this->getRequestId($data)
            ));
        } catch (\Throwable $e) {
            $this->sendActionResult($client_id, 'callMethod', array(
                'SUCCESS' => 0,
                'ERROR' => $e->getMessage(),
                'REQUEST_ID' => $this->getRequestId($data)
            ));
        } catch (\Exception $e) {
            $this->sendActionResult($client_id, 'callMethod', array(
                'SUCCESS' => 0,
                'ERROR' => $e->getMessage(),
                'REQUEST_ID' => $this->getRequestId($data)
            ));
        }
    }

    private function _actionRunScript($data, $client_id)
    {
        $this->cycleAlive();
        if (!is_array($data)) {
            $data = array();
        }
        $script = isset($data['SCRIPT']) ? $data['SCRIPT'] : (isset($data['ID']) ? $data['ID'] : (isset($data['NAME']) ? $data['NAME'] : ''));
        $script = trim((string)$script);
        if ($script === '') {
            $this->sendActionResult($client_id, 'runScript', array(
                'SUCCESS' => 0,
                'ERROR' => 'Script id or title is required',
                'REQUEST_ID' => $this->getRequestId($data)
            ));
            return;
        }

        $params = isset($data['PARAMS']) ? $data['PARAMS'] : '';
        try {
            $result = runScript($script, $params);
            $this->sendActionResult($client_id, 'runScript', array(
                'SUCCESS' => 1,
                'SCRIPT' => $script,
                'RESULT' => $result,
                'REQUEST_ID' => $this->getRequestId($data)
            ));
        } catch (\Throwable $e) {
            $this->sendActionResult($client_id, 'runScript', array(
                'SUCCESS' => 0,
                'ERROR' => $e->getMessage(),
                'REQUEST_ID' => $this->getRequestId($data)
            ));
        } catch (\Exception $e) {
            $this->sendActionResult($client_id, 'runScript', array(
                'SUCCESS' => 0,
                'ERROR' => $e->getMessage(),
                'REQUEST_ID' => $this->getRequestId($data)
            ));
        }
    }

    private function extractPropertyNames($data)
    {
        $properties = array();
        if (isset($data['PROPERTIES'])) {
            if (is_array($data['PROPERTIES'])) {
                $properties = $data['PROPERTIES'];
            } else {
                $properties = explode(',', (string)$data['PROPERTIES']);
            }
        } elseif (isset($data['PROPERTY'])) {
            $properties = is_array($data['PROPERTY']) ? $data['PROPERTY'] : array($data['PROPERTY']);
        } elseif (isset($data['NAMES'])) {
            $properties = is_array($data['NAMES']) ? $data['NAMES'] : explode(',', (string)$data['NAMES']);
        } elseif (isset($data['NAME'])) {
            $properties = is_array($data['NAME']) ? $data['NAME'] : array($data['NAME']);
        } elseif (is_array($data)) {
            $properties = $data;
        }

        $result = array();
        foreach ($properties as $property) {
            if (!is_scalar($property)) {
                continue;
            }
            $property = trim((string)$property);
            if ($property !== '') {
                $result[] = $property;
            }
        }
        return $result;
    }

    private function sendActionResult($client_id, $action, $payload)
    {
        $encodedData = $this->_encodeData($action, json_encode($payload));
        $this->_clients[$client_id]->send($encodedData);
    }

    private function getRequestId($data)
    {
        if (is_array($data) && isset($data['REQUEST_ID'])) {
            return $data['REQUEST_ID'];
        }
        if (is_array($data) && isset($data['request_id'])) {
            return $data['request_id'];
        }
        return '';
    }

    private function _actionPostEvent($data)
    {
        $this->cycleAlive();
        if (IsSet($data['NAME'])) {
            $event_name = mb_strtolower($data['NAME'], 'UTF-8');
            if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                DebMes("Received event " . $event_name, 'websockets');
            }
            foreach ($this->_clients as $client) {
                if (IsSet($client->subscribedTo['events'][$event_name])) {
                    $send_data = array();
                    $send_data['EVENT_DATA'] = $data;
                    $encodedData = $this->_encodeData('events', json_encode($send_data));
                    $client->send($encodedData);
                }
            }

        }
    }

    private function _actionPostProperty($data)
    {
        $this->cycleAlive();

        $received_properties = array();
        $received_values = array();

        if (IsSet($data['NAME'])) {
            $received_properties[] = $data['NAME'];
            $received_values[$data['NAME']] = $data['VALUE'];
        } elseif (is_array($data[0]) && isset($data[0]['NAME'])) {
            $total = count($data);
            for ($i = 0; $i < $total; $i++) {
                $received_properties[] = $data[$i]['NAME'];
                $received_values[$data[$i]['NAME']] = $data[$i]['VALUE'];
            }
        }

        global $objects_module;


        foreach ($received_properties as $property_name) {
            $property_name_lc = mb_strtolower($property_name,'UTF-8');
            $property_value = $received_values[$property_name];
            if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                DebMes("Update property ".$property_name." => ".$property_value." (total cache:".count($this->_cachedProperties).")",'websockets');
            }
            $this->cacheProperty($property_name_lc, $property_value);
            //process property update
            $found_subscribers = 0;

            foreach ($this->_clients as $client) {
                $tmp = explode('.', $property_name_lc);
                if (IsSet($client->watchedProperties['*']['properties']) || IsSet($client->watchedProperties[$property_name_lc]) || IsSet($client->watchedProperties[$tmp[0]])) {
                    //objects
                    if (isset($client->watchedProperties[$property_name_lc]['objects'])) {
                        $send_values = array();
                        $seen_objects = array();
                        foreach ($client->watchedProperties[$property_name_lc]['objects'] as $k => $v) {
                            if (isset($seen_objects[$k])) {
                                continue;
                            }
                            $seen_objects[$k] = 1;
                            $item = $objects_module->processObject($k);
                            if (isset($item['HTML'])) {
                                $send_values[] = array('OBJECT_ID' => $item['OBJECT_ID'], 'DATA' => $item['HTML']);
                            }
                        }

                        if (isset($send_values[0])) {
                            $send_data = array('DATA' => $send_values);
                            if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                                DebMes($client->getClientIp() . " Sending updated object items\n" . json_encode($send_data), 'websockets');
                            }
                            $encodedData = $this->_encodeData('objects', json_encode($send_data));
                            $client->send($encodedData);
                        }
                    }

                    //properties
                    if (isset($client->watchedProperties['*']['properties']) || isset($client->watchedProperties[$property_name_lc]['properties'])) {
                        $send_data = array();
                        $send_data[] = array('PROPERTY' => $property_name, 'VALUE' => $property_value);
                        if (isset($send_data[0])) {
                            if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                                DebMes($client->getClientIp() . " Sending updated properties\n" . json_encode($send_data), 'websockets');
                            }
                            $encodedData = $this->_encodeData('properties', json_encode($send_data));
                            $client->send($encodedData);
                        }

                    }

                    //object properties
                    $tmp = explode('.', $property_name_lc);
                    if (isset($client->watchedProperties[$tmp[0]]['properties'])) {
                        $send_data = array();
                        $send_data[] = array('PROPERTY' => $property_name, 'VALUE' => $property_value);
                        if (isset($send_data[0])) {
                            if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                                DebMes($client->getClientIp() . " Sending updated properties\n" . json_encode($send_data), 'websockets');
                            }
                            $encodedData = $this->_encodeData('properties', json_encode($send_data));
                            $client->send($encodedData);
                        }
                    }
                }
            }

            if (!$found_subscribers) {
                if (defined('DEBUG_WEBSOCKETS') && DEBUG_WEBSOCKETS == 1) {
                    //DebMes("No subscribers for ".$property_name,'websockets');
                }
            }

        }

        if (file_exists('./reboot')) {
            if (function_exists('DebMes')) {
                DebMes(date('Y-m-d H:i:s') . ' cycle_websockets exiting by reboot flag, pid=' . getmypid(), 'websockets');
            }
            global $db;
            $db->Disconnect();
            exit;
        }


    }



    private function _actionEcho($text, $client_id)
    {
        $this->cycleAlive();
        $encodedData = $this->_encodeData('echo', $text);
        foreach ($this->_clients as $sendto) {
            echo date('Y-m-d H:i:s') . " Echo command: $text\n";
            $sendto->send($encodedData);
        }
    }

    private function _actionSetFilename($filename)
    {
        if (strpos($filename, '\\') !== false) {
            $filename = substr($filename, strrpos($filename, '\\') + 1);
        } elseif (strpos($filename, '/') !== false) {
            $filename = substr($filename, strrpos($filename, '/') + 1);
        }
        if (!empty($filename)) {
            $this->_filename = $filename;
            return true;
        }
        return false;
    }
}
