<?php

namespace MajorDoMo\Modules\Zigbee;

use Closure;
use MajorDoMo\Core\Core;
use MajorDoMo\Core\Cycles\CycleStateStore;
use RuntimeException;
use Throwable;

/** One persisted incident pipeline for availability and battery events. */
final class HealthMonitor
{
    private Closure $say;
    private Closure $code;
    private Closure $transport;

    public function __construct(private Repository $repository, ?callable $say = null, ?callable $code = null, ?callable $transport = null)
    {
        $this->say = Closure::fromCallable($say ?? static function (string $message, int $level): void { say($message, $level, 0, 'zigbee'); });
        $this->code = Closure::fromCallable($code ?? function (string $code, array $params): void {
            $variables = array('params' => $params, 'event' => $params['EVENT']);
            setEvalCode($code);
            if (Core::code()->evaluate($this, $code, $variables) === false) throw new RuntimeException('PHP-обработчик мониторинга вернул false.');
        });
        $this->transport = Closure::fromCallable($transport ?? static function (): ?string {
            if (!(new CycleStateStore(ROOT))->isRunning('cycle_mqtt')) return null;
            $heartbeat = (int)getGlobal('cycle_mqttRun');
            $connected = getGlobal('mqttConnected');
            if (!$heartbeat || $connected === null || $connected === '') return null;
            return $connected && time() - $heartbeat <= 90 ? 'online' : 'offline';
        });
    }

    public function tick(?int $now = null): void
    {
        $now ??= time();
        $db = $this->repository->db;
        // MINUTELY may overlap with a slow user handler. Only one poll owns the
        // state machine; no SQL transaction is held while running user code.
        $lock = $db->one("SELECT GET_LOCK(CONCAT('mdj_zigbee_health:',DATABASE()),0) AS ACQUIRED");
        if ((int)($lock['ACQUIRED'] ?? 0) !== 1) return;
        try {
            $settings = $this->repository->healthSettings();
            $bridges = $this->repository->bridges(true);
            if (!$settings['enabled'] || !$bridges) return;
            $transport = ($this->transport)();
            if ($transport === null) return; // stopped, disabled or not initialized
            $context = array('KIND' => 'mqtt', 'ID' => 0, 'DEVICE_ID' => 0, 'DEVICE_TITLE' => 'MQTT-брокер',
                'BRIDGE_ID' => (int)$bridges[0]['ID'], 'LOCATION_ID' => 0, 'ROOM' => '', 'SOURCE' => 'zigbee', 'IEEE_ADDRESS' => '', 'BASE_TOPIC' => '');
            $this->observe('mqtt:availability', 'availability', $transport, null, 'mqtt', $settings, $context, $now);
            if ($transport !== 'online') return; // suppress all dependent incidents
            foreach ($bridges as $bridge) {
                $context = array_replace($context, array('KIND' => 'bridge', 'ID' => (int)$bridge['ID'], 'BRIDGE_ID' => (int)$bridge['ID'],
                    'DEVICE_TITLE' => $bridge['TITLE'], 'BASE_TOPIC' => $bridge['BASE_TOPIC']));
                if (!in_array($bridge['STATE'], array('online', 'offline'), true)) continue;
                $this->observe('bridge:' . $bridge['ID'] . ':availability', 'availability', $bridge['STATE'], null, 'bridge', $settings, $context, $now);
                if ($bridge['STATE'] !== 'online') continue;
                $devices = $db->select("SELECT d.*,l.TITLE AS ROOM FROM zigbee_devices d LEFT JOIN locations l ON l.ID=d.LOCATION_ID WHERE d.STATUS='added' AND d.BRIDGE_ID=" . (int)$bridge['ID']);
                foreach ($devices as $device) {
                    try { $this->device($bridge, $device, $settings, $now); }
                    catch (Throwable $e) { $this->repository->log((int)$bridge['ID'], (int)$device['ID'], 'monitor', $e->getMessage(), 'error'); }
                }
            }
        } finally { $db->one("SELECT RELEASE_LOCK(CONCAT('mdj_zigbee_health:',DATABASE()))"); }
    }

