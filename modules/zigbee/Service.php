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

    public function __construct(public readonly Repository $repository, ?callable $publish = null, ?callable $set = null, ?callable $call = null)
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
                $update = array('ID' => $device['ID'], 'LAST_SEEN' => $now);
                if ($availability) {
                    $state = is_array($value) ? ($value['state'] ?? '') : $value;
                    if (!in_array($state, array('online', 'offline'), true)) throw new InvalidArgumentException('Неизвестный статус доступности.');
                    $update['AVAILABLE'] = $state;
                } else {
                    if (!is_array($value) || !str_starts_with(ltrim($payload), '{')) throw new InvalidArgumentException('Ожидался JSON-объект состояния устройства.');
                    $update['AVAILABLE'] = 'online';
                    $failures = array();
                    foreach (self::flatten($value) as $path => $raw) {
                        try { $this->deliver($bridge, $device, $path, $raw, $topic); }
                        catch (Throwable $e) { $failures[] = $path . ': ' . $e->getMessage(); }
                    }
                    if ($failures) throw new RuntimeException(implode('; ', $failures));
                }
                $this->repository->save('zigbee_devices', $update);
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
            if (!$binding || !$binding['ENABLED']) return;
            if ($binding['EVENT_MODE'] === 'change' && $binding['LAST_DELIVERED'] === $encoded) return;
            $source = 'zigbee:' . $bridge['BASE_TOPIC'] . '/' . $device['FRIENDLY_NAME'] . ':' . $path;
            // New value must be visible to both ONCHANGE and the explicit method.
            if ($binding['LINKED_OBJECT'] !== '' && $binding['LINKED_PROPERTY'] !== '') ($this->set)($binding['LINKED_OBJECT'] . '.' . $binding['LINKED_PROPERTY'], $value, $source);
            if ($binding['LINKED_OBJECT'] !== '' && $binding['LINKED_METHOD'] !== '') {
                ($this->call)($binding['LINKED_OBJECT'] . '.' . $binding['LINKED_METHOD'], array('VALUE' => $value, 'NEW_VALUE' => $value,
                    'OLD_VALUE' => ValueTransformer::decode($binding['LAST_DELIVERED']), 'RAW_VALUE' => $raw, 'TITLE' => $path,
                    'SOURCE' => $source, 'IEEE_ADDRESS' => $device['IEEE_ADDRESS'], 'BRIDGE_ID' => (int)$bridge['ID']));
            }
            $this->repository->save('zigbee_bindings', array('ID' => $binding['ID'], 'LAST_DELIVERED' => $encoded, 'LAST_ERROR' => null));
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
