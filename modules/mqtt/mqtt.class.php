<?php
/**
 * MQTT
 * 2026-10-25 — отказ связанного свойства или метода не подтверждает входящее сообщение.
 *
 * Mqtt
 * 2026-10-22 — клиент MQTT загружается Composer без ручного include.
 * 2026-10-23 — добавлен быстрый путь: индекс привязок, состояние в Redis,
 *              отложенная проекция в MySQL и отдельная публикация.
 *
 * @package project
 * @author Serge J. <jey@tut.by>
 * @copyright http://www.atmatic.eu/ (c)
 * @version 0.1 (wizard, 13:07:08 [Jul 19, 2013])
 */
require_once __DIR__ . '/MqttUnlinkedThrottle.php';
require_once __DIR__ . '/MqttSchema.php';
require_once __DIR__ . '/MqttBindingIndex.php';
require_once __DIR__ . '/MqttRuntime.php';

class mqtt extends module
{
    protected $lookup_cache_ttl = 15;
    protected $lookup_cache_updated = 0;
    protected $lookup_by_path = array();
    protected $lookup_write_paths = array();
    protected $lookup_linked_paths = array();
    protected $setglobal_supports_source = null;
    protected static $unlinked_throttle = null;
    private ?MqttBindingIndex $runtime_index = null;
    private bool $config_loaded = false;
    /** Последние значения в памяти обработчика: путь => строка. */
    private array $runtime_last_values = array();
    private int $runtime_last_values_limit = 20000;
    /**
     * mqtt
     *
     * Module class constructor
     *
     * @access private
     */
    function __construct()
    {
        $this->name = "mqtt";
        $this->title = "<#LANG_MODULE_MQTT#>";
        $this->module_category = "<#LANG_SECTION_DEVICES#>";
        $this->checkInstalled();
    }

    /**
     * saveParams
     *
     * Saving module parameters
     *
     * @access public
     */
    function saveParams($data = 0)
    {
        $p = array();
        if (isset($this->id)) {
            $p["id"] = $this->id;
        }
        if (isset($this->view_mode)) {
            $p["view_mode"] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $p["edit_mode"] = $this->edit_mode;
        }
        if (isset($this->tab)) {
            $p["tab"] = $this->tab;
        }
        return parent::saveParams($p);
    }

    /**
     * getParams
     *
     * Getting module parameters from query string
     *
     * @access public
     */
    function getParams()
    {
        global $id;
        global $mode;
        global $view_mode;
        global $edit_mode;
        global $tab;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
    }

    /**
     * Run
     *
     * Description
     *
     * @access public
     */
    function run()
    {
        global $session;
        $out = array();
        $this->action = 'admin';
        $this->admin($out);
        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['TAB'] = $this->tab;
        if (isset($this->location_id)) {
            $out['IS_SET_LOCATION_ID'] = 1;
        }
        if ($this->single_rec) {
            $out['SINGLE_REC'] = 1;
        }
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;

    }

    function prepareQueueTable()
    {
        //SQLExec ("DROP TABLE IF EXISTS `mqtt_queue`;");
        $sqlQuery = "CREATE TABLE IF NOT EXISTS `mqtt_queue`
               (`ID`  INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `PATH` VARCHAR(255) NOT NULL,
                `VALUE` VARCHAR(255) NOT NULL,
                 PRIMARY KEY (`ID`)
               ) ENGINE = MEMORY DEFAULT CHARSET=utf8;";
        SQLExec($sqlQuery);
    }

    function invalidateLookupCache()
    {
        $this->lookup_cache_updated = 0;
        $this->lookup_by_path = array();
        $this->lookup_write_paths = array();
        $this->lookup_linked_paths = array();
        $this->runtime_index = null;
        if (class_exists('MqttRuntime') && MqttRuntime::enabled()) {
            // Версия в Redis: обработчики перечитают снимок без переподключения
            // к брокеру и без перезапуска цикла.
            MqttRuntime::invalidate();
        }
    }

    function getRuntimeIndex(): MqttBindingIndex
    {
        if ($this->runtime_index === null) {
            $this->runtime_index = new MqttBindingIndex(\MajorDoMo\Core\Core::redis());
        }
        $this->runtime_index->ensureFresh();
        return $this->runtime_index;
    }

    function loadConfigOnce()
    {
        if (!$this->config_loaded) {
            $this->getConfig();
            $this->config_loaded = true;
        }
        return $this->config;
    }

    private function rememberRuntimeValue(string $path, string $value): void
    {
        if (!isset($this->runtime_last_values[$path]) && count($this->runtime_last_values) >= $this->runtime_last_values_limit) {
            unset($this->runtime_last_values[array_key_first($this->runtime_last_values)]);
        }
        $this->runtime_last_values[$path] = $value;
    }

    private function runtimeOldValue(string $path, array $record): string
    {
        if (array_key_exists($path, $this->runtime_last_values)) {
            return (string)$this->runtime_last_values[$path];
        }
        $state = MqttRuntime::state($path);
        if ($state !== null) {
            $value = (string)($state['VALUE'] ?? '');
        } else {
            $value = (string)($record['VALUE'] ?? '');
            // Снимок не хранит значения обычных привязок: после перезапуска
            // Redis старое значение для метода читается один раз из SQL.
            if ($value === '' && !empty($record['ID']) && !empty($record['LINKED_METHOD'])) {
                $row = SQLSelectOne("SELECT VALUE FROM mqtt WHERE ID=" . (int)$record['ID']);
                $value = (string)($row['VALUE'] ?? '');
            }
        }
        $this->rememberRuntimeValue($path, $value);
        return $value;
    }

    function refreshLookupCache($force = false)
    {
        if (!$force && $this->lookup_cache_updated && (time() - $this->lookup_cache_updated) < $this->lookup_cache_ttl) {
            return;
        }
        $this->lookup_by_path = array();
        $this->lookup_write_paths = array();
        $this->lookup_linked_paths = array();
        $rows = SQLSelect("SELECT * FROM mqtt");
        foreach ($rows as $row) {
            if ($row['PATH'] !== '') {
                $this->lookup_by_path[$row['PATH']] = $row;
                if ($row['LINKED_OBJECT'] != '' && ($row['LINKED_PROPERTY'] != '' || $row['LINKED_METHOD'] != '')) {
                    $this->lookup_linked_paths[] = $row['PATH'];
                }
            }
            if ($row['PATH_WRITE'] !== '') {
                $this->lookup_write_paths[$row['PATH_WRITE']] = 1;
            }
        }
        $this->lookup_cache_updated = time();
    }

    function isPathWriteTopic($path)
    {
        $this->refreshLookupCache();
        return isset($this->lookup_write_paths[$path]);
    }

    function getRecordByPath($path)
    {
        $this->refreshLookupCache();
        if (isset($this->lookup_by_path[$path])) {
            return $this->lookup_by_path[$path];
        }
        return array();
    }

    function getLinkedPathsSnapshot($force = false)
    {
        $this->refreshLookupCache($force);
        return $this->lookup_linked_paths;
    }

