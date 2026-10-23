<?php

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Core;
use MajorDoMo\Core\LaneEventQueue;

/**
 * Быстрый путь MQTT: надёжный вход, состояние в Redis и отложенная проекция
 * в MySQL.
 *
 * Контракт:
 *  - входящее событие подтверждается только после обработки;
 *  - `mqtt.VALUE/UPDATED` — проекция, она может отставать до секунды;
 *  - `mqtt_history` получает отдельные записи для привязанных топиков,
 *    `LOGGING`/`DEBUG_MODE` и команд, а несвязанная телеметрия только
 *    обновляет последнее значение;
 *  - при недоступности Redis вызывающий код обязан вернуться к старому
 *    синхронному пути.
 *
 * 2026-10-23 — черновик переписан под полный быстрый путь и диагностику.
 */
final class MqttRuntime
{
    private const STAGE_STAGES = array('intake', 'claim', 'handle', 'property', 'publish', 'project');

    public static function enabled(): bool
    {
        return (bool)Config::get('USE_REDIS') && class_exists('Redis') && Config::bool('MQTT_ASYNC_ENABLED', true);
    }

    public static function handlers(): int
    {
        return max(1, min(16, Config::int('MQTT_HANDLERS', 3)));
    }

    public static function queue(string $name): LaneEventQueue
    {
        return new LaneEventQueue(Core::redis(), 'mqtt:' . $name, max(30, Config::int('MQTT_HANDLER_LEASE', 120)));
    }

    private static function key(string $suffix): string
    {
        return Core::redis()->key('mqtt:runtime:' . $suffix);
    }

    public static function version(): string
    {
        try {
            return (string)(Core::redis()->requireConnection()->get(self::key('bindings')) ?: '0');
        } catch (Throwable $error) {
            return '0';
        }
    }

    public static function invalidate(): void
    {
        if (!self::enabled()) return;
        try {
            Core::redis()->requireConnection()->incr(self::key('bindings'));
        } catch (Throwable $error) {
            self::report('Не удалось обновить версию привязок MQTT: ' . $error->getMessage());
        }
    }

    public static function state(string $path): ?array
    {
        try {
            $raw = Core::redis()->requireConnection()->hGet(self::key('state'), $path);
            if ($raw === false || $raw === null) return null;
            $row = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($row) ? $row : null;
        } catch (Throwable $error) {
            return null;
        }
    }

    public static function value(string $path, string $fallback = ''): string
    {
        $row = self::state($path);
        return $row === null ? $fallback : (string)($row['VALUE'] ?? '');
    }

    /** Запоминает последнее значение; снимок можно объединять. */
    public static function stage(array $record): void
    {
        $row = array(
            'ID' => (int)($record['ID'] ?? 0),
            'PATH' => (string)$record['PATH'],
            'VALUE' => mb_strcut((string)($record['VALUE'] ?? ''), 0, 1024, 'UTF-8'),
            'UPDATED' => (string)($record['UPDATED'] ?? date('Y-m-d H:i:s')),
        );
        $lua = "redis.call('HSET',KEYS[1],ARGV[1],ARGV[2]); redis.call('SADD',KEYS[2],ARGV[1]); return 1";
        $result = Core::redis()->requireConnection()->eval($lua, array(
            self::key('state'), self::key('dirty'), $row['PATH'],
            json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ), 2);
        if ($result === false) throw new RuntimeException('MQTT state was not staged');
    }

    public static function forget(string $path): void
    {
        $lua = "redis.call('HDEL',KEYS[1],ARGV[1]); redis.call('SREM',KEYS[2],ARGV[1]); return 1";
        Core::redis()->requireConnection()->eval($lua, array(self::key('state'), self::key('dirty'), $path), 2);
    }

