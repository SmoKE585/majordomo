<?php

use MajorDoMo\Core\Core;
use MajorDoMo\Modules\Zigbee\Repository;
use MajorDoMo\Modules\Zigbee\Schema;
use MajorDoMo\Modules\Zigbee\Service;
use MajorDoMo\Modules\Zigbee\ValueTransformer;
use MajorDoMo\Modules\Zigbee\HealthSettings;
use MajorDoMo\Modules\Zigbee\HealthMonitor;

require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/ValueTransformer.php';
require_once __DIR__ . '/Battery.php';
require_once __DIR__ . '/HealthSettings.php';
require_once __DIR__ . '/HealthMonitor.php';
require_once __DIR__ . '/Repository.php';
require_once __DIR__ . '/Service.php';

/** Standard distribution module. The MQTT module owns its network transport. */
class zigbee extends module
{
    private ?Service $zigbeeService = null;

    public function __construct()
    {
        $this->name = 'zigbee';
        $this->title = 'Zigbee устройства';
        $this->module_category = '<#LANG_SECTION_DEVICES#>';
        $this->checkInstalled();
    }

    public function service(): Service
    {
        if (!$this->zigbeeService) {
            Schema::upgrade(Core::database());
            $subscription = Core::database()->one("SELECT VALUE FROM settings WHERE NAME='HOOK_EVENT_MINUTELY'");
            $registry = json_decode($subscription['VALUE'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
            if (!array_key_exists($this->name, $registry)) subscribeToEvent($this->name, 'MINUTELY');
            $this->zigbeeService = new Service(new Repository(Core::database()));
        }
        return $this->zigbeeService;
    }

    public function dbInstall($data)
    {
        Schema::install(Core::database());
    }

    public function install($parent_name = '')
    {
        parent::install($parent_name);
        subscribeToEvent($this->name, 'MQTT_MESSAGE');
        subscribeToEvent($this->name, 'MINUTELY');
        setGlobal('cycle_mqttControl', 'restart');
    }

    public function getMqttSubscriptions(): array { return $this->service()->subscriptions(); }

    public function processSubscription($event, &$details)
    {
        if ($event === 'MQTT_MESSAGE') $this->service()->receive($details['topic'], $details['payload']);
        if ($event === 'MINUTELY') (new HealthMonitor($this->service()->repository))->tick();
    }

    public function propertySetHandle($object, $property, $value)
    {
        $this->service()->propertySetHandle($object, $property, $value);
    }

    public function getParams()
    {
        global $id, $view_mode;
        if (isset($id) && is_scalar($id)) $this->id = (int)$id;
        if (isset($view_mode) && is_scalar($view_mode)) $this->view_mode = (string)$view_mode;
    }

    public function saveParams($data = 0)
    {
        return parent::saveParams(array('id' => (int)$this->id, 'view_mode' => (string)$this->view_mode));
    }

    public function run()
    {
        $this->getParams();
        require_once ROOT . 'lib/ui/MDJUI.php';
        $repo = $this->service()->repository;
        $error = ''; $success = '';
        global $session;
        if (!isset($session->data['zigbee_csrf'])) $session->data['zigbee_csrf'] = bin2hex(random_bytes(32));
        $token = $session->data['zigbee_csrf'];
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                if (!hash_equals($token, self::posted('_token'))) throw new InvalidArgumentException('Форма устарела. Обновите страницу.');
                $success = $this->handlePost();
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $view = (string)$this->view_mode;
        $id = (int)$this->id;
        $bridgeId = max(0, (int)self::request('bridge_id'));
        $search = mb_substr(self::request('q'), 0, 100);
        $page = max(1, (int)self::request('page'));
        $bridges = $repo->bridges();
        $locations = $repo->db->select('SELECT ID,TITLE FROM locations ORDER BY TITLE');
        $device = $view === 'device' ? $repo->row('zigbee_devices', $id) : null;
        $property = $view === 'property' ? $repo->row('zigbee_properties', $id) : null;
        if ($property) $device = $repo->row('zigbee_devices', (int)$property['DEVICE_ID']);
        if (($view === 'device' && !$device) || ($view === 'property' && !$property)) {
            http_response_code(404); $error = 'Устройство или свойство не найдено.';
        }
        $binding = $property ? ($repo->binding($id) ?? array()) : array();
        $healthDeviceId = $view === 'monitoring' ? max(0, (int)self::request('device_id')) : 0;
        $healthDevice = $healthDeviceId ? $repo->row('zigbee_devices', $healthDeviceId) : null;
        if ($healthDeviceId && !$healthDevice) { http_response_code(404); $error = 'Устройство не найдено.'; }
        $healthSettings = $view === 'monitoring' ? $repo->healthSettings($healthDevice) : array();
        $healthInherit = $healthDevice && empty($healthDevice['HEALTH_CONFIG']);
        if ($view === 'monitoring' && ($_POST['operation'] ?? '') === 'health_settings') {
            try { $healthSettings = self::healthFromPost(); $healthInherit = self::posted('health_inherit') === '1'; }
            catch (Throwable $e) { if ($error === '') $error = $e->getMessage(); }
        }
        if (isset($_POST['operation']) && is_string($_POST['operation']) && in_array($_POST['operation'], array('binding', 'preview_incoming', 'preview_outgoing'), true)) {
            $draftValue = static fn($name) => isset($_POST[$name]) && is_scalar($_POST[$name]) ? (string)$_POST[$name] : '';
            foreach (array('LINKED_OBJECT', 'LINKED_PROPERTY', 'LINKED_METHOD', 'EVENT_MODE') as $field) $binding[$field] = $draftValue(strtolower($field));
            $binding['ENABLED'] = $draftValue('enabled') === '1'; $binding['WRITE_ENABLED'] = $draftValue('write_enabled') === '1';
            foreach (array('incoming', 'outgoing') as $direction) {
                $draft = array('mode' => $draftValue($direction . '_mode'), 'factor' => $draftValue($direction . '_factor'),
                    'offset' => $draftValue($direction . '_offset'), 'precision' => $draftValue($direction . '_precision'),
                    'case_sensitive' => $draftValue($direction . '_case') === '1', 'pairs' => array());
                $toValues = is_array($_POST[$direction . '_to'] ?? null) ? $_POST[$direction . '_to'] : array();
                foreach (array_slice((array)($_POST[$direction . '_from'] ?? array()), 0, 64, true) as $key => $value) {
                    $to = $toValues[$key] ?? '';
                    if (is_string($value) && is_string($to) && ($value !== '' || $to !== '')) $draft['pairs'][] = array('from' => self::parseValue($value), 'to' => self::parseValue($to));
                }
                $binding[strtoupper($direction)] = ValueTransformer::json($draft);
            }
        }
        $properties = $device ? $repo->properties((int)$device['ID']) : array();
        $where = $this->devicesWhere($view === 'discovered', $bridgeId, $search);
        $count = (int)($repo->db->one('SELECT COUNT(*) AS CNT FROM zigbee_devices d WHERE ' . $where)['CNT'] ?? 0);
        $pages = max(1, (int)ceil($count / 40)); $page = min($page, $pages);
        $devices = $repo->db->select('SELECT d.*,b.TITLE AS BRIDGE_TITLE,b.ENABLED AS BRIDGE_ENABLED,b.STATE AS BRIDGE_STATE,l.TITLE AS LOCATION_TITLE FROM zigbee_devices d JOIN zigbee_bridges b ON b.ID=d.BRIDGE_ID LEFT JOIN locations l ON l.ID=d.LOCATION_ID WHERE ' . $where . ' ORDER BY d.ID DESC LIMIT 40 OFFSET ' . (($page - 1) * 40));
        $events = $repo->db->select('SELECT * FROM zigbee_events' . ($device ? ' WHERE DEVICE_ID=' . (int)$device['ID'] : ($bridgeId ? ' WHERE BRIDGE_ID=' . $bridgeId : '')) . ' ORDER BY ID DESC LIMIT 100');
        $stats = array('devices' => (int)($repo->db->one("SELECT COUNT(*) AS CNT FROM zigbee_devices WHERE STATUS='added'")['CNT'] ?? 0),
            'discovered' => (int)($repo->db->one("SELECT COUNT(*) AS CNT FROM zigbee_devices WHERE STATUS='discovered'")['CNT'] ?? 0),
            'bindings' => (int)($repo->db->one("SELECT COUNT(*) AS CNT FROM zigbee_bindings WHERE ENABLED=1 AND LINKED_OBJECT<>''")['CNT'] ?? 0),
            'errors' => (int)($repo->db->one("SELECT COUNT(*) AS CNT FROM zigbee_bindings WHERE LAST_ERROR IS NOT NULL")['CNT'] ?? 0));
        $linkedControl = '';
        if ($property) {
            require_once DIR_MODULES . 'linkedobject/linkedobject.class.php';
            $this->data = $binding;
            $linked = new linkedobject(); $linked->owner = $this;
            $linked->object_field = 'linked_object'; $linked->property_field = 'linked_property'; $linked->method_field = 'linked_method';
            $linked->width = '100%'; $linked->run(); $linkedControl = $linked->result;
        }
        ob_start();
        require ROOT . 'templates/zigbee/zigbee.php';
        // PHP views need the same form/pd state pass as parser-owned templates.
        $this->result = $this->parseLinks(ob_get_clean());
    }

    private function devicesWhere(bool $discovered, int $bridgeId, string $search): string
    {
        $repo = $this->service()->repository;
        $where = "d.STATUS='" . ($discovered ? 'discovered' : 'added') . "'";
        if ($bridgeId) $where .= ' AND d.BRIDGE_ID=' . $bridgeId;
        if ($search !== '') {
            $like = $repo->quote('%' . strtr($search, array('=' => '==', '%' => '=%', '_' => '=_')) . '%');
            $where .= " AND (d.FRIENDLY_NAME LIKE $like ESCAPE '=' OR d.TITLE LIKE $like ESCAPE '=' OR d.IEEE_ADDRESS LIKE $like ESCAPE '=' OR d.MODEL LIKE $like ESCAPE '=')";
        }
        return $where;
    }

    private static function request(string $name): string
    {
        $value = $_GET[$name] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
    }
    private static function posted(string $name): string
    {
        $value = $_POST[$name] ?? '';
        if (!is_scalar($value)) throw new InvalidArgumentException('Некорректное поле формы: ' . $name);
        return trim((string)$value);
    }

    private function handlePost(): string
    {
        $repo = $this->service()->repository;
        $operation = self::posted('operation');
        if ($operation === 'bridge') {
            $repo->saveBridge(array('ID' => (int)self::posted('bridge_id'), 'TITLE' => self::posted('title'),
                'BASE_TOPIC' => self::posted('base_topic'), 'ENABLED' => self::posted('enabled') === '1' ? 1 : 0));
            setGlobal('cycle_mqttControl', 'restart');
            return 'Экземпляр сохранён. MQTT обновляет подписки.';
        }
        if ($operation === 'binding') {
            $this->saveBinding((int)self::posted('property_id'));
            return 'Привязка и преобразования сохранены.';
        }
        if (in_array($operation, array('preview_incoming', 'preview_outgoing'), true)) {
            $property = $repo->row('zigbee_properties', (int)self::posted('property_id'));
            if (!$property) throw new InvalidArgumentException('Свойство не найдено.');
            $direction = substr($operation, 8);
            $value = ValueTransformer::apply(self::parseValue(self::posted($operation)), self::ruleFromPost($direction),
                ValueTransformer::decode($property['EXPOSE']) ?? array(), $direction === 'outgoing');
            return 'Результат проверки: ' . ValueTransformer::json($value) . '. Привязка не сохранялась, команда не отправлялась.';
        }
        if ($operation === 'write') {
            $value = self::parseValue(self::posted('value'));
            $this->service()->write((int)self::posted('property_id'), $value);
            return 'Команда поставлена в очередь MQTT. Подтверждение устройства появится в значениях.';
        }
        if ($operation === 'command') {
            $data = json_decode(self::posted('command_data'), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || (!str_starts_with(self::posted('command_data'), '{'))) throw new InvalidArgumentException('Параметры команды должны быть JSON-объектом.');
            $this->service()->command((int)self::posted('bridge_id'), self::posted('command'), $data);
            return 'Запрос отправлен. Ответ появится в журнале экземпляра.';
        }
        if (in_array($operation, array('adopt', 'adopt_all'), true)) {
            if ($operation === 'adopt_all') {
                $where = $this->devicesWhere(true, max(0, (int)self::posted('filter_bridge_id')), mb_substr(self::posted('filter_q'), 0, 100));
                $ids = array_column($repo->db->select('SELECT d.ID FROM zigbee_devices d WHERE ' . $where), 'ID');
            } elseif (self::posted('device_id') !== '') {
                $ids = array((int)self::posted('device_id'));
            } else {
                $ids = $_POST['device_ids'] ?? array();
                if (!is_array($ids) || array_filter($ids, static fn($id) => !is_string($id) || !ctype_digit($id))) throw new InvalidArgumentException('Некорректный список устройств.');
            }
            $total = $this->service()->configureDevices($ids, (int)self::posted('location_id'), true);
            return 'Добавлено устройств: ' . $total . '. Привязки можно настроить в разделе «Устройства».';
        }
        if ($operation === 'device_settings') {
            $device = $repo->row('zigbee_devices', (int)self::posted('device_id'));
            if (!$device) throw new InvalidArgumentException('Устройство не найдено.');
            $this->service()->configureDevices(array((int)$device['ID']), (int)self::posted('location_id'), false, self::posted('title'));
            return 'Название и комната сохранены.';
        }
        if ($operation === 'health_settings') {
            $deviceId = (int)self::posted('health_device_id');
            $inherit = $deviceId > 0 && self::posted('health_inherit') === '1';
            $repo->saveHealthSettings($inherit ? array() : self::healthFromPost(), $deviceId, $inherit);
            return 'Мониторинг сохранён. Проверка выполняется раз в минуту; сохранение не запускает say или PHP-код.';
        }
        throw new InvalidArgumentException('Неизвестное действие.');
    }

    private static function healthFromPost(): array
    {
        $settings = HealthSettings::defaults();
        $settings['enabled'] = self::posted('health_enabled') === '1';
        foreach (array('offline_delay', 'timeout_mains', 'timeout_battery', 'low', 'critical', 'recovery', 'battery_source', 'voltage_path', 'voltage_unit', 'voltage_profile') as $key) $settings[$key] = self::posted('health_' . $key);
        $points = self::posted('health_voltage_points');
        $settings['voltage_points'] = $points === '' ? array() : json_decode($points, true, 32, JSON_THROW_ON_ERROR);
        foreach (HealthSettings::EVENTS as $key => $label) {
            foreach (array('enabled', 'say') as $field) $settings['events'][$key][$field] = self::posted('health_' . $key . '_' . $field) === '1';
            foreach (array('level', 'repeat_hours', 'message', 'code') as $field) $settings['events'][$key][$field] = self::posted('health_' . $key . '_' . $field);
        }
        return $settings;
    }

    public static function parseValue(string $value): mixed
    {
        try { return json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { return $value; }
    }

    private static function ruleFromPost(string $direction): array
    {
        $rule = array('mode' => self::posted($direction . '_mode'), 'case_sensitive' => self::posted($direction . '_case') === '1',
            'factor' => self::posted($direction . '_factor'), 'offset' => self::posted($direction . '_offset'),
            'precision' => self::posted($direction . '_precision') === '' ? null : self::posted($direction . '_precision'), 'pairs' => array());
        $from = $_POST[$direction . '_from'] ?? array(); $to = $_POST[$direction . '_to'] ?? array();
        if (!is_array($from) || !is_array($to) || count($from) > 64) throw new InvalidArgumentException('Некорректная таблица замен.');
        foreach ($from as $key => $value) {
            if (!is_string($value) || !isset($to[$key]) || !is_string($to[$key])) throw new InvalidArgumentException('Некорректная пара замен.');
            if ($value === '' && $to[$key] === '') continue;
            $rule['pairs'][] = array('from' => self::parseValue($value), 'to' => self::parseValue($to[$key]));
        }
        return ValueTransformer::validate($rule);
    }

    private function saveBinding(int $id): void
    {
        $repo = $this->service()->repository;
        $property = $repo->row('zigbee_properties', $id);
        if (!$property) throw new InvalidArgumentException('Свойство Zigbee не найдено.');
        $previous = $repo->binding($id);
        $objectName = self::posted('linked_object'); $propertyName = self::posted('linked_property'); $methodName = self::posted('linked_method');
        foreach (array($objectName, $propertyName, $methodName) as $name) if (mb_strlen($name) > 190) throw new InvalidArgumentException('Имя привязки слишком длинное.');
        $scripts = $objectName === 'AllScripts';
        $object = $objectName !== '' && !$scripts ? getObject($objectName) : null;
        if ($scripts && ($propertyName !== '' || ($methodName !== '' && !$repo->db->one('SELECT ID FROM scripts WHERE TITLE=' . $repo->quote($methodName))))) throw new InvalidArgumentException('Выберите существующий сценарий AllScripts без свойства объекта.');
        if ($objectName !== '' && !$scripts && !$object) throw new InvalidArgumentException('Объект не найден.');
        if (($propertyName !== '' || $methodName !== '') && !$object && !$scripts) throw new InvalidArgumentException('Выберите объект для привязки.');
        if ($propertyName !== '' && !$object->getPropertyByName($propertyName, $object->class_id, $object->id)) throw new InvalidArgumentException('Свойство объекта не найдено.');
        if ($methodName !== '' && !$scripts && !$object->getMethodByName($methodName, $object->class_id, $object->id)) throw new InvalidArgumentException('Метод объекта не найден.');
        $eventMode = self::posted('event_mode');
        if (!in_array($eventMode, array('always', 'change'), true)) throw new InvalidArgumentException('Неизвестный режим событий.');
        $write = self::posted('write_enabled') === '1';
        $expose = ValueTransformer::decode($property['EXPOSE']) ?? array();
        if ($write && (!(($expose['access'] ?? 0) & 2) || $propertyName === '')) throw new InvalidArgumentException('Обратная запись требует свойства объекта и разрешения записи в exposes.');
        $incoming = self::ruleFromPost('incoming'); $outgoing = self::ruleFromPost('outgoing');
        $repo->transaction(function () use ($repo, $previous, $id, $objectName, $propertyName, $methodName, $eventMode, $write, $incoming, $outgoing): void {
            $repo->save('zigbee_bindings', array('ID' => $previous['ID'] ?? 0, 'PROPERTY_ID' => $id, 'LINKED_OBJECT' => $objectName,
                'LINKED_PROPERTY' => $propertyName, 'LINKED_METHOD' => $methodName, 'EVENT_MODE' => $eventMode,
                'WRITE_ENABLED' => (int)$write, 'ENABLED' => self::posted('enabled') === '1' ? 1 : 0,
                'INCOMING' => ValueTransformer::json($incoming), 'OUTGOING' => ValueTransformer::json($outgoing), 'LAST_DELIVERED' => null, 'LAST_ERROR' => null));
            if ($objectName !== '' && $propertyName !== '' && !addLinkedProperty($objectName, $propertyName, 'zigbee')) throw new RuntimeException('Не удалось зарегистрировать привязку объекта.');
            if ($previous && $previous['LINKED_OBJECT'] !== '' && $previous['LINKED_PROPERTY'] !== '') {
                removeLinkedPropertyIfNotUsed('zigbee_bindings', $previous['LINKED_OBJECT'], $previous['LINKED_PROPERTY'], 'zigbee');
            }
        });
    }
}