    private function device(array $bridge, array $device, array $global, int $now): void
    {
        $settings = empty($device['HEALTH_CONFIG']) ? $global : HealthSettings::validate(ValueTransformer::decode($device['HEALTH_CONFIG']));
        if (!$settings['enabled']) return;
        $context = array('KIND' => 'device', 'ID' => (int)$device['ID'], 'DEVICE_ID' => (int)$device['ID'],
            'DEVICE_TITLE' => $device['TITLE'] ?: $device['FRIENDLY_NAME'], 'DEVICE_NAME' => $device['FRIENDLY_NAME'],
            'IEEE_ADDRESS' => $device['IEEE_ADDRESS'], 'BRIDGE_ID' => (int)$bridge['ID'], 'BASE_TOPIC' => $bridge['BASE_TOPIC'],
            'LOCATION_ID' => (int)$device['LOCATION_ID'], 'ROOM' => $device['ROOM'] ?? '', 'SOURCE' => 'zigbee');
        $metadata = ValueTransformer::decode($device['METADATA']) ?? array();
        $batteryPowered = str_contains(strtolower((string)($metadata['power_source'] ?? '')), 'battery');
        $timeout = $settings[$batteryPowered ? 'timeout_battery' : 'timeout_mains'];
        $state = $device['AVAILABLE']; $reason = 'availability';
        if ($timeout && $device['LAST_SEEN'] && $now - strtotime($device['LAST_SEEN']) >= $timeout * 60) { $state = 'offline'; $reason = 'timeout'; }
        if (in_array($state, array('online', 'offline'), true)) $this->observe('device:' . $device['ID'] . ':availability', 'availability', $state, null, $reason, $settings, $context, $now);
        if ($settings['battery_source'] === 'off') return;
        $rows = $this->repository->db->select('SELECT PATH,RAW_VALUE,UPDATED FROM zigbee_properties WHERE DEVICE_ID=' . (int)$device['ID']);
        $values = array_column($rows, 'RAW_VALUE', 'PATH');
        $raw = ValueTransformer::decode($values['battery'] ?? null);
        $estimated = false; $percent = null;
        if ($settings['battery_source'] === 'auto' && Battery::number($raw) && $raw >= 0 && $raw <= 100) $percent = (float)$raw;
        elseif (($settings['battery_source'] === 'voltage' || $batteryPowered) && $settings['voltage_profile'] !== 'none') {
            $percent = Battery::estimate(ValueTransformer::decode($values[$settings['voltage_path']] ?? null), $settings);
            $estimated = $percent !== null;
        }
        $lowFlag = ValueTransformer::decode($values['battery_low'] ?? null);
        if ($percent === null && !is_bool($lowFlag) && !in_array($lowFlag, array(0, 1), true)) return;
        $subject = 'device:' . $device['ID'] . ':battery';
        $previous = $this->state($subject);
        $level = $percent !== null && $percent <= $settings['critical'] ? 'critical'
            : (($lowFlag === true || $lowFlag === 1 || ($percent !== null && $percent <= $settings['low'])) ? 'low' : 'normal');
        // Do not reset an incident on small voltage rebounds around the threshold.
        if ($level === 'normal' && $percent !== null && $percent < $settings['recovery'] && in_array($previous['current'] ?? '', array('low', 'critical'), true)) $level = 'low';
        $context['ESTIMATED'] = $estimated;
        $context['VOLTAGE'] = ValueTransformer::decode($values[$settings['voltage_path']] ?? null);
        $this->observe($subject, 'battery', $level, $percent, $estimated ? 'voltage' : 'battery', $settings, $context, $now);
    }

    private function state(string $subject): array
    {
        $row = $this->repository->db->one('SELECT * FROM zigbee_health_state WHERE SUBJECT=' . $this->repository->quote($subject));
        return $row ? array_replace(ValueTransformer::decode($row['DATA']), array('_id' => (int)$row['ID'])) : array();
    }