    /**
     * Переносит накопленные значения в MySQL. Один UPDATE на топик за проход;
     * запись, пришедшая во время UPDATE, остаётся грязной и будет перенесена
     * следующим проходом.
     */
    public static function flushState(int $limit = 200): int
    {
        $redis = Core::redis()->requireConnection();
        $paths = $redis->sRandMember(self::key('dirty'), max(1, min(1000, $limit)));
        if (!is_array($paths) || !$paths) return 0;
        $existing = array();
        foreach (array_chunk($paths, 100) as $chunk) {
            $in = array();
            foreach ($chunk as $path) $in[] = "'" . DBSafe($path) . "'";
            $rows = SQLSelect('SELECT ID, PATH FROM mqtt WHERE PATH IN (' . implode(',', $in) . ')');
            foreach ($rows as $row) $existing[(string)$row['PATH']] = (int)$row['ID'];
        }
        $count = 0;
        $errors = array();
        foreach ($paths as $path) {
            try {
                $raw = $redis->hGet(self::key('state'), $path);
                if ($raw === false || $raw === null) {
                    $redis->sRem(self::key('dirty'), $path);
                    continue;
                }
                $row = json_decode((string)$raw, true);
                if (!is_array($row)) {
                    $redis->sRem(self::key('dirty'), $path);
                    continue;
                }
                $id = $existing[(string)$path] ?? 0;
                if ($id > 0) {
                    // Не перетираем более новую ручную правку из карточки MQTT:
                    // проекция обновляет строку только если она не новее снимка.
                    $value = DBSafe((string)$row['VALUE']);
                    $updated = DBSafe((string)$row['UPDATED']);
                    $sql = "UPDATE mqtt SET VALUE='" . $value . "',UPDATED='" . $updated
                        . "' WHERE ID=" . (int)$id . " AND (UPDATED IS NULL OR UPDATED<='" . $updated . "')";
                    if (SQLExec($sql) === false) throw new RuntimeException('Unable to persist MQTT state');
                } else {
                    $new = array('PATH' => (string)$path, 'TITLE' => (string)$path,
                        'VALUE' => (string)$row['VALUE'], 'UPDATED' => (string)$row['UPDATED']);
                    if (!SQLInsert('mqtt', $new)) throw new RuntimeException('Unable to discover MQTT topic');
                }
                // Удаляем из dirty только если значение не изменилось за время записи.
                $lua = "if redis.call('HGET',KEYS[1],ARGV[1]) == ARGV[2] then return redis.call('SREM',KEYS[2],ARGV[1]) end return 0";
                $redis->eval($lua, array(self::key('state'), self::key('dirty'), $path, $raw), 2);
                $count++;
            } catch (Throwable $error) {
                // Один проблемный топик не должен блокировать перенос остальных.
                $errors[] = $error;
            }
        }
        self::count('projected', $count);
        if ($errors) {
            self::count('project_errors', count($errors));
            self::report('MQTT projection errors: ' . count($errors) . ', first: ' . $errors[0]->getMessage());
            throw new RuntimeException('MQTT projection incomplete: ' . $errors[0]->getMessage());
        }
        return $count;
    }