    function hasLinkedPathWithPrefixInList($topic_prefix, $linked_paths)
    {
        foreach ($linked_paths as $linked_path) {
            if ($linked_path === $topic_prefix || strpos($linked_path, $topic_prefix . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    function isLinkedPathInList($path, $linked_paths)
    {
        return in_array($path, $linked_paths, true);
    }

    function getUnlinkedThrottle()
    {
        if (self::$unlinked_throttle === null) {
            self::$unlinked_throttle = new MqttUnlinkedThrottle(\MajorDoMo\Core\Core::redis());
        }
        return self::$unlinked_throttle;
    }

    function hasLinkedPathWithPrefix($topic_prefix)
    {
        $this->refreshLookupCache();
        return $this->hasLinkedPathWithPrefixInList($topic_prefix, $this->lookup_linked_paths);
    }

    function buildIncomingSource($topic, $msg)
    {
        return '/api.php/module/mqtt?topic=' . urlencode((string)$topic) . '&msg=' . urlencode((string)$msg) . '&no_session=1';
    }

    function decodeJsonObjectPayload($value)
    {
        if (!is_string($value)) {
            return false;
        }

        $payload = trim($value);
        if ($payload === '' || substr($payload, 0, 1) !== '{' || substr($payload, -1) !== '}') {
            return false;
        }

        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return false;
        }

        return $decoded;
    }

    function isLikelyJsonFragmentPath($path)
    {
        if (!is_string($path)) {
            return false;
        }

        return preg_match('/["{}]/', $path) && preg_match('/[:,]/', $path);
    }

    function setLinkedPropertyFromMqtt($object, $property, $value, $source_url)
    {
        if ($this->setglobal_supports_source === null) {
            $this->setglobal_supports_source = false;
            if (function_exists('setGlobal') && class_exists('ReflectionFunction')) {
                $rf = new ReflectionFunction('setGlobal');
                $this->setglobal_supports_source = ($rf->getNumberOfParameters() >= 4);
            }
        }

        if ($this->setglobal_supports_source) {
            $result = setGlobal($object . '.' . $property, $value, array('mqtt' => '0'), $source_url);
        } else {
            $has_request_uri = isset($_SERVER['REQUEST_URI']);
            $old_request_uri = $has_request_uri ? $_SERVER['REQUEST_URI'] : '';
            $_SERVER['REQUEST_URI'] = $source_url;
            try {
                $result = setGlobal($object . '.' . $property, $value, array('mqtt' => '0'));
            } finally {
                if ($has_request_uri) $_SERVER['REQUEST_URI'] = $old_request_uri;
                else unset($_SERVER['REQUEST_URI']);
            }
        }
        if ($result === false || $result === 0) throw new RuntimeException('Запись связанного свойства отклонена: ' . $object . '.' . $property);
    }

    function pathToTree($array)
    {
        $tree = array();
        foreach ($array as $item) {
            //$pathIds = explode("/", ltrim($item["PATH"], "/") . '/' . $item["ID"]);
            $pathIds = explode("/", $item["PATH"] . '/' . $item["ID"]);
            if ($pathIds[0] == '') {
                array_shift($pathIds);
                $pathIds[0] = '/' . $pathIds[0];
            }
            $current = &$tree;
            $cp = '';
            foreach ($pathIds as $id) {
                if (!isset($current["CHILDS"][$id])) {
                    $current["CHILDS"][$id] = array('CP' => $cp);
                }
                $current = &$current["CHILDS"][$id];
                if ($id == $item["ID"]) {
                    $current = $item;
                }
            }
        }
        return ($this->childsToArray($tree['CHILDS'], ''));
    }

    function childsToArray($items, $prev_path = '')
    {
        global $session;
        $res = array();
        foreach ($items as $k => $v) {
            if (!isset($v['PATH'])) {
                $v['TITLE'] = trim($k . ' ' . $v['CP']);
                $pp = $k;
            } else {
                $v['TITLE'] = '';
                $pp = '';
            }
            if (isset($v['CHILDS'])) {
                $items = $this->childsToArray($v['CHILDS'], $prev_path != '' ? $prev_path . '/' . $pp : $pp);
                $total = count($items);
                if ($total == 1) {
                    $v = $items[0];
                    $v['TITLE'] = $pp . ($v['TITLE'] != '' ? '/' . $v['TITLE'] : '');
                } else {
                    $max_updated = 0;
                    $device_title = '';
                    $device_id = 0;
                    for ($i = 0; $i < $total; $i++) {
                        if (isset($items[$i]['DEVICE_ID']) && $items[$i]['DEVICE_ID'] != '' && !$device_id) {
                            $device_id = $items[$i]['DEVICE_ID'];
                            $device_title = $items[$i]['DEVICE_TITLE'];
                        } elseif (isset($items[$i]['DEVICE_TITLE']) && $items[$i]['DEVICE_TITLE'] != '' && !$device_title) {
                            $device_id = 0;
                            $device_title = $items[$i]['DEVICE_TITLE'];
                        }
                        if (isset($items[$i]['UPDATED'])) {
                            $tm = strtotime($items[$i]['UPDATED']);
                            if (!isset($items[$i]['COLOR'])) {
                                if ((time() - $tm) <= 60 * 60) {
                                    $items[$i]['COLOR'] = 'green';
                                } elseif ((time() - $tm) <= 24 * 60 * 60) {
                                    $items[$i]['COLOR'] = 'black';
                                } else {
                                    $items[$i]['COLOR'] = '#aaaaaa';
                                }
                            }
                            if ($tm > $max_updated) {
                                $max_updated = $tm;
                            }
                        }
                    }
                    if ($device_id) {
                        $v['DEVICE_TITLE'] = $device_title;
                        $v['DEVICE_ID'] = $device_id;
                    } elseif ($device_title) {
                        $v['DEVICE_TITLE'] = $device_title;
                        $v['DEVICE_ID'] = 0;
                    }
                    $v['UPDATED'] = date('Y-m-d H:i:s', $max_updated);
                    if ((time() - $max_updated) <= 60 * 60) {
                        $v['COLOR'] = 'green';
                    } elseif ((time() - $max_updated) <= 24 * 60 * 60) {
                        $v['COLOR'] = 'black';
                    } else {
                        $v['COLOR'] = '#aaaaaa';
                    }
                    $v['RESULT'] = $items;
                }
                //$v['RESULT'] = $items;
                unset($v['CHILDS']);
            }
            if (isset($session->get('branches')[$v['TITLE']])) {
                $v['IS_VISIBLE'] = 1;
            }
            if (!isset($v['PATH'])) {
                if ($prev_path) {
                    $v['PATH'] = $prev_path . '/' . $v['TITLE'];
                } else {
                    $v['PATH'] = $v['TITLE'];
                }
            }
            $v['PATH_URL'] = urlencode(isset($v['PATH']) ? $v['PATH'] : '');
            $res[] = $v;
        }
        return $res;
    }


    function mqttPublish($topic, $value, $qos = 0, $retain = 0, $write_type = 0)
    {
        startMeasure('mqttPublish');
        $this->loadConfigOnce();
        $async = MqttRuntime::enabled();
        if ($async || ($write_type == 0 && !empty($this->config['MQTT_WRITE_METHOD']))) {
            // При быстром пути любая команда идёт через отдельный издатель:
            // автоматизация не ждёт ни соединения с брокером, ни пачки датчиков.
            $write_type = 2;
        }

        if ($write_type == 2) {
            $data = array('v' => $value);
            if ($qos) {
                $data['q'] = $qos;
            }
            if ($retain) {
                $data['r'] = $retain;
            }
            try {
                $queued = addToOperationsQueue('mqtt_queue', $topic, json_encode($data), true);
                if ($queued === false) throw new RuntimeException('MQTT command was not queued');
                MqttRuntime::count('commands_queued');
                endMeasure('mqttPublish');
                return 1;
            } catch (Throwable $error) {
                if ($async) {
                    // Redis недоступен: не теряем команду, уходим в прямой путь.
                    DebMes('MQTT queue unavailable, publishing directly: ' . $error->getMessage(), 'mqtt_error');
                    $write_type = 0;
                } else {
                    throw $error;
                }
            }
        }



        if (!empty($this->config['MQTT_CLIENT'])) {//
            $client_name = $this->config['MQTT_CLIENT'];
        } else {
            $client_name = "MajorDoMo MQTT";
        }

        $username = '';
        $password = '';
        if (!empty($this->config['MQTT_AUTH'])) {
            $username = (string)($this->config['MQTT_USERNAME'] ?? '');
            $password = (string)($this->config['MQTT_PASSWORD'] ?? '');
        }
        if (!empty($this->config['MQTT_HOST'])) {
            $host = $this->config['MQTT_HOST'];
        } else {
            $host = 'localhost';
        }
        if (!empty($this->config['MQTT_PORT'])) {
            $port = (int)$this->config['MQTT_PORT'];
        } else {
            $port = 1883;
        }

        // TLS/SSL configuration
        $tls_enabled = isset($this->config['MQTT_TLS_ENABLE']) ? (int)$this->config['MQTT_TLS_ENABLE'] : 0;
        $tls_ca_file = isset($this->config['MQTT_TLS_CERTPATH']) ? $this->config['MQTT_TLS_CERTPATH'] : '';
        $tls_ignore_cert = isset($this->config['MQTT_TLS_IGNORE_CERT']) ? (int)$this->config['MQTT_TLS_IGNORE_CERT'] : 0;

        // Create MQTT client with TLS support
        $mqtt_client = new Bluerhinos\phpMQTT($host, $port, $client_name . ' Client');
        $mqtt_client->setReadLimits(\MajorDoMo\Core\Config::int('MQTT_MAX_PACKET_BYTES'), \MajorDoMo\Core\Config::float('MQTT_PACKET_TIMEOUT'));

        // Set TLS context if TLS is enabled
        if ($tls_enabled) {
            $tls_context_options = array(
                'local_cert' => $tls_ca_file ? $tls_ca_file : false
            );
            
            if ($tls_ignore_cert) {
                // Ignore certificate validation (insecure, but sometimes needed for self-signed certs)
                $tls_context_options['verify_peer'] = false;
                $tls_context_options['verify_peer_name'] = false;
                $tls_context_options['allow_self_signed'] = true;
            }
            
            // Set TLS/SSL stream context
            $tls_context = stream_context_create(array('ssl' => $tls_context_options));
            
            if (method_exists($mqtt_client, 'setTlsContext')) {
                $mqtt_client->setTlsContext($tls_context);
            } elseif (property_exists($mqtt_client, 'tlsContext')) {
                $mqtt_client->tlsContext = $tls_context;
            }
        }

        if (!$mqtt_client->connect(true, NULL, $username, $password)) {
            DebMes("Error connecting with '$value' to $topic", 'mqtt_error');
            endMeasure('mqttPublish');
            return 0;
        }

        $result = $mqtt_client->publish($topic, $value, $qos, $retain);
        $mqtt_client->close();
        endMeasure('mqttPublish');
        if (!$result) {
            DebMes("Error writing '$value' to $topic", 'mqtt_error');
            return 0;
        }

    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function setProperty($id, $value, $set_linked = 0, $rec = null)
    {
        $original_value = $value;

        if (!is_array($rec)) {
            $rec = SQLSelectOne("SELECT * FROM mqtt WHERE ID='" . (int)$id . "'");
        }

        if (empty($rec['ID']) || $rec['PATH'] === '') {
            return 0;
        }

        $this->loadConfigOnce();
        $async = MqttRuntime::enabled();
        $debug = !empty($this->config['DEBUG_MODE']);

        if ($rec['REPLACE_LIST'] != '') {
            $list = explode(',', $rec['REPLACE_LIST']);
            foreach ($list as $pair) {
                $pair = trim($pair);
                list($new, $old) = explode('=', $pair);
                if ($value == $old) {
                    if ($rec['LOGGING']) {
                        DebMes("Replacing \"$value\" to \"$new\"", 'mqtt_topic_' . $rec['ID']);
                    }
                    $value = $new;
                    break;
                }
            }
        }

        $topic = $rec['PATH_WRITE'] ? $rec['PATH_WRITE'] : $rec['PATH'];
        $old_value = $async
            ? MqttRuntime::value($rec['PATH'], (string)($rec['VALUE'] ?? ''))
            : (string)($rec['VALUE'] ?? '');

        if ($rec['ONLY_NEW_VALUE'] && $old_value == $value) {
            if ($rec['LOGGING']) {
                DebMes("Ignoring \"$value\" = \"" . $old_value . "\"", 'mqtt_topic_' . $rec['ID']);
            }
            if ($rec['LOGGING'] || $debug) {
                $this->queueOutgoingHistory($rec, $topic, $value, $original_value, $async);
            }
            return 0;
        }

        $topic = $rec['PATH'];
        if ($rec['PATH_WRITE']) {
            $topic = $rec['PATH_WRITE'];
            if (preg_match('/^http:/', $rec['PATH_WRITE'])) {
                $url = $rec['PATH_WRITE'];
                $url = str_replace('%VALUE%', $value, $url);
                if ($rec['LOGGING']) {
                    DebMes("Publishing \"$value\" to url $url", 'mqtt_topic_' . $rec['ID']);
                }
                getURL($url, 0);
            } else {
                if ($rec['LOGGING']) {
                    DebMes("Publishing \"$value\" to write-path " . $rec['PATH_WRITE'], 'mqtt_topic_' . $rec['ID']);
                }
                $this->mqttPublish($rec['PATH_WRITE'], $value, (int)$rec['QOS'], (int)$rec['RETAIN'], (int)$rec['WRITE_TYPE']);
            }
        } else {
            if ($rec['LOGGING']) {
                DebMes("Publishing \"$value\" to " . $rec['PATH'], 'mqtt_topic_' . $rec['ID']);
            }
            $this->mqttPublish($rec['PATH'], $value, (int)$rec['QOS'], (int)$rec['RETAIN'], (int)$rec['WRITE_TYPE']);
        }

        $updated = date('Y-m-d H:i:s');
        if ($async) {
            // Значение уходит в проекцию; интерфейс читает его из Redis и не
            // ждёт следующей записи MySQL. Если Redis исчез после публикации,
            // команда уже отправлена — сохраняем состояние напрямую.
            try {
                MqttRuntime::stage(array('ID' => (int)$rec['ID'], 'PATH' => (string)$rec['PATH'],
                    'VALUE' => (string)$value, 'UPDATED' => $updated));
            } catch (Throwable $error) {
                DebMes('MQTT state projection unavailable, writing SQL directly: ' . $error->getMessage(), 'mqtt_runtime', 'warning');
                $rec['VALUE'] = $value . '';
                $rec['UPDATED'] = $updated;
                SQLUpdate('mqtt', $rec);
            }
        } else {
            $rec['VALUE'] = $value . '';
            $rec['UPDATED'] = $updated;
            SQLUpdate('mqtt', $rec);
        }

        if ($set_linked && $rec['LINKED_OBJECT'] && $rec['LINKED_PROPERTY']) {
            $currentValue = getGlobal($rec['LINKED_OBJECT'] . '.' . $rec['LINKED_PROPERTY']);
            if ($currentValue != $value) {
                if ($rec['LOGGING']) {
                    DebMes("Setting " . $rec['LINKED_OBJECT'] . '.' . $rec['LINKED_PROPERTY'] . " to \"$original_value\"", 'mqtt_topic_' . $rec['ID']);
                }
                setGlobal($rec['LINKED_OBJECT'] . '.' . $rec['LINKED_PROPERTY'], $original_value, array($this->name => '0'));
            }
        }

        if ($rec['LOGGING'] || $debug) {
            $this->queueOutgoingHistory($rec, $topic, $value, $original_value, $async, $updated);
        }
    }

    /** История команды: отдельное событие, даже если состояние объединяется. */
    private function queueOutgoingHistory(array $rec, string $topic, $value, $original_value, bool $async, ?string $updated = null): void
    {
        $hist = array();
        $hist['MQTT_ID'] = (int)$rec['ID'];
        $hist['DESTINATION'] = 1;
        $hist['TOPIC'] = $topic;
        $hist['DATA_PAYLOAD'] = (string)$value;
        $hist['VALUE'] = (string)$original_value;
        if (mb_strlen($hist['VALUE'], 'UTF-8') > 255) {
            $hist['VALUE'] = '[Data too large]';
        }
        $hist['UPDATED'] = $updated ?? date('Y-m-d H:i:s');
        if ($rec['LINKED_OBJECT'] && $rec['LINKED_PROPERTY']) {
            $hist['LINKED_DATA'] = $rec['LINKED_OBJECT'] . '.' . $rec['LINKED_PROPERTY'];
        }
        if ($async) {
            try {
                MqttRuntime::stageHistory($hist);
            } catch (Throwable $error) {
                DebMes('MQTT history queue unavailable, writing SQL directly: ' . $error->getMessage(), 'mqtt_runtime', 'warning');
                SQLInsert('mqtt_history', $hist);
            }
        } else {
            SQLInsert('mqtt_history', $hist);
        }
    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function processMessage($path, $value, $ignore_stripmode = false, $linked_paths = null)
    {
        if (preg_match('/\#$/', $path)) {
            return 0;
        }

        $original_value = $value;

        if ($value === false) $value = 0;
        elseif ($value === true) $value = 1;
        elseif ($value === null) $value = '';

        // The cycle supplies its snapshot; recursive JSON processing reuses it.
        if (!is_array($linked_paths)) {
            $this->getConfig();
            $linked_paths = $this->getLinkedPathsSnapshot();
        }

        if (preg_match("/^\\\\u\\d+/", $path)) {
            $path = json_decode('"' . $path . '"');
        }

        $is_linked = $this->isLinkedPathInList($path, $linked_paths);
        $stripmode = !$ignore_stripmode && !empty($this->config['MQTT_STRIPMODE']);
        $interval = isset($this->config['MQTT_UNLINKED_UPDATE_INTERVAL']) ? max(0, (int)$this->config['MQTT_UNLINKED_UPDATE_INTERVAL']) : 3600;
        $process_record = $is_linked || (!$stripmode && $this->getUnlinkedThrottle()->allow($path, $interval));
        if (!$process_record && !$this->hasLinkedPathWithPrefixInList($path, $linked_paths)) {
            return false;
        }

        $throttled = $process_record && !$is_linked && $interval > 0;
        try {
            $json_payload = $this->decodeJsonObjectPayload($value);
            if ($json_payload !== false) {
                foreach ($json_payload as $k => $v) {
                    if (is_array($v)) $v = json_encode($v);
                    $this->processMessage($path . '/' . $k, $v, $ignore_stripmode, $linked_paths);
                }
            }
            if (!$process_record) return false;

            $result = $this->processMessageValue($path, $value, $original_value, $this->buildIncomingSource($path, $original_value));
            if ($result === false && $throttled) $this->getUnlinkedThrottle()->cancel($path, $interval);
            return $result;
        } catch (Throwable $error) {
            if ($throttled) $this->getUnlinkedThrottle()->cancel($path, $interval);
            throw $error;
        }
    }

    function processMessageValue($path, $value, $original_value, $source_url)
    {
        startMeasure('mqttProcessMessage');

        if (preg_match("/^\\\\u\\d+/", $value)) {
            $value = json_decode('"' . $value . '"');
        }

        /* New query to search 'PATH_WRITE' record in db */
        if ($this->isPathWriteTopic($path)) { /* If path_write found in db */
            endMeasure('mqttProcessMessage');
            return false;
        }

        /* Search 'PATH' in database (db) */
        $rec = $this->getRecordByPath($path);
        $old_value = isset($rec['VALUE']) ? $rec['VALUE'] : '';

        if (empty($rec['ID'])) { /* If 'PATH' not found in db */
            if ($this->isLikelyJsonFragmentPath($path)) {
                endMeasure('mqttProcessMessage');
                return false;
            }
            /* Insert new record in db */
            $rec = array('LINKED_OBJECT' => '', 'LINKED_PROPERTY' => '', 'LINKED_METHOD' => '',
                'PATH_WRITE' => '', 'LOGGING' => 0, 'ONLY_NEW_VALUE' => 0, 'REPLACE_LIST' => '');
            $rec['PATH'] = $path;
            $rec['TITLE'] = $path;
            $rec['VALUE'] = $value . '';
            if (mb_strlen($rec['VALUE']) > 255) {
                $rec['VALUE'] = mb_substr($rec['VALUE'], 0, 255);
            }
            $rec['UPDATED'] = date('Y-m-d H:i:s');
            $rec['ID'] = SQLInsert('mqtt', $rec);
            if (!$rec['ID']) throw new RuntimeException('Unable to insert MQTT topic');
            // Discovery must not reload the entire table after every new topic.
            $this->lookup_by_path[$path] = $rec;
        } else {

            if ($rec['LOGGING']) {
                DebMes("Received \"$value\" from " . $path, 'mqtt_topic_' . $rec['ID']);
            }

            if (!$rec['ONLY_NEW_VALUE'] || ($value <> $old_value)) {

                /* Update values in db */
                $rec['VALUE'] = $value . '';
                if (mb_strlen($rec['VALUE']) > 255) {
                    $rec['VALUE'] = mb_substr($rec['VALUE'], 0, 255);
                }
                $rec['UPDATED'] = date('Y-m-d H:i:s');
                if ($rec['REPLACE_LIST'] != '') {
                    $list = explode(',', $rec['REPLACE_LIST']);
                    foreach ($list as $pair) {
                        $pair = trim($pair);
                        list($new, $old) = explode('=', $pair);
                        if ($value == $new) {
                            if ($rec['LOGGING']) {
                                DebMes("Replacing \"$value\" with \"$old\"", 'mqtt_topic_' . $rec['ID']);
                            }
                            $value = $old;
                            break;
                        }
                    }
                }

                if ($rec['LOGGING'] || (isset($this->config['DEBUG_MODE']) && $this->config['DEBUG_MODE'])) {
                    $hist = array();
                    $hist['MQTT_ID'] = $rec['ID'];
                    $hist['DESTINATION'] = 0;
                    $hist['TOPIC'] = $path;
                    $hist['DATA_PAYLOAD'] = $original_value;
                    $hist['VALUE'] = $value;
                    if (mb_strlen($hist['VALUE']) > 255) {
                        $hist['VALUE'] = '[Data too large]';
                    }
                    $hist['UPDATED'] = date('Y-m-d H:i:s');
                    if ($rec['LINKED_OBJECT'] && $rec['LINKED_PROPERTY']) {
                        $hist['LINKED_DATA'] = $rec['LINKED_OBJECT'] . '.' . $rec['LINKED_PROPERTY'];
                    }
                }


                /* Update property in linked object if it exist */
                if ($rec['LINKED_OBJECT'] && $rec['LINKED_PROPERTY']) {
                    if ($rec['LOGGING']) {
                        DebMes("Setting property " . $rec['LINKED_OBJECT'] . '.' . $rec['LINKED_PROPERTY'] . " to \"" . $value . "\"", 'mqtt_topic_' . $rec['ID']);
                    }
                    $this->setLinkedPropertyFromMqtt($rec['LINKED_OBJECT'], $rec['LINKED_PROPERTY'], $value, $source_url);
                }

                if ($rec['LINKED_OBJECT'] && $rec['LINKED_METHOD'] &&
                    !(strtolower($rec['LINKED_PROPERTY']) == 'status' && strtolower($rec['LINKED_METHOD']) == 'switch')) {
                    if ($rec['LOGGING']) {
                        DebMes("Calling method " . $rec['LINKED_OBJECT'] . '.' . $rec['LINKED_METHOD'], 'mqtt_topic_' . $rec['ID']);
                    }
                    $method_result = callMethod($rec['LINKED_OBJECT'] . '.' . $rec['LINKED_METHOD'], array(
                        'VALUE' => $rec['VALUE'],
                        'NEW_VALUE' => $rec['VALUE'],
                        'OLD_VALUE' => $old_value,
                        'SOURCE' => $path,
                        'TITLE' => $rec['TITLE'] ?: $path,
                    ));
                    if ($method_result === false) throw new RuntimeException('Связанный метод не выполнен: ' . $rec['LINKED_OBJECT'] . '.' . $rec['LINKED_METHOD']);
                }

                // Дедупликация видит новое состояние только после применения
                // привязок: отклонённый пакет должен оставаться повторяемым.
                if (!SQLUpdate('mqtt', array('ID' => $rec['ID'], 'VALUE' => $rec['VALUE'], 'UPDATED' => $rec['UPDATED']))) {
                    throw new RuntimeException('Unable to update MQTT topic');
                }
                $this->lookup_by_path[$path] = $rec;
                if (isset($hist)) SQLInsert('mqtt_history', $hist);

            } else {
                if ($rec['LOGGING']) {
                    DebMes("Ignoring \"$value\" (already existing)", 'mqtt_topic_' . $rec['ID']);
                }
                if ($rec['LOGGING'] || (isset($this->config['DEBUG_MODE']) && $this->config['DEBUG_MODE'])) {
                    $hist = array();
                    $hist['MQTT_ID'] = $rec['ID'];
                    $hist['DESTINATION'] = 0;
                    $hist['TOPIC'] = $path;
                    $hist['DATA_PAYLOAD'] = $original_value;
                    $hist['VALUE'] = $value;
                    if (mb_strlen($hist['VALUE']) > 255) {
                        $hist['VALUE'] = '[Data too large]';
                    }
                    $hist['UPDATED'] = date('Y-m-d H:i:s');
                    SQLInsert('mqtt_history', $hist);
                }
            }
        }
        endMeasure('mqttProcessMessage');
    }

    /**
     * Обработка сообщения в быстром пути: состояние пишется в Redis, MySQL
     * получает только проекцию и отдельные события истории.
     */
    function processRuntimeMessage($path, $value, $ignore_stripmode = false, ?MqttBindingIndex $index = null)
    {
        if (preg_match('/\#$/', $path)) {
            return 0;
        }

        $this->loadConfigOnce();
        $original_value = $value;
        if ($value === false) $value = 0;
        elseif ($value === true) $value = 1;
        elseif ($value === null) $value = '';

        $index = $index ?? $this->getRuntimeIndex();
        if (!$index->ready()) throw new RuntimeException('MQTT binding index is not ready');
        if (preg_match("/^\\\\u\\d+/", $path)) {
            $path = json_decode('"' . $path . '"');
        }

        $stripmode = !$ignore_stripmode && !empty($this->config['MQTT_STRIPMODE']);
        if ($stripmode && !$index->isLinkedOrPrefix($path)) {
            return false;
        }

        $is_linked = $index->isLinked($path);
        $interval = isset($this->config['MQTT_UNLINKED_UPDATE_INTERVAL'])
            ? max(0, (int)$this->config['MQTT_UNLINKED_UPDATE_INTERVAL']) : 3600;
        $process_record = $is_linked || (!$stripmode && $this->getUnlinkedThrottle()->allow($path, $interval));
        if (!$process_record && !$index->hasLinkedPrefix($path)) {
            return false;
        }

        $throttled = $process_record && !$is_linked && $interval > 0;
        try {
            $json_payload = $this->decodeJsonObjectPayload($value);
            if ($json_payload !== false) {
                foreach ($json_payload as $key => $child) {
                    if (is_array($child)) $child = json_encode($child);
                    $this->processRuntimeMessage($path . '/' . $key, $child, $ignore_stripmode, $index);
                }
            }
            if (!$process_record) return false;
            $result = $this->processRuntimeValue($path, $value, $original_value,
                $this->buildIncomingSource($path, $original_value), $index);
            if ($result === false && $throttled) $this->getUnlinkedThrottle()->cancel($path, $interval);
            return $result;
        } catch (Throwable $error) {
            if ($throttled) $this->getUnlinkedThrottle()->cancel($path, $interval);
            throw $error;
        }
    }

    function processRuntimeValue($path, $value, $original_value, $source_url, MqttBindingIndex $index)
    {
        startMeasure('mqttProcessMessage');
        if (preg_match("/^\\\\u\\d+/", $value)) {
            $value = json_decode('"' . $value . '"');
        }
        if ($index->isWritePath($path)) {
            endMeasure('mqttProcessMessage');
            return false;
        }

        $rec = $index->record($path);
        $debug = !empty($this->config['DEBUG_MODE']);
        $updated = date('Y-m-d H:i:s');

        if (empty($rec['ID'])) {
            if ($this->isLikelyJsonFragmentPath($path)) {
                endMeasure('mqttProcessMessage');
                return false;
            }
            MqttRuntime::stage(array('ID' => 0, 'PATH' => $path, 'VALUE' => (string)$value, 'UPDATED' => $updated));
            $this->rememberRuntimeValue($path, (string)$value);
            endMeasure('mqttProcessMessage');
            return true;
        }

        $old_value = $this->runtimeOldValue($path, $rec);

        if ($rec['ONLY_NEW_VALUE'] && $value == $old_value) {
            if ($rec['LOGGING']) {
                DebMes("Ignoring \"$value\" (already existing)", 'mqtt_topic_' . $rec['ID']);
            }
            if ($rec['LOGGING'] || $debug || $rec['LINKED_OBJECT']) {
                $this->stageIncomingHistory($rec, $path, $value, $original_value, $updated);
            }
            endMeasure('mqttProcessMessage');
            return true;
        }

        $raw_value = (string)$value;

        if ($rec['REPLACE_LIST'] != '') {
            $list = explode(',', $rec['REPLACE_LIST']);
            foreach ($list as $pair) {
                $pair = trim($pair);
                list($new, $old) = explode('=', $pair);
                if ($value == $new) {
                    if ($rec['LOGGING']) {
                        DebMes("Replacing \"$value\" with \"$old\"", 'mqtt_topic_' . $rec['ID']);
                    }
                    $value = $old;
                    break;
                }
            }
        }

        $is_bound = $rec['LINKED_OBJECT'] && ($rec['LINKED_PROPERTY'] || $rec['LINKED_METHOD']);

        if ($rec['LINKED_OBJECT'] && $rec['LINKED_PROPERTY']) {
            if ($rec['LOGGING']) {
                DebMes("Setting property " . $rec['LINKED_OBJECT'] . '.' . $rec['LINKED_PROPERTY'] . " to \"" . $value . "\"", 'mqtt_topic_' . $rec['ID']);
            }
            $started = microtime(true);
            $this->setLinkedPropertyFromMqtt($rec['LINKED_OBJECT'], $rec['LINKED_PROPERTY'], $value, $source_url);
            MqttRuntime::sample('property', (microtime(true) - $started) * 1000);
        }

        if ($rec['LINKED_OBJECT'] && $rec['LINKED_METHOD'] &&
            !(strtolower($rec['LINKED_PROPERTY']) == 'status' && strtolower($rec['LINKED_METHOD']) == 'switch')) {
            if ($rec['LOGGING']) {
                DebMes("Calling method " . $rec['LINKED_OBJECT'] . '.' . $rec['LINKED_METHOD'], 'mqtt_topic_' . $rec['ID']);
            }
            $method_result = callMethod($rec['LINKED_OBJECT'] . '.' . $rec['LINKED_METHOD'], array(
                'VALUE' => $value,
                'NEW_VALUE' => $value,
                'OLD_VALUE' => $old_value,
                'SOURCE' => $path,
                'TITLE' => $rec['TITLE'] ?: $path,
            ));
            if ($method_result === false) throw new RuntimeException('Связанный метод не выполнен: ' . $rec['LINKED_OBJECT'] . '.' . $rec['LINKED_METHOD']);
        }

        // Состояние и локальный кэш обновляем только после успешной записи
        // свойства/метода: иначе повтор после сбоя увидит «новое» значение и
        // ONLY_NEW_VALUE пропустит так и не применённое изменение.
        MqttRuntime::stage(array('ID' => (int)$rec['ID'], 'PATH' => $path, 'VALUE' => $raw_value, 'UPDATED' => $updated));
        $this->rememberRuntimeValue($path, $raw_value);

        // Событие истории ставим после успешной обработки: при повторе
        // сообщения не появится дубль уже записанного события.
        if ($rec['LOGGING'] || $debug || $is_bound) {
            $this->stageIncomingHistory($rec, $path, $value, $original_value, $updated);
        }

        endMeasure('mqttProcessMessage');
        return true;
    }

    private function stageIncomingHistory(array $rec, string $path, $value, $original_value, string $updated): void
    {
        $hist = array(
            'MQTT_ID' => (int)$rec['ID'],
            'DESTINATION' => 0,
            'TOPIC' => $path,
            'DATA_PAYLOAD' => (string)$original_value,
            'VALUE' => (string)$value,
            'UPDATED' => $updated,
        );
        if (mb_strlen($hist['VALUE'], 'UTF-8') > 255) {
            $hist['VALUE'] = '[Data too large]';
        }
        if ($rec['LINKED_OBJECT'] && $rec['LINKED_PROPERTY']) {
            $hist['LINKED_DATA'] = $rec['LINKED_OBJECT'] . '.' . $rec['LINKED_PROPERTY'];
        }
        MqttRuntime::stageHistory($hist);
    }

    /**
     * BackEnd
     *
     * Module backend
     *
     * @access public
     */
    function admin(&$out)
    {

        require_once(__DIR__ . '/mqtt_helpers.inc.php');

        if (gr('ajax')) {
            $op = gr('op');
            if ($op == 'values') {
                $ids = gr('ids');
                if (!is_array($ids)) {
                    $ids = $ids === '' ? array() : explode(',', (string)$ids);
                }
                $clean_ids = array();
                foreach ($ids as $item_id) {
                    $item_id = (int)$item_id;
                    if ($item_id > 0 && !in_array($item_id, $clean_ids, true)) {
                        $clean_ids[] = $item_id;
                    }
                }
                $data = array();
                if ($clean_ids) {
                    $rows = SQLSelect("SELECT ID, PATH, VALUE, UPDATED FROM mqtt WHERE ID IN (" . implode(',', array_slice($clean_ids, 0, 200)) . ")");
                    $rows = MqttRuntime::overlay($rows);
                    foreach ($rows as $row) {
                        $data[] = array(
                            'ID' => (int)$row['ID'],
                            'VALUE' => (string)$row['VALUE'],
                            'UPDATED' => (string)$row['UPDATED'],
                            'UPDATED_HUMAN' => md_mqtt_relative_time((string)$row['UPDATED']),
                            'FRESHNESS' => md_mqtt_freshness((string)$row['UPDATED']),
                        );
                    }
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array('DATA' => $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            if ($op == 'branch_status') {
                global $session;
                $status = (int)gr('status');
                $branch = $this->normalizeMqttTreePath(gr('branch'));
                if ($branch != '') {
                    $branches = $session->get('mqtt_tree_branches', array());
                    $legacyBranches = $session->get('branches', array());
                    if (!$status) {
                        unset($branches[$branch], $legacyBranches[$branch]);
                    } else {
                        $branches[$branch] = 1;
                    }
                    $session->set('mqtt_tree_branches', $branches);
                    $session->set('branches', $legacyBranches);
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array('OK' => 1));
            }
            if ($op == 'tree_children') {
                $root = gr('root');
                $nodes = $this->getMqttTreeChildren($root);
                $result = array(
                    'HTML' => $this->renderMqttTreeNodes($nodes),
                    'COUNT' => count($nodes)
                );
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($result);
            }
            exit;
        }

        if (isset($this->data_source) && !$_GET['data_source'] && !$_POST['data_source']) {
            $out['SET_DATASOURCE'] = 1;
        }

        $this->getConfig();
        if (empty($this->config['MQTT_HOST'])) {
            $this->config['MQTT_HOST'] = 'localhost';
        }
        if (empty($this->config['MQTT_PORT'])) {
            $this->config['MQTT_PORT'] = '1883';
        }
        if (empty($this->config['MQTT_QUERY'])) {
            $this->config['MQTT_QUERY'] = '/var/now/#';
        }

        $out['MQTT_CLIENT'] = md_mqtt_h($this->config['MQTT_CLIENT']);
        $out['MQTT_HOST'] = md_mqtt_h($this->config['MQTT_HOST']);
        $out['MQTT_PORT'] = md_mqtt_h($this->config['MQTT_PORT']);
        $out['MQTT_QUERY'] = md_mqtt_h($this->config['MQTT_QUERY']);
        $out['MQTT_DELAY'] = md_mqtt_h(isset($this->config['MQTT_DELAY']) ? $this->config['MQTT_DELAY'] : '');
        $out['MQTT_WRITE_METHOD'] = isset($this->config['MQTT_WRITE_METHOD']) ? (int)$this->config['MQTT_WRITE_METHOD'] : 0;
        $out['MQTT_STRIPMODE'] = isset($this->config['MQTT_STRIPMODE']) ? (int)$this->config['MQTT_STRIPMODE'] : 0;
        $out['MQTT_UNLINKED_UPDATE_INTERVAL'] = isset($this->config['MQTT_UNLINKED_UPDATE_INTERVAL']) ? (int)$this->config['MQTT_UNLINKED_UPDATE_INTERVAL'] : 3600;
        $out['DEBUG_MODE'] = isset($this->config['DEBUG_MODE']) ? (int)$this->config['DEBUG_MODE'] : 0;
        // Быстрый путь и очередь настраиваются в «Общих настройках» (.env),
        // но на странице MQTT полезно видеть текущие значения и состояние.
        if ($this->view_mode == 'settings' || $this->view_mode == 'update_settings') {
            $out['RUNTIME_ASYNC'] = MqttRuntime::enabled() ? 1 : 0;
            $out['RUNTIME_HANDLERS'] = MqttRuntime::handlers();
            $out['RUNTIME_LEASE'] = max(30, \MajorDoMo\Core\Config::int('MQTT_HANDLER_LEASE', 120));
            $out['RUNTIME_ATTEMPTS'] = max(2, \MajorDoMo\Core\Config::int('MQTT_MAX_ATTEMPTS', 5));
            $out['RUNTIME_CORE_WORKERS'] = max(1, min(16, \MajorDoMo\Core\Config::int('CORE_WORKERS', 3)));
            $out['RUNTIME_HANDLERS_ALIVE'] = MqttRuntime::handlersAlive() ? 1 : 0;
            $out['RUNTIME_PUBLISHER_ALIVE'] = MqttRuntime::isAlive('publisher') ? 1 : 0;
        }

        // TLS/SSL configuration output
        $out['MQTT_TLS_ENABLE'] = isset($this->config['MQTT_TLS_ENABLE']) ? (int)$this->config['MQTT_TLS_ENABLE'] : 0;
        $out['MQTT_TLS_CERTPATH'] = md_mqtt_h(isset($this->config['MQTT_TLS_CERTPATH']) ? $this->config['MQTT_TLS_CERTPATH'] : '');
        $out['MQTT_TLS_IGNORE_CERT'] = isset($this->config['MQTT_TLS_IGNORE_CERT']) ? (int)$this->config['MQTT_TLS_IGNORE_CERT'] : 0;

        $out['MQTT_USERNAME'] = md_mqtt_h(isset($this->config['MQTT_USERNAME']) ? $this->config['MQTT_USERNAME'] : '');
        $out['MQTT_PASSWORD'] = md_mqtt_h(isset($this->config['MQTT_PASSWORD']) ? $this->config['MQTT_PASSWORD'] : '');
        $out['MQTT_AUTH'] = !empty($this->config['MQTT_AUTH']) ? 1 : 0;

        $ok_msg = gr('ok_msg');
        if ($ok_msg !== '') {
            $out['OK_MSG'] = md_mqtt_h($ok_msg);
        }

        if ($this->view_mode == 'update_settings') {

            $this->config['MQTT_CLIENT'] = gr('mqtt_client');
            $this->config['MQTT_HOST'] = gr('mqtt_host');
            $this->config['MQTT_USERNAME'] = gr('mqtt_username');
            $this->config['MQTT_PASSWORD'] = gr('mqtt_password');
            $this->config['MQTT_AUTH'] = gr('mqtt_auth', 'int');
            $this->config['MQTT_PORT'] = gr('mqtt_port', 'int');
            $this->config['MQTT_QUERY'] = gr('mqtt_query');
            $this->config['MQTT_WRITE_METHOD'] = gr('mqtt_write_method', 'int');
            $this->config['MQTT_STRIPMODE'] = gr('mqtt_stripmode', 'int');
            $this->config['MQTT_UNLINKED_UPDATE_INTERVAL'] = max(0, gr('mqtt_unlinked_update_interval', 'int'));
            $this->config['DEBUG_MODE'] = gr('debug_mode', 'int');
            
            // TLS/SSL configuration update
            $this->config['MQTT_TLS_ENABLE'] = gr('mqtt_tls_enable', 'int');
            $this->config['MQTT_TLS_CERTPATH'] = gr('mqtt_tls_certpath');
            $this->config['MQTT_TLS_IGNORE_CERT'] = gr('mqtt_tls_ignore_cert', 'int');

            if (!$this->config['DEBUG_MODE']) {
                SQLExec("TRUNCATE mqtt_history;");
            }

            $mqtt_delay = gr('mqtt_delay');
            if ($mqtt_delay === '') {
                unset($this->config['MQTT_DELAY']);
            } else {
                $this->config['MQTT_DELAY'] = (int)$mqtt_delay;
            }

            $this->saveConfig();

            setGlobal('cycle_mqttControl', 'restart');
            // Отдельный супервизор держит обработчиков и издателя: смена
            // брокера/числа обработчиков должна перезапустить и его.
            setGlobal('cycle_mqtt_runtimeControl', 'restart');

            $this->redirect("?view_mode=settings&ok_msg=" . urlencode(LANG_DATA_SAVED));
        }

        if (!$this->config['MQTT_HOST']) {
            $this->config['MQTT_HOST'] = 'localhost';
            $this->saveConfig();
        }
        if (!$this->config['MQTT_PORT']) {
            $this->config['MQTT_PORT'] = '1883';
            $this->saveConfig();
        }
        if (!$this->config['MQTT_QUERY']) {
            $this->config['MQTT_QUERY'] = '/var/now/#';
            $this->saveConfig();
        }


        if ($this->view_mode == 'settings' || $this->view_mode == 'update_settings') {
            $out['SETTINGS_VIEW'] = 1;
        } elseif ($this->data_source == 'mqtt' || $this->data_source == '') {
            if ($this->view_mode == 'edit_mqtt') {
                $this->edit_mqtt($out, $this->id);
            } elseif ($this->view_mode == '' || $this->view_mode == 'search_mqtt') {
                $this->search_mqtt($out);
            }

            if ($this->view_mode == 'delete_path') {
                $this->delete_mqtt_path(gr('path'));
                $this->redirect(ROOTHTML . "panel/mqtt.html?ok_msg=" . urlencode('Ветка топиков удалена'));
            }
            if ($this->view_mode == 'delete_mqtt') {
                $this->delete_mqtt($this->id);
                $this->redirect(ROOTHTML . "panel/mqtt.html?ok_msg=" . urlencode('Топик удалён'));
            }
            if ($this->view_mode == 'clear_trash') {
                $this->clear_trash();
                $this->redirect(ROOTHTML . "panel/mqtt.html?ok_msg=" . urlencode('Неиспользуемые топики удалены'));
            }

            if ($this->view_mode == 'optimize') {
                $this->optimize();
                $this->redirect(ROOTHTML . "panel/mqtt.html?ok_msg=" . urlencode('История MQTT оптимизирована'));
            }

        }
    }

    function getBreadcrumbs($path) {
        $tmp = explode('/', $path);
        $list_path = '';
        $breadcrumbs = '';
        foreach ($tmp as $word) {
            $parent_rec = array('ID' => 0);
            if ($word == '') {
                $list_path .= '/';
                continue;
            }
            $list_path .= $word;
            $parent_tmp = SQLSelectOne("SELECT ID FROM mqtt WHERE PATH='" . DBSafe($list_path) . "'");
            $list_path .= '/';
            if (isset($parent_tmp['ID'])) {
                $parent_rec['ID'] = $parent_tmp['ID'];
            }
            if ($parent_rec['ID']) {
                $breadcrumbs .= '<a href="' . ROOTHTML . 'panel/mqtt.html?view_mode=edit_mqtt&id=' . $parent_rec['ID'] . '">' . $word . '</a> / ';
            } else {
                $breadcrumbs .= '<a href="' . ROOTHTML . 'panel/mqtt.html?root=' . urlencode($list_path) . '">' . $word . '</a> / ';
            }
        }
        return $breadcrumbs;
    }

    function normalizeMqttTreePath($path)
    {
        $path = trim((string)$path);
        $path = trim($path, '/');
        $path = preg_replace('/\/+/', '/', $path);
        return $path;
    }

    function isMqttTreeBranchOpen($path)
    {
        global $session;
        $path = $this->normalizeMqttTreePath($path);
        if ($path == '' || !$session instanceof \MajorDoMo\Core\Http\Session) {
            return false;
        }

        if ($session->has('mqtt_tree_branches') && is_array($session->get('mqtt_tree_branches')) && isset($session->get('mqtt_tree_branches')[$path])) {
            return true;
        }

        return $session->has('branches') && is_array($session->get('branches')) && isset($session->get('branches')[$path]);
    }

    function getMqttTreeChildren($root = '', $limit = 200)
    {
        $root = $this->normalizeMqttTreePath($root);
        $path_expr = "TRIM(BOTH '/' FROM PATH)";
        if ($root == '') {
            $where = "$path_expr!=''";
            $rest_expr = $path_expr;
        } else {
            $where = "$path_expr LIKE '" . DBSafe($root . '/') . "%'";
            $rest_expr = "SUBSTRING($path_expr," . (strlen($root) + 2) . ")";
        }
        $title = gr('title');
        if ($title != '') {
            $where .= " AND (TITLE LIKE '%" . DBSafe($title) . "%' OR VALUE LIKE '%" . DBSafe($title) . "%' OR PATH LIKE '%" . DBSafe($title) . "%'";
            $where .= " OR LINKED_OBJECT LIKE '" . DBSafe($title) . "'";
            $where .= " OR LINKED_PROPERTY LIKE '" . DBSafe($title) . "'";
            $where .= " OR LINKED_METHOD LIKE '" . DBSafe($title) . "'";
            $where .= ")";
        }
        $searchpath = gr('searchpath');
        if ($searchpath != '') {
            $where .= " AND (TITLE LIKE '%" . DBSafe($searchpath) . "%' OR VALUE LIKE '%" . DBSafe($searchpath) . "%' OR PATH LIKE '%" . DBSafe($searchpath) . "%'";
            $where .= " OR LINKED_OBJECT LIKE '" . DBSafe($searchpath) . "'";
            $where .= " OR LINKED_PROPERTY LIKE '" . DBSafe($searchpath) . "'";
            $where .= " OR LINKED_METHOD LIKE '" . DBSafe($searchpath) . "'";
            $where .= ")";
        }
        $location_id = gr('location_id', 'int');
        if ($location_id) {
            $where .= " AND LOCATION_ID='" . (int)$location_id . "'";
        }

        $segments = SQLSelect("SELECT DISTINCT SUBSTRING_INDEX($rest_expr,'/',1) AS SEGMENT FROM mqtt WHERE $where HAVING SEGMENT!='' ORDER BY SEGMENT LIMIT " . ((int)$limit + 1));
        $total = count($segments);
        if ($total > $limit) {
            array_pop($segments);
        }

        $nodes = array();
        foreach ($segments as $segment_rec) {
            $segment = $segment_rec['SEGMENT'];
            $path = ($root == '') ? $segment : $root . '/' . $segment;
            $path_safe = DBSafe($path);
            $rec = SQLSelectOne("SELECT ID, PATH, TITLE, LEFT(VALUE, 2048) AS VALUE, LINKED_OBJECT, LINKED_PROPERTY, LINKED_METHOD FROM mqtt WHERE $path_expr='" . $path_safe . "' ORDER BY ID DESC LIMIT 1");
            $has_children = SQLSelectOne("SELECT ID FROM mqtt WHERE $path_expr LIKE '" . DBSafe($path . '/') . "%' LIMIT 1");

            if (!isset($rec['ID']) && !isset($has_children['ID'])) {
                continue;
            }

            $is_branch = isset($has_children['ID']) ? 1 : 0;
            $title = $segment;
            if (!$is_branch && isset($rec['TITLE']) && $rec['TITLE'] != '') {
                $title = $rec['TITLE'];
            }

            $node = array(
                'ID' => isset($rec['ID']) ? $rec['ID'] : '',
                'TITLE' => $title,
                'PATH' => isset($rec['PATH']) && $rec['PATH'] != '' ? $rec['PATH'] : $path,
                'TREE_PATH' => $path,
                'PATH_URL' => urlencode(isset($rec['PATH']) && $rec['PATH'] != '' ? $rec['PATH'] : $path),
                'VALUE' => isset($rec['VALUE']) ? str_replace('":', '": ', $rec['VALUE']) : '',
                'LINKED_OBJECT' => isset($rec['LINKED_OBJECT']) ? $rec['LINKED_OBJECT'] : '',
                'LINKED_PROPERTY' => isset($rec['LINKED_PROPERTY']) ? $rec['LINKED_PROPERTY'] : '',
                'LINKED_METHOD' => isset($rec['LINKED_METHOD']) ? $rec['LINKED_METHOD'] : '',
                'HAS_CHILDREN' => $is_branch,
                'IS_VISIBLE' => $is_branch && $this->isMqttTreeBranchOpen($path) ? 1 : 0
            );
            $nodes[] = $node;
        }

        if ($total > $limit) {
            $nodes[] = array(
                'TITLE' => 'Показаны первые ' . (int)$limit . ' веток. Уточните фильтр или корневой раздел.',
                'IS_NOTICE' => 1
            );
        }

        return $nodes;
    }

    function mqttTreeHtml($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    function renderMqttTreeNodes($nodes)
    {
        $html = '';
        $delete_label = defined('LANG_DELETE') ? LANG_DELETE : 'Delete';
        foreach ($nodes as $node) {
            if (isset($node['IS_NOTICE'])) {
                $html .= '<div class="md-admin-empty-state">' . $this->mqttTreeHtml($node['TITLE']) . '</div>';
                continue;
            }

            $title = $this->mqttTreeHtml($node['TITLE'] != '' ? $node['TITLE'] : '[..]');
            $path = $this->mqttTreeHtml($node['PATH']);
            $tree_path = $this->mqttTreeHtml($node['TREE_PATH']);
            $path_url = $this->mqttTreeHtml($node['PATH_URL']);
            $id = isset($node['ID']) ? (int)$node['ID'] : 0;
            $has_children = !empty($node['HAS_CHILDREN']);
            $is_open = !empty($node['IS_VISIBLE']);

            if ($has_children) {
                $expanded = $is_open ? 'true' : 'false';
                $toggle_title = $is_open ? 'Свернуть ветку' : 'Развернуть ветку';
                $children_hidden = $is_open ? '' : ' hidden';
                $children_html = '';
                if ($is_open) {
                    $children_html = $this->renderMqttTreeNodes($this->getMqttTreeChildren($node['TREE_PATH']));
                }

                $html .= '<section class="md-mqtt-tree__branch" title="' . $path . '" data-md-mqtt-tree-branch data-md-mqtt-tree-path="' . $tree_path . '" data-md-mqtt-tree-loaded="' . ($is_open ? '1' : '0') . '">';
                $html .= '<div class="md-mqtt-tree__row">';
                $html .= '<button type="button" class="md-mqtt-tree__toggle" data-md-mqtt-tree-toggle aria-expanded="' . $expanded . '" aria-label="' . $this->mqttTreeHtml($toggle_title) . '" title="' . $this->mqttTreeHtml($toggle_title) . '"><i class="glyphicon glyphicon-chevron-right"></i></button>';
                $html .= '<div class="md-mqtt-tree__content">';
                if ($id) {
                    $html .= '<a href="?view_mode=edit_mqtt&id=' . $id . '" data-md-mqtt-tree-edit="' . $id . '" title="' . $path . '" class="md-mqtt-tree__title">' . $title . '</a>';
                    $html .= $this->renderMqttTreeMeta($node);
                } else {
                    $html .= '<div class="md-mqtt-tree__branch-title">' . $title . '</div>';
                }
                $html .= '</div>';
                if ($id) {
                    $html .= '<a href="#" class="md-mqtt-tree__delete" data-md-mqtt-tree-delete="' . $path_url . '" aria-label="' . $this->mqttTreeHtml($delete_label) . '"><i class="glyphicon glyphicon-remove"></i></a>';
                }
                $html .= '</div>';
                $html .= '<div class="md-mqtt-tree__children"' . $children_hidden . '>' . $children_html . '</div>';
                $html .= '</section>';
            } else {
                $html .= '<article class="md-mqtt-tree__node is-leaf" title="' . $path . '">';
                $html .= '<div class="md-mqtt-tree__row">';
                $html .= '<span class="md-mqtt-tree__toggle--leaf" aria-hidden="true"><i class="glyphicon glyphicon-record"></i></span>';
                $html .= '<div class="md-mqtt-tree__content">';
                $html .= '<a href="?view_mode=edit_mqtt&id=' . $id . '" data-md-mqtt-tree-edit="' . $id . '" title="' . $path . '" class="md-mqtt-tree__title">' . $title . '</a>';
                $html .= $this->renderMqttTreeMeta($node);
                $html .= '</div>';
                $html .= '<a href="#" class="md-mqtt-tree__delete" data-md-mqtt-tree-delete="' . $path_url . '" aria-label="' . $this->mqttTreeHtml($delete_label) . '"><i class="glyphicon glyphicon-remove"></i></a>';
                $html .= '</div>';
                $html .= '</article>';
            }
        }
        return $html;
    }

    function renderMqttTreeMeta($node)
    {
        $id = isset($node['ID']) ? (int)$node['ID'] : 0;
        if (!$id) {
            return '';
        }
        $value = isset($node['VALUE']) ? (string)$node['VALUE'] : '';
        $has_linked = isset($node['LINKED_OBJECT']) && $node['LINKED_OBJECT'] != '';
        if ($value === '' && !$has_linked) {
            return '';
        }
        $html = '<div class="md-mqtt-tree__meta">';
        if ($value !== '') {
            $html .= '<span id="mqtt' . $id . '" class="mqtt_value md-mqtt-tree__value">' . $this->mqttTreeHtml($value) . '</span>';
        }
        if ($has_linked) {
            $linked_type = isset($node['LINKED_PROPERTY']) && $node['LINKED_PROPERTY'] == '' ? 'M: ' : 'P: ';
            $linked_target = $node['LINKED_OBJECT'] . '.';
            $linked_target .= isset($node['LINKED_PROPERTY']) && $node['LINKED_PROPERTY'] != '' ? $node['LINKED_PROPERTY'] : $node['LINKED_METHOD'];
            $html .= '<span class="md-mqtt-tree__linked">' . $this->mqttTreeHtml($linked_type . $linked_target) . '</span>';
        }
        $html .= '</div>';
        return $html;
    }

    function optimize()
    {

        $unused = SQLSelect("SELECT mqtt_history.* FROM mqtt_history LEFT JOIN mqtt ON mqtt_history.MQTT_ID=mqtt.ID WHERE mqtt.ID IS NULL");
        $total = count($unused);
        for ($i = 0; $i < $total; $i++) {
            SQLExec("DELETE FROM mqtt_history WHERE ID=" . $unused[$i]['ID']);
        }
        $keep_total = 20;
        $topics = SQLSelect("SELECT ID FROM mqtt");
        $total = count($topics);
        for ($i = 0; $i < $total; $i++) {
            $tmp = SQLSelect("SELECT ID FROM mqtt_history WHERE MQTT_ID=" . $topics[$i]['ID'] . " ORDER BY ID DESC LIMIT " . ($keep_total + 1));
            if (count($tmp) > $keep_total) {
                SQLExec("DELETE FROM mqtt_history WHERE MQTT_ID=" . $topics[$i]['ID'] . " AND ID<" . $tmp[$keep_total - 1]['ID']);
            }
        }
    }

    function clear_trash()
    {
        $res = SQLSelect("SELECT ID FROM mqtt WHERE LINKED_OBJECT='' AND LINKED_PROPERTY=''");
        $total = count($res);
        for ($i = 0; $i < $total; $i++) {
            $this->delete_mqtt($res[$i]['ID']);
        }
        $res = SQLSelect("SELECT ID, LINKED_OBJECT FROM mqtt WHERE LINKED_OBJECT!=''");
        $total = count($res);
        for ($i = 0; $i < $total; $i++) {
            $obj = getObject($res[$i]['LINKED_OBJECT']);
            if (!is_object($obj)) {
                $this->delete_mqtt($res[$i]['ID']);
            }
        }
        $this->invalidateLookupCache();
    }

    function api($params)
    {
        if (isset($_REQUEST['topic']) && $_REQUEST['topic']) {
            if (MqttRuntime::enabled() && MqttRuntime::handlersAlive()) {
                $this->getConfig();
                try {
                    $this->processRuntimeMessage($_REQUEST['topic'], $_REQUEST['msg']);
                } catch (Throwable $error) {
                    DebMes('MQTT API fast path failed, using sync: ' . $error->getMessage(), 'mqtt_runtime', 'warning');
                    $this->processMessage($_REQUEST['topic'], $_REQUEST['msg']);
                }
            } else {
                $this->processMessage($_REQUEST['topic'], $_REQUEST['msg']);
            }
        }
        if (isset($params['publish']) && $params['publish']) {
            $this->mqttPublish($params['publish'], $params['msg']);
        }
    }




    /**
     * mqtt search
     *
     * @access public
     */
    function search_mqtt(&$out)
    {
        require(DIR_MODULES . $this->name . '/mqtt_search.inc.php');
    }

    /**
     * mqtt edit/add
     *
     * @access public
     */
    function edit_mqtt(&$out, $id)
    {
        require(DIR_MODULES . $this->name . '/mqtt_edit.inc.php');
    }

    function propertySetHandle($object, $property, $value)
    {
        // Exact match: LIKE treats "_" and "%" in object/property titles as wildcards.
        // Индекс (LINKED_OBJECT, LINKED_PROPERTY) добавлен в MqttSchema::ensure().
        $mqtt_properties = SQLSelect("SELECT * FROM mqtt WHERE READONLY=0 AND LINKED_OBJECT='" . DBSafe($object) . "' AND LINKED_PROPERTY='" . DBSafe($property) . "'");
        $total = count($mqtt_properties);
        if ($total) {
            for ($i = 0; $i < $total; $i++) {
                if ($mqtt_properties[$i]['LOGGING']) {
                    DebMes("Linked property (" . $object . "." . $property . ") updated to \"" . $value . '"', 'mqtt_topic_' . $mqtt_properties[$i]['ID']);
                }
                // Передаём уже прочитанную запись: setProperty больше не делает
                // второй SELECT по ID на каждое изменение свойства.
                $this->setProperty($mqtt_properties[$i]['ID'], $value, 0, $mqtt_properties[$i]);
            }
        }
    }


    /**
     * mqtt delete record
     *
     * @access public
     */

    function delete_mqtt_path($path)
    {
        if (!$path) return;
        $records = SQLSelect("SELECT ID FROM mqtt WHERE PATH LIKE '" . DBSafe($path) . "/%' OR PATH='" . DBSafe($path) . "'");
        foreach ($records as $rec) {
            $this->delete_mqtt($rec['ID']);
        }
        $this->invalidateLookupCache();
    }

    function delete_mqtt($id)
    {
        $rec = SQLSelectOne("SELECT * FROM mqtt WHERE ID='" . (int)$id . "'");
        if (!isset($rec['ID'])) {
            return;
        }

        $linked_object = isset($rec['LINKED_OBJECT']) ? $rec['LINKED_OBJECT'] : '';
        $linked_property = isset($rec['LINKED_PROPERTY']) ? $rec['LINKED_PROPERTY'] : '';

        // some action for related tables
        SQLExec("DELETE FROM mqtt WHERE ID='" . (int)$rec['ID'] . "'");
        if (class_exists('MqttRuntime')) {
            try { MqttRuntime::forget((string)$rec['PATH']); } catch (Throwable $ignored) {}
        }
        if ($linked_object != '' && $linked_property != '') {
            removeLinkedPropertyIfNotUsed('mqtt', $linked_object, $linked_property, $this->name);
        }
        $this->invalidateLookupCache();
        if (!MqttRuntime::enabled()) {
            // В синхронном режиме цикл держит снимок привязок в памяти.
            setGlobal('cycle_mqttControl', 'restart');
        }
    }

    function processSubscription($event, &$details)
    {
        if ($event == 'HOURLY') {
            $this->optimize();
        }
    }

    /**
     * Install
     *
     * Module installation routine
     *
     * @access private
     */
    function install($data = '')
    {
        parent::install();

        subscribeToEvent($this->name, 'HOURLY');

        $write_paths = SQLSelect("SELECT PATH_WRITE FROM mqtt WHERE PATH_WRITE != ''");
        $total = count($write_paths);
        for ($i = 0; $i < $total; $i++) {
            SQLExec("DELETE FROM mqtt WHERE PATH='" . DBSafe($write_paths[$i]['PATH_WRITE']) . "'");
        }
        $this->invalidateLookupCache();
        MqttSchema::ensure();
    }

    /**
     * Uninstall
     *
     * Module uninstall routine
     *
     * @access public
     */
    function uninstall()
    {
        SQLExec('DROP TABLE IF EXISTS mqtt');
        parent::uninstall();
    }

    /**
     * dbInstall
     *
     * Database installation routine
     *
     * @access private
     */
    function dbInstall($data)
    {
        /*
        mqtt - MQTT
        */
        $data = <<<EOD
 mqtt: ID int(10) unsigned NOT NULL auto_increment
 mqtt: TITLE varchar(255) NOT NULL DEFAULT ''
 mqtt: LOCATION_ID int(10) NOT NULL DEFAULT '0'
 mqtt: UPDATED datetime
 mqtt: VALUE varchar(1024) NOT NULL DEFAULT ''
 mqtt: PATH varchar(6000) NOT NULL DEFAULT ''
 mqtt: PATH_WRITE varchar(6000) NOT NULL DEFAULT ''
 mqtt: REPLACE_LIST varchar(255) NOT NULL DEFAULT ''
 mqtt: LINKED_OBJECT varchar(255) NOT NULL DEFAULT ''
 mqtt: LINKED_PROPERTY varchar(255) NOT NULL DEFAULT ''
 mqtt: LINKED_METHOD varchar(255) NOT NULL DEFAULT ''
 mqtt: QOS int(3) NOT NULL DEFAULT '0'
 mqtt: RETAIN int(3) NOT NULL DEFAULT '0'
 mqtt: DISP_FLAG int(3) NOT NULL DEFAULT '0'
 mqtt: READONLY int(3) NOT NULL DEFAULT '0'
 mqtt: WRITE_TYPE int(3) NOT NULL DEFAULT '0'
 mqtt: ONLY_NEW_VALUE int(3) NOT NULL DEFAULT '0'
 mqtt: LOGGING int(3) NOT NULL DEFAULT '0'
 mqtt: INDEX (PATH)
 mqtt: INDEX (PATH_WRITE)
 mqtt: INDEX (LINKED_OBJECT, LINKED_PROPERTY)
 mqtt: INDEX (LINKED_OBJECT, LINKED_METHOD)
 mqtt: INDEX (UPDATED)
 
 mqtt_history: ID int(10) unsigned NOT NULL auto_increment
 mqtt_history: MQTT_ID int(10) NOT NULL DEFAULT '0'
 mqtt_history: DESTINATION int(3) NOT NULL DEFAULT '0'
 mqtt_history: TOPIC varchar(255) NOT NULL DEFAULT ''
 mqtt_history: VALUE varchar(255) NOT NULL DEFAULT ''
 mqtt_history: LINKED_DATA varchar(255) NOT NULL DEFAULT ''
 mqtt_history: DATA_PAYLOAD text
 mqtt_history: UPDATED datetime 
 mqtt_history: INDEX (MQTT_ID, ID)
 
EOD;
        parent::dbInstall($data);
    }
// --------------------------------------------------------------------
}

/*
*
* TW9kdWxlIGNyZWF0ZWQgSnVsIDE5LCAyMDEzIHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
?>