    private function persist(string $subject, array &$state): void
    {
        $data = $state; unset($data['_id']);
        $state['_id'] = $this->repository->save('zigbee_health_state', array('ID' => $state['_id'] ?? 0, 'SUBJECT' => $subject, 'DATA' => ValueTransformer::json($data)));
    }

    private function observe(string $subject, string $kind, string $value, mixed $reading, string $reason, array $settings, array $context, int $now): void
    {
        $state = $this->state($subject);
        $normal = $kind === 'battery' ? 'normal' : 'online';
        $state['reason'] = $reason;
        if (($state['pending'] ?? null) !== $value) { $state['pending'] = $value; $state['since'] = $now; }
        if ($value === 'offline' && $now - $state['since'] < $settings['offline_delay']) { $this->persist($subject, $state); return; }
        $previous = $state['current'] ?? null;
        if ($previous === null && $value === $normal) {
            $state['current'] = $value; $state['previous'] = null; $state['revision'] = 1;
            $state['say'] = $state['code'] = array('revision' => 1, 'time' => $now);
            $state['journal'] = 1;
            $state['reading'] = $reading;
            $this->persist($subject, $state); return; // first healthy state is silent
        }
        if ($previous !== $value) {
            $state['current'] = $value; $state['previous'] = $previous;
            $state['previous_reading'] = $state['reading'] ?? null;
            $state['changed_at'] = $now;
            $state['revision'] = ($state['revision'] ?? 0) + 1;
        }
        $revision = $state['revision'];
        $event = $kind === 'battery' ? array('normal' => 'battery_ok', 'low' => 'battery_low', 'critical' => 'battery_critical')[$value] : $value;
        $rule = $settings['events'][$event];
        $params = $context + array('EVENT' => $event, 'OLD_STATE' => $state['previous'], 'NEW_STATE' => $value,
            'VALUE' => $reading, 'OLD_VALUE' => $state['previous_reading'] ?? null, 'REASON' => $reason, 'TIME' => $now,
            'INCIDENT_ID' => $subject . ':' . $revision);
        $state['reading'] = $reading;
        if (($state['journal'] ?? null) !== $revision) {
            $this->repository->log($context['BRIDGE_ID'], $context['DEVICE_ID'] ?: null, 'monitor/' . $event,
                $context['DEVICE_TITLE'] . ': ' . HealthSettings::EVENTS[$event] . ($reading !== null ? ' (' . $reading . '%)' : ''), $value === $normal ? 'info' : 'warning');
            $state['journal'] = $revision;
        }
        $this->persist($subject, $state);
        foreach (array('say', 'code') as $channel) {
            $active = $rule['enabled'] && ($channel === 'say' ? $rule['say'] : trim($rule['code']) !== '');
            $ack = $state[$channel] ?? array();
            $repeat = $value !== $normal && $rule['repeat_hours'] > 0 && $now - ($ack['time'] ?? 0) >= $rule['repeat_hours'] * 3600;
            if (($ack['revision'] ?? null) === $revision && !$repeat) continue;
            if ($active) {
                $params['REPEAT'] = ($ack['revision'] ?? null) === $revision;
                try {
                    if ($channel === 'say') {
                        $message = strtr($rule['message'], array('{device}' => $context['DEVICE_TITLE'], '{room}' => $context['ROOM'],
                            '{value}' => $reading === null ? 'неизвестно' : (string)$reading, '{event}' => $event));
                        ($this->say)($message, $rule['level']);
                    } else { ($this->code)($rule['code'], $params); }
                } catch (Throwable $e) {
                    $this->repository->log($context['BRIDGE_ID'], $context['DEVICE_ID'] ?: null, 'monitor/' . $channel, $e->getMessage(), 'error');
                    continue; // only the failed channel retries on the next tick
                }
            }
            $state[$channel] = array('revision' => $revision, 'time' => $now);
            $this->persist($subject, $state);
        }
    }
}