    /** Ставит событие истории в очередь проекции. */
    public static function stageHistory(array $row): void
    {
        $value = (string)($row['VALUE'] ?? '');
        if (mb_strlen($value, 'UTF-8') > 255) $value = '[Data too large]';
        $event = array(
            'MQTT_ID' => (int)($row['MQTT_ID'] ?? 0),
            'DESTINATION' => (int)($row['DESTINATION'] ?? 0),
            'TOPIC' => mb_strcut((string)($row['TOPIC'] ?? ''), 0, 255, 'UTF-8'),
            'VALUE' => $value,
            'DATA_PAYLOAD' => (string)($row['DATA_PAYLOAD'] ?? ''),
            'LINKED_DATA' => mb_strcut((string)($row['LINKED_DATA'] ?? ''), 0, 255, 'UTF-8'),
            'UPDATED' => (string)($row['UPDATED'] ?? date('Y-m-d H:i:s')),
            'ATTEMPTS' => 0,
        );
        $result = Core::redis()->requireConnection()->rPush(self::key('history'),
            json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        if ($result === false) throw new RuntimeException('MQTT history event was not queued');
    }
    public static function flushHistory(int $limit = 200): int
    {
        $redis = Core::redis()->requireConnection();
        $count = 0;
        for ($i = 0; $i < max(1, $limit); $i++) {
            $raw = $redis->lPop(self::key('history'));
            if ($raw === false || $raw === null) break;
            $row = json_decode((string)$raw, true);
            if (!is_array($row)) continue;
            try {
                $id = (int)($row['MQTT_ID'] ?? 0);
                if ($id < 1) {
                    $rec = SQLSelectOne("SELECT ID FROM mqtt WHERE PATH='" . DBSafe((string)$row['TOPIC']) . "' LIMIT 1");
                    $id = (int)($rec['ID'] ?? 0);
                    if ($id < 1) {
                        $new = array('PATH' => (string)$row['TOPIC'], 'TITLE' => (string)$row['TOPIC'],
                            'VALUE' => (string)$row['VALUE'], 'UPDATED' => (string)$row['UPDATED']);
                        $id = (int)SQLInsert('mqtt', $new);
                        if ($id < 1) throw new RuntimeException('Unable to discover MQTT topic for history');
                    }
                }
                $hist = array(
                    'MQTT_ID' => $id,
                    'DESTINATION' => (int)$row['DESTINATION'],
                    'TOPIC' => (string)$row['TOPIC'],
                    'VALUE' => (string)$row['VALUE'],
                    'DATA_PAYLOAD' => (string)$row['DATA_PAYLOAD'],
                    'LINKED_DATA' => (string)$row['LINKED_DATA'],
                    'UPDATED' => (string)$row['UPDATED'],
                );
                if (!SQLInsert('mqtt_history', $hist)) throw new RuntimeException('Unable to save MQTT history');
                $count++;
            } catch (Throwable $error) {
                $row['ATTEMPTS'] = (int)($row['ATTEMPTS'] ?? 0) + 1;
                if ($row['ATTEMPTS'] >= 3) {
                    self::report('Не удалось сохранить историю MQTT для ' . $row['TOPIC'] . ': ' . $error->getMessage());
                    continue;
                }
                $redis->lPush(self::key('history'), json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
                self::count('history_retry');
                throw $error;
            }
        }
        self::count('history_saved', $count);
        return $count;
    }

    /**
     * Накладывает актуальные значения из Redis на строки, прочитанные из SQL.
     * Используется списком/карточкой MQTT, чтобы секундное отставание проекции
     * не было видно в интерфейсе.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function overlay(array $rows): array
    {
        if (!$rows || !self::enabled()) return $rows;
        $paths = array();
        foreach ($rows as $row) {
            $path = (string)($row['PATH'] ?? '');
            if ($path !== '') $paths[$path] = 1;
        }
        if (!$paths) return $rows;
        try {
            $values = Core::redis()->requireConnection()->hMGet(self::key('state'), array_keys($paths));
        } catch (Throwable $error) {
            return $rows;
        }
        if (!is_array($values)) return $rows;
        $index = 0;
        foreach ($paths as $path => $unused) {
            $raw = $values[$index++] ?? false;
            if ($raw === false || $raw === null) continue;
            $state = json_decode((string)$raw, true);
            if (!is_array($state)) continue;
            foreach ($rows as $key => $row) {
                if ((string)($row['PATH'] ?? '') !== $path) continue;
                $oldUpdated = (string)($row['UPDATED'] ?? '');
                $newUpdated = (string)($state['UPDATED'] ?? '');
                if ($oldUpdated === '' || $newUpdated >= $oldUpdated) {
                    $rows[$key]['VALUE'] = (string)($state['VALUE'] ?? '');
                    $rows[$key]['UPDATED'] = $newUpdated;
                }
            }
        }
        return $rows;
    }

    public static function count(string $name, int $by = 1): void
    {
        try {
            Core::redis()->requireConnection()->hIncrBy(self::key('metrics'), 'count:' . $name, $by);
        } catch (Throwable $error) {
            // Метрика не должна ломать обработку.
        }
    }

    /** Замер участка: последний, средний и p95 по последним 100 замерам. */
    public static function sample(string $stage, float $ms): void
    {
        if (!in_array($stage, self::STAGE_STAGES, true)) return;
        $ms = max(0.0, $ms);
        try {
            $redis = Core::redis()->requireConnection();
            $key = self::key('metrics');
            $redis->hIncrBy($key, 'samples:' . $stage . ':count', 1);
            $redis->hIncrBy($key, 'samples:' . $stage . ':sum', (int)round($ms * 1000));
            $redis->hSet($key, 'samples:' . $stage . ':last', (string)round($ms, 1));
            $list = self::key('samples:' . $stage);
            $redis->lPush($list, (string)round($ms, 2));
            $redis->lTrim($list, 0, 99);
            $redis->expire($list, 3600);
        } catch (Throwable $error) {
            // Диагностика не должна ломать обработку.
        }
    }

    public static function metrics(): array
    {
        $result = array();
        try {
            $redis = Core::redis()->requireConnection();
            $counters = $redis->hGetAll(self::key('metrics'));
            foreach (is_array($counters) ? $counters : array() as $field => $value) {
                if (str_starts_with((string)$field, 'count:')) {
                    $result[substr((string)$field, 6)] = (int)$value;
                }
            }
            foreach (self::STAGE_STAGES as $stage) {
                $count = (int)($counters['samples:' . $stage . ':count'] ?? 0);
                $sum = (int)($counters['samples:' . $stage . ':sum'] ?? 0);
                $samples = $redis->lRange(self::key('samples:' . $stage), 0, 99);
                $samples = array_map('floatval', is_array($samples) ? $samples : array());
                sort($samples);
                $p95 = $samples ? $samples[(int)floor((count($samples) - 1) * 0.95)] : 0.0;
                $result[$stage] = array(
                    'count' => $count,
                    'avg_ms' => $count > 0 ? round($sum / $count / 1000, 1) : 0.0,
                    'last_ms' => (float)($counters['samples:' . $stage . ':last'] ?? 0),
                    'p95_ms' => round($p95, 1),
                );
            }
        } catch (Throwable $error) {
        }
        return $result;
    }

    public static function status(): array
    {
        $result = array('enabled' => self::enabled(), 'bindings_version' => self::version());
        if (!self::enabled()) return $result;
        try {
            $incoming = self::queue('incoming');
            $result['incoming'] = array(
                'size' => $incoming->size(),
                'inflight' => $incoming->inflightCount(),
                'delayed' => $incoming->delayedCount(),
                'failed' => $incoming->failedCount(),
            );
            $result['dirty_topics'] = (int)Core::redis()->requireConnection()->sCard(self::key('dirty'));
            $result['history_pending'] = (int)Core::redis()->requireConnection()->lLen(self::key('history'));
            $result['handlers'] = self::handlers();
            $result['handlers_alive'] = self::handlersAlive();
            $result['publisher_alive'] = self::isAlive('publisher');
            $result['metrics'] = self::metrics();
        } catch (Throwable $error) {
            $result['error'] = $error->getMessage();
        }
        return $result;
    }

    public static function requireAvailable(): void
    {
        if (!self::enabled()) throw new RuntimeException('MQTT async runtime is disabled');
        Core::redis()->requireConnection()->ping();
    }

    /** Heartbeat роли (publisher/handler/state) для контроля зависаний. */
    public static function heartbeat(string $role): void
    {
        try {
            Core::redis()->requireConnection()->set(self::key('beat:' . $role), (string)time(), array('ex' => 30));
        } catch (Throwable $error) {
        }
    }

    public static function isAlive(string $role, int $ttl = 20): bool
    {
        try {
            $value = Core::redis()->requireConnection()->get(self::key('beat:' . $role));
            return $value !== false && $value !== null && time() - (int)$value <= max(1, $ttl);
        } catch (Throwable $error) {
            return false;
        }
    }

    public static function handlersAlive(): bool
    {
        for ($i = 1; $i <= self::handlers(); $i++) {
            if (self::isAlive('mqtt_handler_' . $i)) return true;
        }
        return false;
    }

    private static function report(string $message): void
    {
        static $last = array();
        $key = md5($message);
        if (($last[$key] ?? 0) > time() - 60) return;
        $last[$key] = time();
        if (function_exists('DebMes')) {
            DebMes($message, 'mqtt_runtime', 'warning');
        }
    }
}
