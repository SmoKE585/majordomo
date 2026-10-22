<?php

namespace MajorDoMo\Modules\Zigbee;

use Closure;
use InvalidArgumentException;
use MajorDoMo\Core\Core;
use RuntimeException;
use Throwable;

final class Service
{
    private ?array $roots = null;
    private Closure $publish;
    private Closure $set;
    private Closure $call;
    private Closure $room;

    public function __construct(public readonly Repository $repository, ?callable $publish = null, ?callable $set = null, ?callable $call = null, ?callable $room = null)
    {
        $this->publish = Closure::fromCallable($publish ?? static function ($topic, $payload): void {
            $data = ValueTransformer::json(array('v' => $payload, 'q' => 0, 'r' => 0));
            if (!Core::queue()->enqueue('mqtt_queue', $topic, $data, false, 3600)) throw new RuntimeException('Команда MQTT не поставлена в очередь.');
        });
        $this->set = Closure::fromCallable($set ?? static function ($target, $value, $source): void {
            if (!getObject(substr($target, 0, strrpos($target, '.')))) throw new RuntimeException('Привязанный объект не найден: ' . $target);
            $result = setGlobal($target, $value, array('zigbee' => 0), $source);
            if ($result === false || $result === 0) throw new RuntimeException('Запись свойства отклонена: ' . $target);
        });
        $this->call = Closure::fromCallable($call ?? static function ($target, $params): void {
            if (callMethod($target, $params) === false) throw new RuntimeException('Метод не найден или вернул false: ' . $target);
        });
        $this->room = Closure::fromCallable($room ?? static function (int $location, bool $create): string {
            if ($create && !addClassMethod('Rooms', 'deviceChanged')) throw new RuntimeException('Не удалось создать метод Rooms.deviceChanged.');
            $name = getRoomObjectByLocation($location, $create ? 1 : 0);
            if ($name === '') throw new RuntimeException('Объект комнаты не найден.');
            return $name;
        });
    }

