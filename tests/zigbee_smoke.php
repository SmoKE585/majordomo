<?php

/** SQLite-only module contract tests; no application boot, HTTP, Redis or broker. */
// 2026-10-23 — заглушка очереди использует действующий контракт MQTT-доставки.
namespace MajorDoMo\Core {
    final class Core
    {
        public static $database;
        public static $queue;
        public static function database() { return self::$database; }
        public static function queue() { return self::$queue; }
    }
}
namespace {
    putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('USE_REDIS=false');
    define('ROOT', dirname(__DIR__) . '/'); define('DIR_MODULES', ROOT . 'modules/'); define('ROOTHTML', '/');
    require_once ROOT . 'vendor/autoload.php';
    require ROOT . 'lib/core/Database.php';
    #[AllowDynamicProperties]
    class module { public function checkInstalled() {} }
    require DIR_MODULES . 'zigbee/zigbee.class.php';
    require DIR_MODULES . 'mqtt/MqttIntegrations.php';

    use MajorDoMo\Core\Core;
    use MajorDoMo\Core\Database;
    use MajorDoMo\Modules\Zigbee\Repository;
    use MajorDoMo\Modules\Zigbee\Schema;
    use MajorDoMo\Modules\Zigbee\Service;
    use MajorDoMo\Modules\Zigbee\ValueTransformer as T;
    use MajorDoMo\Modules\Zigbee\Battery;
    use MajorDoMo\Modules\Zigbee\HealthSettings;
    use MajorDoMo\Modules\Zigbee\HealthMonitor;

    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Isolation failed');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $adapter = new class($pdo) {
        public int $ddl = 0;
        public function __construct(public PDO $pdo) {}
        public function Select($sql) {
            if ($sql === 'SHOW INDEX FROM zigbee_bindings') {
                $rows = array();
                foreach ($this->pdo->query('PRAGMA index_list(zigbee_bindings)') as $index) {
                    foreach ($this->pdo->query("PRAGMA index_info('" . $index['name'] . "')") as $column) {
                        $rows[] = array('Key_name' => $index['name'], 'Seq_in_index' => $column['seqno'] + 1, 'Column_name' => $column['name']);
                    }
                }
                return $rows;
            }
            if ($sql === 'SHOW COLUMNS FROM zigbee_devices') return array_map(static fn($row) => array('Field' => $row['name'], 'Default' => trim((string)$row['dflt_value'], "'")), $this->pdo->query('PRAGMA table_info(zigbee_devices)')->fetchAll(PDO::FETCH_ASSOC));
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }
        public function SelectOne($sql) {
            if (str_contains($sql, 'GET_LOCK(')) return array('ACQUIRED' => 1);
            if (str_contains($sql, 'RELEASE_LOCK(')) return array('RELEASED' => 1);
            return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        }
        public function DbSafe1($value) { return str_replace("'", "''", (string)$value); }
        public function DbSafe($value) { return str_replace(array('%', '_'), array('\\%', '\\_'), (string)$value); }
        public function Exec($sql, $ignore = false) {
            if (preg_match('/^(CREATE|ALTER|DROP) /', $sql)) $this->ddl++;
            if ($sql === 'ALTER TABLE zigbee_bindings ADD INDEX idx_zigbee_binding_target (LINKED_OBJECT,LINKED_PROPERTY,ENABLED,WRITE_ENABLED)') {
                $sql = 'CREATE INDEX idx_zigbee_binding_target ON zigbee_bindings (LINKED_OBJECT,LINKED_PROPERTY,ENABLED,WRITE_ENABLED)';
            }
            if (str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS zigbee_health_')) return true; // created with SQLite schema below
            $this->pdo->exec($sql === 'START TRANSACTION' ? 'BEGIN' : $sql); return true;
        }
        public function Insert($table, &$row) {
            if (in_array(null, $row, true)) throw new RuntimeException('Nullable inserts must omit fields for the legacy SQL adapter');
            $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
            $this->pdo->prepare($sql)->execute(array_values($row)); return (int)$this->pdo->lastInsertId();
        }
        public function Update($table, $row, $key = 'ID') {
            $id = $row[$key]; unset($row[$key]);
            return $this->pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(fn($k) => $k . '=?', array_keys($row))) . ' WHERE ' . $key . '=?')->execute(array_merge(array_values($row), array($id)));
        }
    };
    Core::$database = new Database($adapter);
    Core::$queue = new class {
        public array $items = array();
        public function enqueueRuntimePayload($operation, $payload): bool
        {
            $this->items[] = array('operation' => $operation, 'payload' => $payload);
            return true;
        }
    };
    foreach (Schema::statements(true) as $sql) $pdo->exec($sql);
    $pdo->exec('CREATE TABLE locations (ID INTEGER PRIMARY KEY, TITLE TEXT)');
    $pdo->exec("INSERT INTO locations VALUES (1, 'Кухня'), (2, 'Прихожая')");
    $pdo->exec('CREATE TABLE settings (NAME TEXT PRIMARY KEY, VALUE TEXT)');
    $pdo->exec("INSERT INTO settings VALUES ('HOOK_EVENT_MINUTELY', '{\"zigbee\":{}}')");
    $repo = new Repository(Core::database());
    $checks = 0;
    function check($condition, $message) { global $checks; if (!$condition) throw new RuntimeException('FAILED: ' . $message); $checks++; }
    function rejects(callable $call, string $message): void { $caught = false; try { $call(); } catch (Throwable $e) { $caught = true; } check($caught, $message); }
    function SQLSelectOne($sql) { return array('VALUE' => '{"zigbee":{}}'); }
    function isModuleInstalled($name) { return $name === 'zigbee'; }

    check(T::apply('KeepCASE', array()) === 'KeepCASE', 'Raw string case stays unchanged');
    check(T::apply(null, array()) === null && T::apply('', array()) === '', 'Null and empty string stay distinct');
    check(T::apply('ON', array('mode' => 'binary')) === 1, 'ON -> 1');
    check(T::apply('off', array('mode' => 'binary')) === 0, 'off -> 0');
    check(T::apply(false, array('mode' => 'binary')) === 0, 'false -> 0');
    check(T::apply('0', array('mode' => 'binary'), array(), true) === 'OFF', 'Outgoing 0 -> OFF');
    check(T::apply('on', array('mode' => 'invert')) === 0, 'Invert');
    rejects(fn() => T::apply('', array('mode' => 'binary')), 'Empty string is not false');
    $binary = array('value_on' => 'CLOSE', 'value_off' => 'OPEN');
    check(T::apply('CLOSE', array('mode' => 'exposes'), $binary) === 1, 'Expose on value');
    check(T::apply(0, array('mode' => 'exposes'), $binary, true) === 'OPEN', 'Expose outgoing off');
    check(T::apply(1, array('mode' => 'exposes'), array('value_on' => true, 'value_off' => false), true) === true, 'Outgoing boolean keeps type');
    rejects(fn() => T::apply('ON', array('mode' => 'exposes')), 'No guessed exposes');
    $map = array('mode' => 'map', 'pairs' => array(array('from' => 'ON', 'to' => 7), array('from' => false, 'to' => 'false-type')));
    check(T::apply('ON', $map) === 7 && T::apply('on', $map) === 'on', 'Map case sensitive');
    $map['case_sensitive'] = false;
    check(T::apply('on', $map) === 7 && T::apply(0, $map) === 0, 'Case-insensitive map does not confuse false and zero');
    check(T::apply(false, $map) === 'false-type', 'Typed replacement');
    check(T::apply('13.21', array('mode' => 'number', 'factor' => 2, 'offset' => -1, 'precision' => 1)) === 25.4, 'Scale offset precision');
    rejects(fn() => T::apply('bad', array('mode' => 'number')), 'Non-numeric input rejected');
    rejects(fn() => T::validate(array('mode' => 'number', 'factor' => INF)), 'Infinite scale rejected');
    rejects(fn() => T::validate(array('precision' => 9)), 'Precision range validated');
    check(T::decode(T::json(array('test' => 0, 'null' => null))) === array('test' => 0, 'null' => null), 'Typed JSON round-trip');

    $house = $repo->saveBridge(array('TITLE' => 'Дом', 'BASE_TOPIC' => 'Z2M/House', 'ENABLED' => 1));
    $garage = $repo->saveBridge(array('TITLE' => 'Гараж', 'BASE_TOPIC' => 'z2m-garage', 'ENABLED' => 1));
    rejects(fn() => $repo->saveBridge(array('TITLE' => 'Bad', 'BASE_TOPIC' => 'Z2M/House/sub', 'ENABLED' => 1)), 'Nested roots rejected');
    rejects(fn() => $repo->saveBridge(array('TITLE' => 'Bad', 'BASE_TOPIC' => 'Z2M/+', 'ENABLED' => 1)), 'Wildcard roots rejected');
    rejects(fn() => $repo->saveBridge(array('ID' => $house, 'TITLE' => 'Bad', 'BASE_TOPIC' => 'changed', 'ENABLED' => 1)), 'Root identity immutable');
    $calls = array(); $published = array(); $objectValue = null; $failSet = false;
    $service = new Service($repo,
        function ($topic, $payload) use (&$published) { $published[] = array($topic, $payload); },
        function ($target, $value, $source) use (&$calls, &$objectValue, &$failSet) { if ($failSet) throw new RuntimeException('simulated validation failure'); $objectValue = $value; $calls[] = array('set', $target, $value, $source); },
        function ($target, $params) use (&$calls, &$objectValue) { $calls[] = array('method', $target, $params, $objectValue); });
    check($service->subscriptions() === array('Z2M/House/#', 'z2m-garage/#'), 'Multiple bridge roots');
    check($service->matches('Z2M/House/Kitchen/Motion') && !$service->matches('z2m/house/Kitchen/Motion'), 'MQTT case sensitivity');
    $service->receive('Z2M/House/Kitchen/Motion', '{"occupancy":false}');
    $early = $repo->device($house, 'Kitchen/Motion');
    $metadata = array(array('ieee_address' => '0x001', 'friendly_name' => 'Kitchen/Motion', 'definition' => array('model' => 'TEST', 'vendor' => 'Test', 'exposes' => array(
        array('type' => 'binary', 'property' => 'occupancy', 'access' => 1, 'value_on' => true, 'value_off' => false),
        array('type' => 'light', 'features' => array(array('property' => 'state', 'type' => 'binary', 'access' => 7, 'value_on' => 'ON', 'value_off' => 'OFF'),
            array('property' => 'color', 'type' => 'composite', 'access' => 2, 'features' => array(array('property' => 'x', 'type' => 'numeric', 'access' => 2, 'value_min' => 0, 'value_max' => 1)))))
    ))));
    $service->receive('Z2M/House/bridge/devices', T::json($metadata));
    $device = $repo->device($house, 'Kitchen/Motion', '0x001');
    $service->configureDevices(array((int)$device['ID']), 0, true);
    $device = $repo->row('zigbee_devices', (int)$device['ID']);
    check($device['ID'] === $early['ID'] && $device['IEEE_ADDRESS'] === '0x001', 'Early reports reconcile with stable IEEE identity');
    $other = $repo->device($garage, 'Kitchen/Motion', '0x001');
    check($other['ID'] !== $device['ID'], 'Same IEEE on different roots remains separate');
    $provisionalA = $repo->device($garage, 'EarlyA'); $provisionalB = $repo->device($garage, 'EarlyB');
    check($provisionalA['IEEE_ADDRESS'] === null && $provisionalB['IEEE_ADDRESS'] === null, 'Multiple provisional devices use SQL NULL, not an empty IEEE address');
    check(count($repo->properties((int)$device['ID'])) === 4, 'Nested exposes discovered');
    $occupancy = $repo->property((int)$device['ID'], 'occupancy');
    $bindingId = $repo->save('zigbee_bindings', array('PROPERTY_ID' => $occupancy['ID'], 'LINKED_OBJECT' => 'Sensor', 'LINKED_PROPERTY' => 'motion',
        'LINKED_METHOD' => 'onMotion', 'INCOMING' => T::json(array('mode' => 'exposes')), 'OUTGOING' => '{}'));
    $calls = array();
    $service->receive('Z2M/House/Kitchen/Motion', '{"occupancy":true}');
    $service->receive('Z2M/House/Kitchen/Motion', '{"occupancy":true}');
    check(count($calls) === 4, 'Repeated 1 reaches both property and method');
    check($calls[0][0] === 'set' && $calls[1][0] === 'method' && $calls[1][3] === 1, 'Explicit method observes saved new value');
    check($calls[1][2]['RAW_VALUE'] === true && $calls[1][2]['NEW_VALUE'] === 1, 'Method receives raw and transformed values');
    $repo->save('zigbee_bindings', array('ID' => $bindingId, 'EVENT_MODE' => 'change'));
    $service->receive('Z2M/House/Kitchen/Motion', '{"occupancy":true}');
    check(count($calls) === 4, 'Optional change-only policy');
    $failSet = true;
    rejects(fn() => $service->receive('Z2M/House/Kitchen/Motion', '{"occupancy":false}'), 'Write errors propagate for durable retry');
    $failed = $repo->binding((int)$occupancy['ID']);
    check($failed['LAST_DELIVERED'] === '1' && str_contains($failed['LAST_ERROR'], 'simulated'), 'Failed delivery does not advance fingerprint');
    check(count($calls) === 4, 'Explicit method does not run before failed property write');
    $failSet = false;
    $service->receive('Z2M/House/Kitchen/Motion', '{"occupancy":false}');
    check($repo->binding((int)$occupancy['ID'])['LAST_ERROR'] === null && count($calls) === 6, 'Retry delivers same failed value and clears error');
    $raw = $repo->row('zigbee_properties', (int)$occupancy['ID']);
    check($raw['RAW_VALUE'] === 'false' && $raw['VALUE'] === '0', 'Raw boolean and converted zero are separate');
    $state = $repo->property((int)$device['ID'], 'state');
    $repo->save('zigbee_bindings', array('PROPERTY_ID' => $state['ID'], 'LINKED_OBJECT' => 'Lamp', 'LINKED_PROPERTY' => 'status', 'WRITE_ENABLED' => 1,
        'INCOMING' => '{}', 'OUTGOING' => '{"mode":"exposes"}'));
    $service->propertySetHandle('Lamp', 'status', 0);
    check($published[0] === array('Z2M/House/Kitchen/Motion/set', '{"state":"OFF"}'), 'Outgoing uses shared publisher and reverse conversion');
    check(count($published) === 1, 'Incoming processing does not publish echoes');
    rejects(fn() => $service->write((int)$occupancy['ID'], 1), 'Read-only exposes cannot be written');
    $color = $repo->property((int)$device['ID'], 'color.x');
    $service->write((int)$color['ID'], 0.25);
    check($published[1][1] === '{"color":{"x":0.25}}', 'Composite command shape preserved');
    rejects(fn() => $service->write((int)$color['ID'], 2), 'Numeric exposes bounds');
    $service->receive('Z2M/House/bridge/state', '{"state":"offline"}');
    check($repo->row('zigbee_bridges', $house)['STATE'] === 'offline', 'Bridge availability');
    $service->receive('Z2M/House/Kitchen/Motion/availability', 'offline');
    check($repo->row('zigbee_devices', (int)$device['ID'])['AVAILABLE'] === 'offline', 'Plain availability compatibility');
    $service->receive('Z2M/House/Kitchen/Motion/set', '{"state":"ON"}');
    check(count($calls) === 6, 'Outgoing echo is ignored');
    $metadata[0]['friendly_name'] = 'Kitchen/Renamed';
    $service->receive('Z2M/House/bridge/devices', T::json($metadata));
    check($repo->device($house, 'Kitchen/Renamed', '0x001')['ID'] === $device['ID'], 'Rename preserves device ID and bindings');
    $service->receive('Z2M/House/Kitchen/EarlyRename', '{"occupancy":true}');
    $metadata[0]['friendly_name'] = 'Kitchen/EarlyRename';
    $service->receive('Z2M/House/bridge/devices', T::json($metadata));
    check($repo->device($house, 'Kitchen/EarlyRename', '0x001')['ID'] === $device['ID'], 'Early report before renamed discovery merges into stable identity');
    check(count($calls) === 8 && end($calls)[3] === 1, 'Merged early state reaches existing binding after metadata commits');
    check((int)$pdo->query("SELECT COUNT(*) FROM zigbee_devices WHERE BRIDGE_ID=$house")->fetchColumn() === 1, 'Provisional renamed device is removed without duplicates');
    $service->command($house, 'permit_join', array('time' => 120));
    check($published[2][0] === 'Z2M/House/bridge/request/permit_join' && isset(json_decode($published[2][1], true)['transaction']), 'Bridge request has transaction ID');
    rejects(fn() => $service->command($house, '../../bad', array()), 'Command allowlist');
    rejects(fn() => $service->receive('Z2M/House/Kitchen/Renamed', 'broken'), 'Invalid JSON visible as an error');
    check((int)$pdo->query("SELECT COUNT(*) FROM zigbee_events WHERE LEVEL='error'")->fetchColumn() >= 2, 'Errors preserved in journal');
    $repo->save('zigbee_bridges', array('ID' => $house, 'ENABLED' => 0));
    rejects(fn() => $service->write((int)$state['ID'], 1), 'Disabled bridge blocks writes');
    $repo->save('zigbee_bridges', array('ID' => $house, 'ENABLED' => 1));

    check(MqttIntegrations::matches('root/#', 'root'), 'Hash includes parent');
    check(MqttIntegrations::matches('root/+', 'root/a') && !MqttIntegrations::matches('root/+', 'root/a/b'), 'Plus matches one level');
    check(!MqttIntegrations::matches('#', '$SYS/status'), 'MQTT system topics need explicit subscription');
    $mqtt_integrations = new MqttIntegrations();
    $mqtt = new class { public array $config = array(); public function hasLinkedPathWithPrefixInList($topic, $paths) { return false; } };
    $stripmode = 1; $mqtt_delay = 5; $mqtt_repeating_cache = array(); $mqtt_linked_paths = array();
    $cycle = file_get_contents(ROOT . 'scripts/cycle_mqtt.php');
    $start = strpos($cycle, 'function procmsg('); $end = strpos($cycle, 'function mqttShouldStop()', $start);
    eval(substr($cycle, $start, $end - $start));
    procmsg('Z2M/House/Kitchen/Renamed', '{"occupancy":true}');
    procmsg('Z2M/House/Kitchen/Renamed', '{"occupancy":true}');
    check(count(Core::$queue->items) === 2, 'Original repeated packets enter module queue before strip/dedup');
    check(Core::$queue->items[0]['operation'] === 'moduleevent'
        && Core::$queue->items[0]['payload']['params']['event'] === 'MQTT_MESSAGE'
        && Core::$queue->items[0]['payload']['params']['details']['payload'] === '{"occupancy":true}', 'Original event parameters reach the runtime queue');

    require_once ROOT . 'vendor/autoload.php';
    $packetCalls = 0;
    $callback = function ($topic, $payload) use (&$packetCalls) { $packetCalls++; };
    $client = new Bluerhinos\phpMQTT('127.0.0.1', 1, 'isolated-no-connect');
    $client->topics = array('Z2M/#' => array('function' => $callback), 'Z2M/House/#' => array('function' => $callback));
    $topic = 'Z2M/House/Device'; $packet = pack('n', strlen($topic)) . $topic . '{"occupancy":true}';
    $client->message($packet); $client->message($packet);
    check($packetCalls === 2, 'Overlapping subscriptions dispatch once per physical packet but retain subsequent repeats');
    check(MqttIntegrations::matches('root[v2].house/#', 'root[v2].house/device'), 'MQTT literal punctuation is not a regular expression');
    check(!MqttIntegrations::matches('root[v2].house/#', 'rootv2xhouse/device'), 'Regex-like roots do not match other topics');

    require ROOT . 'lib/admin_router.php';
    foreach (array('/panel/zigbee/discovered.html', '/panel/zigbee/bridges.html', '/panel/zigbee/journal.html', '/panel/zigbee/monitoring.html', '/panel/zigbee/device/3.html', '/panel/zigbee/property/7.html') as $path) {
        $resolved = mdj_admin_resolve_path($path);
        check($resolved !== false && mdj_admin_pretty_url('/admin.php?' . http_build_query($resolved['query'])) === $path, 'Zigbee route round-trip: ' . $path);
    }
    $button = $repo->device($house, 'Button');
    $service->configureDevices(array((int)$button['ID']), 0, true);
    $single = $repo->property((int)$button['ID'], 'action:single');
    $repo->save('zigbee_bindings', array('PROPERTY_ID' => $single['ID'], 'LINKED_OBJECT' => 'ButtonObject', 'LINKED_PROPERTY' => 'action', 'EVENT_MODE' => 'always'));
    $calls = array();
    $service->receive('Z2M/House/Button', '{"action":"single"}');
    $service->receive('Z2M/House/Button', '{"action":"single"}');
    check(count($calls) === 2 && $calls[0][1] === 'ButtonObject.action', 'Repeated single presses reach their event binding');
    check($calls[0][2] !== $calls[1][2] && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D', $calls[1][2]), 'Event timestamps distinguish rapid repeated presses');
    $service->receive('Z2M/House/Button', '{"action":"double"}');
    $service->receive('Z2M/House/Button', '{"action":""}');
    check(count($calls) === 2, 'Other actions and cleared action do not trigger single');
    $action = $repo->property((int)$button['ID'], 'action');
    check(T::decode($repo->row('zigbee_properties', (int)$action['ID'])['RAW_VALUE']) === '', 'Ordinary action value remains separate from press events');

    $roomCalls = array(); $roomLookups = array(); $failRoom = false;
    $roomService = new Service($repo, null,
        function ($target, $value, $source) use (&$roomCalls) { $roomCalls[] = array('set', $target, $value); },
        function ($target, $params) use (&$roomCalls, &$failRoom) { if ($failRoom && $target === 'Room1.deviceChanged') throw new RuntimeException('room handler failed'); $roomCalls[] = array('method', $target, $params); },
        function ($location, $create) use (&$roomLookups) { $roomLookups[] = array($location, $create); return 'Room' . $location; });
    $roomService->receive('Z2M/House/NewSensor', '{"occupancy":true}');
    $pending = $repo->device($house, 'NewSensor');
    $motion = $repo->property((int)$pending['ID'], 'occupancy');
    $roomBinding = $repo->save('zigbee_bindings', array('PROPERTY_ID' => $motion['ID'], 'LINKED_OBJECT' => 'NewSensor', 'LINKED_PROPERTY' => 'motion', 'LINKED_METHOD' => 'changed', 'INCOMING' => '{"mode":"binary"}'));
    $roomService->configureDevices(array((int)$pending['ID']), 1);
    $roomService->receive('Z2M/House/NewSensor', '{"occupancy":true}');
    check(!$roomCalls && $pending['STATUS'] === 'discovered', 'Discovered device collects data without executing bindings or room logic');
    check($roomService->configureDevices(array((int)$pending['ID']), 1, true) === 1 && !$roomCalls, 'Adoption does not replay stale values');
    $roomService->receive('Z2M/House/NewSensor', '{"occupancy":true}');
    $roomService->receive('Z2M/House/NewSensor', '{"occupancy":true}');
    check(count($roomCalls) === 6 && $roomCalls[0][0] === 'set' && $roomCalls[1][1] === 'NewSensor.changed' && $roomCalls[2][1] === 'Room1.deviceChanged', 'Binding write, explicit method and room notification run in order for every repeat');
    check($roomCalls[5][2]['NEW_VALUE'] === 1 && $roomCalls[5][2]['OLD_VALUE'] === 1 && $roomCalls[5][2]['RAW_VALUE'] === true && $roomCalls[5][2]['PROPERTY'] === 'occupancy' && $roomCalls[5][2]['LOCATION_ID'] === 1, 'Room receives typed old/new/raw values and device context');
    $repo->save('zigbee_bindings', array('ID' => $roomBinding, 'EVENT_MODE' => 'change'));
    $roomService->receive('Z2M/House/NewSensor', '{"occupancy":true}');
    check(count($roomCalls) === 7 && end($roomCalls)[1] === 'Room1.deviceChanged', 'Room still receives repeats when the explicit binding is change-only');
    $roomService->receive('Z2M/House/NewSensor', '{"temperature":23}');
    check(count($roomCalls) === 8 && end($roomCalls)[2]['PROPERTY'] === 'temperature', 'Room notification does not require an object binding');
    check($roomService->configureDevices(array((int)$pending['ID']), 0, true) === 0 && (int)$repo->row('zigbee_devices', (int)$pending['ID'])['LOCATION_ID'] === 1, 'Repeated adoption preserves the existing room');
    rejects(fn() => $roomService->configureDevices(array((int)$pending['ID']), 999, true), 'Missing room is rejected');
    rejects(fn() => $roomService->configureDevices(array((int)$pending['ID'], 99999), 2), 'Partial bulk assignment is rolled back');
    check((int)$repo->row('zigbee_devices', (int)$pending['ID'])['LOCATION_ID'] === 1, 'Failed bulk assignment preserves rooms');
    $failRoom = true;
    rejects(fn() => $roomService->receive('Z2M/House/NewSensor', '{"occupancy":false}'), 'Room errors propagate for durable retry');
    check($repo->binding((int)$motion['ID'])['LAST_DELIVERED'] === '1', 'Room failure does not advance successful binding delivery');
    $failRoom = false;
    $roomService->receive('Z2M/House/NewSensor', '{"occupancy":false}');
    check($repo->binding((int)$motion['ID'])['LAST_DELIVERED'] === '0' && $repo->binding((int)$motion['ID'])['LAST_ERROR'] === null, 'Room retry completes and clears binding error');

    $health = HealthSettings::defaults();
    check(Service::deviceImageUrl('MW783Z') === 'https://www.zigbee2mqtt.io/images/devices/MW783Z.png', 'Device photo comes from the official model catalogue');
    check(Service::deviceImageUrl('A: B/C') === 'https://www.zigbee2mqtt.io/images/devices/A--B-C.png' && Service::deviceImageUrl('') === null, 'Model names follow frontend normalization and unknown models have no image request');
    check(str_starts_with(Service::deviceImageUrl('https://evil.test/a?x="'), 'https://www.zigbee2mqtt.io/images/devices/') && !str_contains(Service::deviceImageUrl('https://evil.test/a?x="'), '?'), 'A model cannot choose an image host or inject URL parameters');
    check(!$health['events']['offline']['say'] && $health['timeout_battery'] === 0, 'Monitoring starts without unsolicited voice or sleep timeout assumptions');
    rejects(fn() => HealthSettings::validate(array('critical' => 30)), 'Invalid battery threshold ordering rejected');
    rejects(fn() => HealthSettings::validate(array('events' => array('offline' => array('code' => 'if (')))), 'PHP syntax checked without executing code');
    rejects(fn() => HealthSettings::validate(array('events' => array('offline' => array('code' => '<?php say("bad");')))), 'PHP tags rejected');
    rejects(fn() => HealthSettings::validate(array('voltage_profile' => 'custom', 'voltage_points' => array(array('voltage' => 3, 'percent' => 100)))), 'Custom voltage curve needs two points');
    $health['voltage_profile'] = 'linear3v';
    check(Battery::estimate(2550, $health) === 50 && Battery::estimate(2100, $health) === 0 && Battery::estimate(3000, $health) === 100, 'Millivolt conversion and linear endpoints');
    check(Battery::estimate(220000, $health) === null && Battery::estimate(2.9, $health) === null && Battery::estimate(false, $health) === null, 'Mains voltage, wrong units and invalid readings cannot become battery percentage');
    $health['voltage_unit'] = 'V';
    check(Battery::estimate(2.55, $health) === 50, 'Explicit volt conversion');
    $health['voltage_profile'] = 'custom';
    $health['voltage_points'] = array(array('voltage' => 2.1, 'percent' => 0), array('voltage' => 2.7, 'percent' => 40), array('voltage' => 3.0, 'percent' => 100));
    check(Battery::estimate(2.85, $health) === 70, 'Custom curve interpolates adjacent points');
    rejects(fn() => Battery::validatePoints(array_reverse($health['voltage_points'])), 'Reversed curve rejected');

    $health = HealthSettings::defaults();
    foreach ($health['events'] as &$rule) { $rule['say'] = true; $rule['code'] = '// user handler'; } unset($rule);
    $repo->saveHealthSettings($health);
    $pdo->exec("UPDATE zigbee_devices SET HEALTH_CONFIG='{\"enabled\":false}'");
    $repo->save('zigbee_bridges', array('ID' => $house, 'STATE' => 'online'));
    $repo->save('zigbee_bridges', array('ID' => $garage, 'ENABLED' => 0));
    $sensor = $repo->device($house, 'HealthSensor', '0xhealth');
    $sensorId = (int)$sensor['ID'];
    $repo->save('zigbee_devices', array('ID' => $sensorId, 'STATUS' => 'added', 'LOCATION_ID' => 1, 'METADATA' => '{"power_source":"Battery"}'));
    $roomService->receive('Z2M/House/HealthSensor', '{"battery":80,"voltage":2900}');
    $spoken = array(); $handled = array(); $transport = 'online'; $failCode = false;
    $makeMonitor = function () use ($repo, &$spoken, &$handled, &$transport, &$failCode) {
        return new HealthMonitor($repo,
            function ($message, $level) use (&$spoken) { $spoken[] = array($message, $level); },
            function ($code, $params) use (&$handled, &$failCode) { if ($failCode) throw new RuntimeException('test handler failure'); $handled[] = $params; },
            function () use (&$transport) { return $transport; });
    };
    $now = time();
    $makeMonitor()->tick($now);
    check(!$spoken && !$handled, 'Healthy initial state is silent across device, bridge and broker');
    $roomService->receive('Z2M/House/HealthSensor', '{"battery":15}');
    $failCode = true;
    $makeMonitor()->tick($now + 60);
    check(count($spoken) === 1 && !$handled, 'say succeeds independently of failed custom code');
    $failCode = false;
    $makeMonitor()->tick($now + 120);
    check(count($spoken) === 1 && count($handled) === 1 && $handled[0]['EVENT'] === 'battery_low', 'Only failed channel retries across fresh monitor instances');
    check($handled[0]['ROOM'] === 'Кухня' && $handled[0]['DEVICE_ID'] === $sensorId && $handled[0]['VALUE'] === 15.0 && $handled[0]['REPEAT'] === false, 'Custom handler receives typed state and room context');
    $makeMonitor()->tick($now + 180);
    check(count($spoken) === 1 && count($handled) === 1, 'Repeated battery packets do not announce the same incident');
    $roomService->receive('Z2M/House/HealthSensor', '{"battery":22}');
    $makeMonitor()->tick($now + 240);
    check(count($handled) === 1, 'Small percentage rebound does not reset a low-battery incident');
    $roomService->receive('Z2M/House/HealthSensor', '{"battery":8}');
    $makeMonitor()->tick($now + 300);
    check(end($handled)['EVENT'] === 'battery_critical', 'Critical threshold has an independent event');
    $roomService->receive('Z2M/House/HealthSensor', '{"battery":90}');
    $makeMonitor()->tick($now + 360);
    check(end($handled)['EVENT'] === 'battery_ok', 'Battery replacement produces configurable recovery');
    $spokenBefore = count($spoken); $handledBefore = count($handled);
    $roomService->receive('Z2M/House/HealthSensor/availability', '{"state":"offline"}');
    $lastSeen = $repo->row('zigbee_devices', $sensorId)['LAST_SEEN'];
    $makeMonitor()->tick($now + 420); $makeMonitor()->tick($now + 480);
    check(count($handled) === $handledBefore, 'Short offline interval does not trigger a notification');
    $makeMonitor()->tick($now + 540);
    check(end($handled)['EVENT'] === 'offline' && count($spoken) === $spokenBefore + 1, 'Confirmed offline incident uses common pipeline');
    $makeMonitor()->tick($now + 600);
    check(count($spoken) === $spokenBefore + 1 && $repo->row('zigbee_devices', $sensorId)['LAST_SEEN'] === $lastSeen, 'Offline retained messages do not refresh contact time or duplicate announcements');
    $health['events']['offline']['repeat_hours'] = 1;
    $repo->saveHealthSettings($health);
    $makeMonitor()->tick($now + 4140);
    check(end($handled)['REPEAT'] === true && count($spoken) === $spokenBefore + 2, 'Configured reminder repeats an unresolved incident');
    $roomService->receive('Z2M/House/HealthSensor', '{"battery":90}');
    $makeMonitor()->tick($now + 4200);
    check(end($handled)['EVENT'] === 'online' && end($handled)['OLD_STATE'] === 'offline', 'Contact restoration has its own event');
    $handledBefore = count($handled);
    $repo->save('zigbee_bridges', array('ID' => $house, 'STATE' => 'offline'));
    $roomService->receive('Z2M/House/HealthSensor/availability', 'offline');
    $makeMonitor()->tick($now + 4260); $makeMonitor()->tick($now + 4380);
    check(count($handled) === $handledBefore + 1 && end($handled)['KIND'] === 'bridge', 'Bridge outage produces one parent incident and suppresses child outages');
    $transport = 'offline';
    $makeMonitor()->tick($now + 4440); $makeMonitor()->tick($now + 4560);
    check(end($handled)['KIND'] === 'mqtt' && end($handled)['EVENT'] === 'offline', 'MQTT outage is distinguished from bridge outage');
    $handledBefore = count($handled); $transport = null;
    $makeMonitor()->tick($now + 9000);
    check(count($handled) === $handledBefore, 'Intentionally stopped or uninitialized transport suppresses monitoring');
    $transport = 'online'; $repo->save('zigbee_bridges', array('ID' => $house, 'STATE' => 'online'));
    $local = $health; $local['enabled'] = false;
    $repo->saveHealthSettings($local, $sensorId);
    check(!$repo->healthSettings($repo->row('zigbee_devices', $sensorId))['enabled'], 'Per-device override is independent of global settings');
    $local = $health; $local['battery_source'] = 'voltage'; $local['voltage_profile'] = 'linear3v';
    $repo->saveHealthSettings($local, $sensorId);
    $roomService->receive('Z2M/House/HealthSensor', '{"battery":90,"voltage":2550}');
    check(T::decode($repo->property($sensorId, 'battery_estimated')['VALUE']) === 50 && T::decode($repo->property($sensorId, 'voltage')['RAW_VALUE']) === 2550, 'On-the-fly battery estimate is separate from raw voltage and reported battery');
    $local['battery_source'] = 'auto'; $repo->saveHealthSettings($local, $sensorId);
    $roomService->receive('Z2M/House/HealthSensor', '{"battery":90,"voltage":2200}');
    $makeMonitor()->tick($now + 9060);
    $batteryState = $pdo->query("SELECT DATA FROM zigbee_health_state WHERE SUBJECT='device:$sensorId:battery'")->fetchColumn();
    check(T::decode($batteryState)['reading'] === 90.0, 'Reported percent takes priority over voltage in automatic mode');
    $repo->saveHealthSettings(array(), $sensorId, true);
    check($repo->row('zigbee_devices', $sensorId)['HEALTH_CONFIG'] === null && $repo->healthSettings($repo->row('zigbee_devices', $sensorId))['events']['offline']['repeat_hours'] === 1, 'Device can return to inherited settings');
    $route = mdj_admin_pretty_url('/admin.php?action=zigbee&md=zigbee&view_mode=monitoring&device_id=' . $sensorId);
    check(str_contains((string)$route, '/panel/zigbee/monitoring.html') && str_contains((string)$route, 'device_id=' . $sensorId), 'Monitoring route preserves device scope');

    $plan = $pdo->query("EXPLAIN QUERY PLAN SELECT * FROM zigbee_bindings WHERE ENABLED=1 AND WRITE_ENABLED=1 AND LINKED_OBJECT='Lamp' AND LINKED_PROPERTY='status'")->fetchAll(PDO::FETCH_ASSOC);
    check(str_contains(json_encode($plan), 'idx_zigbee_binding_target'), 'Исходящая команда использует индекс привязки');
    $before = $adapter->ddl;
    (new zigbee())->propertySetHandle('MissingFixtureObject', 'status', 1);
    (new zigbee())->propertySetHandle('MissingFixtureObject', 'status', 1);
    check($adapter->ddl === $before, 'Повторные обработчики не выполняют DDL');
    $pdo->exec('DROP INDEX idx_zigbee_binding_target');
    Schema::upgrade(Core::database());
    Schema::upgrade(Core::database());
    check((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_zigbee_binding_target'")->fetchColumn() === 1, 'Обновление старой схемы идемпотентно создаёт индекс');
    echo 'Zigbee smoke tests passed: ' . $checks . " checks; SQLite :memory:, no external connections.\n";
}
