<?php

namespace MajorDoMo\Modules\Zigbee;

use MajorDoMo\Core\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class Repository
{
    public function __construct(public readonly Database $db) {}

    public function quote(string $value): string { return "'" . $this->db->escape($value) . "'"; }
    public function bridges(bool $enabled = false): array
    {
        return $this->db->select('SELECT * FROM zigbee_bridges' . ($enabled ? ' WHERE ENABLED=1' : '') . ' ORDER BY ID');
    }
    public function row(string $table, int $id): ?array
    {
        $this->table($table);
        return $this->db->one('SELECT * FROM ' . $table . ' WHERE ID=' . $id);
    }
    private function table(string $table): void
    {
        if (!in_array($table, array('zigbee_bridges', 'zigbee_devices', 'zigbee_properties', 'zigbee_bindings', 'zigbee_events', 'zigbee_health_state'), true)) throw new InvalidArgumentException('Unknown Zigbee table');
    }
    public function save(string $table, array $row): int
    {
        $this->table($table);
        if (!empty($row['ID'])) {
            if (!$this->db->update($table, $row)) throw new RuntimeException('Не удалось сохранить запись Zigbee.');
            return (int)$row['ID'];
        }
        unset($row['ID']);
        // Legacy mysql::Insert quotes null as ''. Omit nullable columns so SQL
        // uses their NULL default (notably provisional IEEE addresses).
        $row = array_filter($row, static fn($value) => $value !== null);
        $id = $this->db->insert($table, $row);
        if (!$id) throw new RuntimeException('Не удалось создать запись Zigbee.');
        return $id;
    }
    public function transaction(callable $work): mixed
    {
        $this->db->requireExec('START TRANSACTION');
        try { $result = $work(); $this->db->requireExec('COMMIT'); return $result; }
        catch (Throwable $e) { $this->db->requireExec('ROLLBACK'); throw $e; }
    }
    public function saveBridge(array $row): int
    {
        if (!empty($row['ID']) && !$this->row('zigbee_bridges', (int)$row['ID'])) throw new InvalidArgumentException('Экземпляр не найден.');
        $row['BASE_TOPIC'] = trim((string)$row['BASE_TOPIC'], " /\t\r\n");
        if ($row['BASE_TOPIC'] === '' || strlen($row['BASE_TOPIC']) > 190 || preg_match('/[+#\x00-\x1f]/', $row['BASE_TOPIC'])) {
            throw new InvalidArgumentException('Укажите корневой топик без + и #, не длиннее 190 байт.');
        }
        if (trim($row['TITLE']) === '' || mb_strlen($row['TITLE']) > 190) throw new InvalidArgumentException('Укажите название до 190 символов.');
        foreach ($this->bridges() as $bridge) {
            if ((int)$bridge['ID'] === (int)($row['ID'] ?? 0)) {
                if ($bridge['BASE_TOPIC'] !== $row['BASE_TOPIC']) throw new InvalidArgumentException('Корень существующего экземпляра менять нельзя: создайте новый экземпляр.');
                continue;
            }
            $a = $bridge['BASE_TOPIC'] . '/'; $b = $row['BASE_TOPIC'] . '/';
            if (str_starts_with($a, $b) || str_starts_with($b, $a)) throw new InvalidArgumentException('Корневые топики экземпляров не должны пересекаться.');
        }
        return $this->save('zigbee_bridges', $row);
    }
    public function device(int $bridge, string $name, ?string $ieee = null): array
    {
        if ($name === '' || strlen($name) > 190 || preg_match('/[+#\x00-\x1f]/', $name)) throw new InvalidArgumentException('Некорректное имя устройства.');
        $ieee = $ieee !== null ? strtolower($ieee) : null;
        $row = $ieee ? $this->db->one('SELECT * FROM zigbee_devices WHERE BRIDGE_ID=' . $bridge . ' AND IEEE_ADDRESS=' . $this->quote($ieee)) : null;
        $named = $this->db->one('SELECT * FROM zigbee_devices WHERE BRIDGE_ID=' . $bridge . ' AND FRIENDLY_NAME=' . $this->quote($name));
        if ($row && $named && $row['ID'] !== $named['ID']) {
            if (!empty($named['IEEE_ADDRESS'])) throw new InvalidArgumentException('Имя устройства занято другим IEEE-адресом.');
            // Discovery owns the surrounding transaction. Reconcile an early
            // report under the new name without losing existing bindings.
            $row['_REPLAY'] = $this->mergeProvisional((int)$row['ID'], (int)$named['ID']);
        }
        $row ??= $named;
        if (!$row) {
            $id = $this->save('zigbee_devices', array('BRIDGE_ID' => $bridge, 'IEEE_ADDRESS' => $ieee, 'FRIENDLY_NAME' => $name));
            $row = $this->row('zigbee_devices', $id);
        }
        return $row;
    }

    private function mergeProvisional(int $target, int $source): array
    {
        $replay = array();
        $fromDevice = $this->row('zigbee_devices', $source);
        $toDevice = $this->row('zigbee_devices', $target);
        if (!empty($fromDevice['HEALTH_CONFIG'])) {
            if (!empty($toDevice['HEALTH_CONFIG']) && $fromDevice['HEALTH_CONFIG'] !== $toDevice['HEALTH_CONFIG']) throw new InvalidArgumentException('Разные настройки мониторинга у объединяемых устройств.');
            $this->save('zigbee_devices', array('ID' => $target, 'HEALTH_CONFIG' => $fromDevice['HEALTH_CONFIG']));
        }
        if ($fromDevice['STATUS'] === 'added' && $toDevice['STATUS'] !== 'added') {
            $this->save('zigbee_devices', array('ID' => $target, 'STATUS' => 'added', 'LOCATION_ID' => $fromDevice['LOCATION_ID'], 'TITLE' => $fromDevice['TITLE']));
        } elseif ($fromDevice['STATUS'] === 'added' && $toDevice['STATUS'] === 'added'
            && $fromDevice['LOCATION_ID'] && $toDevice['LOCATION_ID'] && $fromDevice['LOCATION_ID'] !== $toDevice['LOCATION_ID']) {
            throw new InvalidArgumentException('Разные комнаты у объединяемых устройств.');
        } elseif ($fromDevice['STATUS'] === 'added' && $fromDevice['LOCATION_ID'] && !$toDevice['LOCATION_ID']) {
            $this->save('zigbee_devices', array('ID' => $target, 'LOCATION_ID' => $fromDevice['LOCATION_ID']));
        }
        foreach ($this->db->select('SELECT * FROM zigbee_properties WHERE DEVICE_ID=' . $source) as $property) {
            $existing = $this->db->one('SELECT * FROM zigbee_properties WHERE DEVICE_ID=' . $target . ' AND PATH=' . $this->quote($property['PATH']));
            if (!$existing) {
                $this->save('zigbee_properties', array('ID' => $property['ID'], 'DEVICE_ID' => $target));
                continue;
            }
            $from = $this->binding((int)$property['ID']); $to = $this->binding((int)$existing['ID']);
            if ($from && $to) throw new InvalidArgumentException('Конфликт привязок при переименовании: ' . $property['PATH']);
            if ($from) $this->save('zigbee_bindings', array('ID' => $from['ID'], 'PROPERTY_ID' => $existing['ID']));
            if ($property['RAW_VALUE'] !== null && ($existing['UPDATED'] === null || $property['UPDATED'] >= $existing['UPDATED'])) {
                $this->save('zigbee_properties', array('ID' => $existing['ID'], 'RAW_VALUE' => $property['RAW_VALUE'], 'VALUE' => $property['VALUE'], 'UPDATED' => $property['UPDATED']));
                $replay[$property['PATH']] = ValueTransformer::decode($property['RAW_VALUE']);
            }
            $this->db->requireExec('DELETE FROM zigbee_properties WHERE ID=' . (int)$property['ID']);
        }
        $this->db->requireExec('UPDATE zigbee_events SET DEVICE_ID=' . $target . ' WHERE DEVICE_ID=' . $source);
        $this->db->requireExec('DELETE FROM zigbee_devices WHERE ID=' . $source);
        $this->db->requireExec('DELETE FROM zigbee_health_state WHERE SUBJECT LIKE ' . $this->quote('device:' . $source . ':%'));
        return $replay;
    }

    public function healthSettings(?array $device = null): array
    {
        $row = $this->db->one('SELECT CONFIG FROM zigbee_health_settings WHERE ID=1');
        $global = HealthSettings::validate(ValueTransformer::decode($row['CONFIG'] ?? null) ?? array());
        return empty($device['HEALTH_CONFIG']) ? $global : HealthSettings::validate(ValueTransformer::decode($device['HEALTH_CONFIG']));
    }

    public function saveHealthSettings(array $settings, int $deviceId = 0, bool $inherit = false): void
    {
        $config = ValueTransformer::json(HealthSettings::validate($settings));
        if ($deviceId) {
            if (!$this->row('zigbee_devices', $deviceId)) throw new InvalidArgumentException('Устройство не найдено.');
            $this->save('zigbee_devices', array('ID' => $deviceId, 'HEALTH_CONFIG' => $inherit ? null : $config));
        } elseif ($this->db->one('SELECT ID FROM zigbee_health_settings WHERE ID=1')) {
            if (!$this->db->update('zigbee_health_settings', array('ID' => 1, 'CONFIG' => $config))) throw new RuntimeException('Не удалось сохранить мониторинг.');
        } else {
            $record = array('ID' => 1, 'CONFIG' => $config);
            if (!$this->db->insert('zigbee_health_settings', $record)) throw new RuntimeException('Не удалось сохранить мониторинг.');
        }
    }
    public function property(int $device, string $path, ?array $expose = null): array
    {
        if ($path === '' || strlen($path) > 190) throw new InvalidArgumentException('Путь свойства слишком длинный или пустой.');
        $row = $this->db->one('SELECT * FROM zigbee_properties WHERE DEVICE_ID=' . $device . ' AND PATH=' . $this->quote($path));
        if (!$row) {
            $id = $this->save('zigbee_properties', array('DEVICE_ID' => $device, 'PATH' => $path));
            $row = $this->row('zigbee_properties', $id);
        }
        if ($expose !== null) {
            $row['EXPOSE'] = ValueTransformer::json($expose);
            $row['LABEL'] = mb_substr((string)($expose['label'] ?? $path), 0, 190);
            $this->save('zigbee_properties', array('ID' => $row['ID'], 'EXPOSE' => $row['EXPOSE'], 'LABEL' => $row['LABEL']));
        }
        return $row;
    }
    public function binding(int $property): ?array
    {
        return $this->db->one('SELECT * FROM zigbee_bindings WHERE PROPERTY_ID=' . $property);
    }
    public function properties(int $device): array
    {
        return $this->db->select('SELECT p.*,b.ID AS BINDING_ID,b.LINKED_OBJECT,b.LINKED_PROPERTY,b.LINKED_METHOD,b.LAST_ERROR FROM zigbee_properties p LEFT JOIN zigbee_bindings b ON b.PROPERTY_ID=p.ID WHERE p.DEVICE_ID=' . $device . ' ORDER BY p.PATH');
    }
    public function log(int $bridge, ?int $device, string $topic, string $message, string $level = 'info'): void
    {
        $this->save('zigbee_events', array('BRIDGE_ID' => $bridge, 'DEVICE_ID' => $device, 'TOPIC' => mb_substr($topic, 0, 512),
            'MESSAGE' => mb_substr($message, 0, 12000), 'LEVEL' => $level, 'ADDED' => date('Y-m-d H:i:s')));
        // Bound the journal per bridge; the primary key supports this lookup.
        $cut = $this->db->one('SELECT ID FROM zigbee_events WHERE BRIDGE_ID=' . $bridge . ' ORDER BY ID DESC LIMIT 1 OFFSET 999');
        if ($cut) $this->db->requireExec('DELETE FROM zigbee_events WHERE BRIDGE_ID=' . $bridge . ' AND ID<' . (int)$cut['ID']);
    }
}