    public function configureDevices(array $ids, int $location, bool $adopt = false, ?string $title = null): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids || min($ids) <= 0) throw new InvalidArgumentException('Выберите устройства.');
        if ($title !== null && (count($ids) !== 1 || mb_strlen($title) > 190)) throw new InvalidArgumentException('Название задаётся одному устройству, до 190 символов.');
        if ($location < 0 || ($location && !$this->repository->db->one('SELECT ID FROM locations WHERE ID=' . $location))) throw new InvalidArgumentException('Комната не найдена.');
        return $this->repository->transaction(function () use ($ids, $location, $adopt, $title): int {
            $devices = $this->repository->db->select('SELECT * FROM zigbee_devices WHERE ID IN (' . implode(',', $ids) . ')');
            if (count($devices) !== count($ids)) throw new InvalidArgumentException('Одно из устройств больше не существует. Обновите страницу.');
            if ($location) ($this->room)($location, true);
            $total = 0;
            foreach ($devices as $device) {
                if ($adopt && $device['STATUS'] === 'added') continue;
                $row = array('ID' => $device['ID'], 'LOCATION_ID' => $location);
                if ($title !== null) $row['TITLE'] = $title;
                if ($adopt) $row['STATUS'] = 'added';
                $this->repository->save('zigbee_devices', $row);
                $total++;
            }
            return $total;
        });
    }

    public function subscriptions(): array
    {
        $this->roots = $this->repository->bridges(true);
        return array_map(static fn($b) => $b['BASE_TOPIC'] . '/#', $this->roots);
    }

    public function matches(string $topic): bool
    {
        $this->roots ??= $this->repository->bridges(true);
        foreach ($this->roots as $bridge) if (str_starts_with($topic, $bridge['BASE_TOPIC'] . '/')) return true;
        return false;
    }

    public static function deviceImageUrl(string $model): ?string
    {
        $model = trim($model);
        if ($model === '') return null;
        // Use the same filename convention as the Zigbee2MQTT frontend.
        $model = preg_replace('/[:\s\/]/u', '-', $model);
        return 'https://www.zigbee2mqtt.io/images/devices/' . rawurlencode($model) . '.png';
    }

    public function receive(string $topic, string $payload): void
    {
        // Workers are short-lived; always respect the current enabled state.
        foreach ($this->repository->bridges(true) as $bridge) {
            if (!str_starts_with($topic, $bridge['BASE_TOPIC'] . '/')) continue;
            $suffix = substr($topic, strlen($bridge['BASE_TOPIC']) + 1);
            try {
                if (str_starts_with($suffix, 'bridge/')) { $this->bridgeMessage($bridge, $suffix, $payload); return; }
                if (preg_match('#/(set|get)(/.*)?$#D', $suffix)) return;
                $availability = str_ends_with($suffix, '/availability');
                $name = $availability ? substr($suffix, 0, -13) : $suffix;
                $value = $availability && in_array($payload, array('online', 'offline'), true) ? $payload : json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
                $device = $this->repository->device((int)$bridge['ID'], $name);
                $now = date('Y-m-d H:i:s');
                $update = array('ID' => $device['ID']);
                if ($availability) {
                    $state = is_array($value) ? ($value['state'] ?? '') : $value;
                    if (!in_array($state, array('online', 'offline'), true)) throw new InvalidArgumentException('Неизвестный статус доступности.');
                    $update['AVAILABLE'] = $state;
                    if ($state === 'online') $update['LAST_SEEN'] = $now;
                    $this->repository->save('zigbee_devices', $update);
                } else {
                    if (!is_array($value) || !str_starts_with(ltrim($payload), '{')) throw new InvalidArgumentException('Ожидался JSON-объект состояния устройства.');
                    $update['AVAILABLE'] = 'online';
                    $update['LAST_SEEN'] = $now;
                    // Receiving data proves contact even when an object handler
                    // subsequently fails. Monitoring must not confuse the two.
                    $this->repository->save('zigbee_devices', $update);
                    $failures = array();
                    $values = self::flatten($value);
                    $health = $this->repository->healthSettings($device);
                    $metadata = ValueTransformer::decode($device['METADATA']) ?? array();
                    $batteryPowered = str_contains(strtolower((string)($metadata['power_source'] ?? '')), 'battery');
                    $reported = $values['battery'] ?? null;
                    if ($health['battery_source'] !== 'off' && ($health['battery_source'] === 'voltage' || $batteryPowered)
                        && !($health['battery_source'] === 'auto' && Battery::number($reported) && $reported >= 0 && $reported <= 100)) {
                        $estimated = Battery::estimate($values[$health['voltage_path']] ?? null, $health);
                        if ($estimated !== null) {
                            $values['battery_estimated'] = $estimated;
                            $this->repository->property((int)$device['ID'], 'battery_estimated', array('type' => 'numeric', 'property' => 'battery_estimated',
                                'label' => 'Заряд батареи (примерно)', 'unit' => '%', 'access' => 1, 'source' => 'voltage'));
                        }
                    }
                    // A button action is an event even when the same action repeats.
                    // Keep the ordinary action value and expose a selectable event
                    // property whose value changes on each received press.
                    if (isset($value['action']) && is_string($value['action']) && $value['action'] !== '') {
                        $eventPath = 'action:' . $value['action'];
                        if (strlen($eventPath) <= 190) $values[$eventPath] = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
                    }
                    foreach ($values as $path => $raw) {
                        try { $this->deliver($bridge, $device, $path, $raw, $topic); }
                        catch (Throwable $e) { $failures[] = $path . ': ' . $e->getMessage(); }
                    }
                    if ($failures) throw new RuntimeException(implode('; ', $failures));
                }
                return;
            } catch (Throwable $e) {
                $this->repository->log((int)$bridge['ID'], null, $topic, $e->getMessage(), 'error');
                throw $e;
            }
        }
    }

    private function bridgeMessage(array $bridge, string $suffix, string $payload): void
    {
        $id = (int)$bridge['ID'];
        // Ignore our own requests and large converter/definition snapshots.
        if (str_starts_with($suffix, 'bridge/request/') || in_array($suffix, array('bridge/definitions', 'bridge/converters', 'bridge/extensions'), true)) return;
        $data = $suffix === 'bridge/state' && in_array($payload, array('online', 'offline'), true) ? $payload : json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        if ($suffix === 'bridge/devices') {
            if (!is_array($data) || !array_is_list($data)) throw new InvalidArgumentException('Список устройств должен быть JSON-массивом.');
            $replay = array();
            $this->repository->transaction(function () use ($id, $data, &$replay): void {
                foreach ($data as $metadata) {
                    if (!is_array($metadata) || empty($metadata['ieee_address'])) throw new InvalidArgumentException('В описании устройства нет IEEE-адреса.');
                    if (($metadata['type'] ?? '') === 'Coordinator') continue;
                    $name = (string)($metadata['friendly_name'] ?? $metadata['ieee_address']);
                    $device = $this->repository->device($id, $name, (string)$metadata['ieee_address']);
                    if (!empty($device['_REPLAY'])) $replay[] = array($device, $device['_REPLAY']);
                    $definition = $metadata['definition'] ?? array();
                    $this->repository->save('zigbee_devices', array('ID' => $device['ID'], 'IEEE_ADDRESS' => strtolower($metadata['ieee_address']),
                        'FRIENDLY_NAME' => $name, 'MODEL' => mb_substr((string)($definition['model'] ?? $metadata['model_id'] ?? ''), 0, 190),
                        'VENDOR' => mb_substr((string)($definition['vendor'] ?? ''), 0, 190),
                        'DESCRIPTION' => (string)($definition['description'] ?? ''), 'METADATA' => ValueTransformer::json($metadata)));
                    foreach (self::exposes($definition['exposes'] ?? array()) as $path => $expose) $this->repository->property((int)$device['ID'], $path, $expose);
                }
            });
            foreach ($replay as [$previousDevice, $values]) {
                $current = $this->repository->row('zigbee_devices', (int)$previousDevice['ID']);
                foreach ($values as $path => $raw) $this->deliver($bridge, $current, $path, $raw, $bridge['BASE_TOPIC'] . '/' . $current['FRIENDLY_NAME']);
            }
            $this->repository->log($id, null, $suffix, 'Обнаружение завершено. Устройств в сообщении: ' . count($data));
        } elseif ($suffix === 'bridge/state') {
            $state = is_array($data) ? ($data['state'] ?? '') : $data;
            if (!in_array($state, array('online', 'offline'), true)) throw new InvalidArgumentException('Неизвестный статус Zigbee2MQTT.');
            $this->repository->save('zigbee_bridges', array('ID' => $id, 'STATE' => $state, 'LAST_SEEN' => date('Y-m-d H:i:s')));
        } elseif ($suffix === 'bridge/info') {
            $this->repository->save('zigbee_bridges', array('ID' => $id, 'INFO' => ValueTransformer::json($data), 'LAST_SEEN' => date('Y-m-d H:i:s')));
        } elseif ($suffix === 'bridge/event' || str_starts_with($suffix, 'bridge/response/') || $suffix === 'bridge/logging') {
            $level = is_array($data) && (($data['status'] ?? '') === 'error' || ($data['level'] ?? '') === 'error') ? 'error' : 'info';
            if ($suffix === 'bridge/event' && ($data['type'] ?? '') === 'device_leave' && !empty($data['data']['ieee_address'])) {
                $device = $this->repository->db->one('SELECT ID FROM zigbee_devices WHERE BRIDGE_ID=' . $id . ' AND IEEE_ADDRESS=' . $this->repository->quote($data['data']['ieee_address']));
                if ($device) $this->repository->save('zigbee_devices', array('ID' => $device['ID'], 'AVAILABLE' => 'offline'));
            }
            $this->repository->log($id, null, $suffix, $payload, $level);
        }
    }

    private function deliver(array $bridge, array $device, string $path, mixed $raw, string $topic): void
    {
        $property = $this->repository->property((int)$device['ID'], $path);
        $binding = $this->repository->binding((int)$property['ID']);
        $this->repository->save('zigbee_properties', array('ID' => $property['ID'], 'RAW_VALUE' => ValueTransformer::json($raw), 'UPDATED' => date('Y-m-d H:i:s')));
        try {
            $value = $binding && $binding['ENABLED'] ? ValueTransformer::apply($raw, ValueTransformer::decode($binding['INCOMING']) ?? array(), ValueTransformer::decode($property['EXPOSE']) ?? array()) : $raw;
            $encoded = ValueTransformer::json($value);
            $this->repository->save('zigbee_properties', array('ID' => $property['ID'], 'VALUE' => $encoded));
            if ($device['STATUS'] !== 'added') return;
            $deliverBinding = $binding && $binding['ENABLED'] && !($binding['EVENT_MODE'] === 'change' && $binding['LAST_DELIVERED'] === $encoded);
            $source = 'zigbee:' . $bridge['BASE_TOPIC'] . '/' . $device['FRIENDLY_NAME'] . ':' . $path;
            // New value must be visible to both ONCHANGE and the explicit method.
            if ($deliverBinding && $binding['LINKED_OBJECT'] !== '' && $binding['LINKED_PROPERTY'] !== '') ($this->set)($binding['LINKED_OBJECT'] . '.' . $binding['LINKED_PROPERTY'], $value, $source);
            if ($deliverBinding && $binding['LINKED_OBJECT'] !== '' && $binding['LINKED_METHOD'] !== '') {
                ($this->call)($binding['LINKED_OBJECT'] . '.' . $binding['LINKED_METHOD'], array('VALUE' => $value, 'NEW_VALUE' => $value,
                    'OLD_VALUE' => ValueTransformer::decode($binding['LAST_DELIVERED']), 'RAW_VALUE' => $raw, 'TITLE' => $path,
                    'SOURCE' => $source, 'IEEE_ADDRESS' => $device['IEEE_ADDRESS'], 'BRIDGE_ID' => (int)$bridge['ID']));
            }
            if ($device['LOCATION_ID']) {
                if (!$this->repository->db->one('SELECT ID FROM locations WHERE ID=' . (int)$device['LOCATION_ID'])) throw new RuntimeException('Комната устройства удалена. Выберите другую комнату.');
                $room = ($this->room)((int)$device['LOCATION_ID'], false);
                ($this->call)($room . '.deviceChanged', array('DEVICE_ID' => (int)$device['ID'], 'DEVICE_NAME' => $device['FRIENDLY_NAME'],
                    'DEVICE_TITLE' => $device['TITLE'], 'IEEE_ADDRESS' => $device['IEEE_ADDRESS'], 'BRIDGE_ID' => (int)$bridge['ID'],
                    'BASE_TOPIC' => $bridge['BASE_TOPIC'], 'LOCATION_ID' => (int)$device['LOCATION_ID'], 'TOPIC' => $topic,
                    'PROPERTY' => $path, 'TITLE' => $path, 'VALUE' => $value, 'NEW_VALUE' => $value,
                    'OLD_VALUE' => ValueTransformer::decode($property['VALUE']), 'RAW_VALUE' => $raw, 'SOURCE' => $source));
            }
            if (!$deliverBinding && !$device['LOCATION_ID']) return;
            if ($deliverBinding) $this->repository->save('zigbee_bindings', array('ID' => $binding['ID'], 'LAST_DELIVERED' => $encoded, 'LAST_ERROR' => null));
            $this->repository->log((int)$bridge['ID'], (int)$device['ID'], $topic, $path . ': ' . ValueTransformer::json($raw) . ' → ' . $encoded . ' · обработано');
        } catch (Throwable $e) {
            if ($binding) $this->repository->save('zigbee_bindings', array('ID' => $binding['ID'], 'LAST_ERROR' => $e->getMessage()));
            throw $e;
        }
    }

    public function write(int $propertyId, mixed $value): void
    {
        $property = $this->repository->row('zigbee_properties', $propertyId);
        if (!$property) throw new InvalidArgumentException('Свойство не найдено.');
        $device = $this->repository->row('zigbee_devices', (int)$property['DEVICE_ID']);
        $bridge = $this->repository->row('zigbee_bridges', (int)$device['BRIDGE_ID']);
        if (!$bridge['ENABLED']) throw new InvalidArgumentException('Экземпляр Zigbee2MQTT выключен.');
        if ($device['STATUS'] !== 'added') throw new InvalidArgumentException('Сначала добавьте устройство из раздела «Обнаруженные».');
        $expose = ValueTransformer::decode($property['EXPOSE']) ?? array();
        if (!isset($expose['access']) || !((int)$expose['access'] & 2)) throw new InvalidArgumentException('Устройство не разрешает запись этого свойства (exposes).');
        $binding = $this->repository->binding($propertyId);
        $value = ValueTransformer::apply($value, ValueTransformer::decode($binding['OUTGOING'] ?? null) ?? array(), $expose, true);
        if (($expose['type'] ?? '') === 'binary') {
            $accepted = false;
            foreach (array('value_on', 'value_off', 'value_toggle') as $key) {
                if (array_key_exists($key, $expose) && ValueTransformer::equal($value, $expose[$key])) $accepted = true;
            }
            if (!$accepted) throw new InvalidArgumentException('Недопустимое бинарное значение устройства. Проверьте преобразование отправки.');
        }
        if (($expose['type'] ?? '') === 'numeric' && (!is_numeric($value) || !is_finite((float)$value))) throw new InvalidArgumentException('Для этого свойства требуется конечное число.');
        if (isset($expose['value_min']) && (!is_numeric($value) || $value < $expose['value_min'])) throw new InvalidArgumentException('Значение ниже допустимого минимума.');
        if (isset($expose['value_max']) && (!is_numeric($value) || $value > $expose['value_max'])) throw new InvalidArgumentException('Значение выше допустимого максимума.');
        if (isset($expose['values']) && !in_array($value, $expose['values'], true)) throw new InvalidArgumentException('Значение отсутствует в списке допустимых.');
        $data = $value;
        foreach (array_reverse(explode('.', $property['PATH'])) as $key) $data = array($key => $data);
        ($this->publish)($bridge['BASE_TOPIC'] . '/' . $device['FRIENDLY_NAME'] . '/set', ValueTransformer::json($data));
        $this->repository->log((int)$bridge['ID'], (int)$device['ID'], $property['PATH'], 'Команда поставлена в очередь: ' . ValueTransformer::json($value));
    }

    public function propertySetHandle(string $object, string $property, mixed $value): void
    {
        $rows = $this->repository->db->select('SELECT * FROM zigbee_bindings WHERE ENABLED=1 AND WRITE_ENABLED=1 AND LINKED_OBJECT='
            . $this->repository->quote($object) . ' AND LINKED_PROPERTY=' . $this->repository->quote($property));
        foreach ($rows as $row) $this->write((int)$row['PROPERTY_ID'], $value);
    }

    public function command(int $bridgeId, string $command, array $data): void
    {
        $allowed = array('permit_join', 'device/rename', 'device/remove', 'device/configure', 'device/interview', 'device/options', 'health_check', 'networkmap');
        if (!in_array($command, $allowed, true)) throw new InvalidArgumentException('Неизвестная команда Zigbee2MQTT.');
        $bridge = $this->repository->row('zigbee_bridges', $bridgeId);
        if (!$bridge || !$bridge['ENABLED']) throw new InvalidArgumentException('Экземпляр Zigbee2MQTT выключен или не найден.');
        $data['transaction'] = 'mjd-' . bin2hex(random_bytes(8));
        ($this->publish)($bridge['BASE_TOPIC'] . '/bridge/request/' . $command, ValueTransformer::json($data));
        $this->repository->log($bridgeId, null, $command, 'Запрос отправлен: ' . ValueTransformer::json($data));
    }

    public static function flatten(array $values, string $prefix = ''): array
    {
        $result = array();
        foreach ($values as $key => $value) {
            $path = $prefix . $key;
            $result[$path] = $value;
            if (is_array($value) && !array_is_list($value)) $result += self::flatten($value, $path . '.');
        }
        return $result;
    }

    public static function exposes(array $features, string $prefix = ''): array
    {
        $result = array();
        foreach ($features as $feature) {
            $property = $feature['property'] ?? '';
            if ($property !== '') $result[$prefix . $property] = $feature;
            if (isset($feature['features'])) $result += self::exposes($feature['features'], $property !== '' ? $prefix . $property . '.' : $prefix);
        }
        return $result;
    }
}
