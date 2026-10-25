<?php

namespace MajorDoMo\Core\Events;

use InvalidArgumentException;
use MajorDoMo\Core\Core;
use MajorDoMo\Core\Database;
use MajorDoMo\Core\RuntimeQueue;
use Throwable;

/**
 * Append-only system event log.
 *
 * Every event is written to `system_events` (history and delivery queue), mirrored
 * into the legacy `events` feed through `registerEvent()` (MQTT, property/method
 * bindings, admin feed) and delivered to in-process listeners right away. Module
 * subscribers (`subscribeToEvent()`) are drained from the queue by `cycle_core`,
 * so an emitter never blocks on a slow module and delivery survives a web request
 * that dies half-way.
 *
 * Names are uppercase with underscores (`SYSTEM_UPDATED`, `MODULE_REMOVED`) so the
 * same names work with the legacy hook bus (`SETTINGS_HOOK_EVENT_*` constants).
 * 2026-10-25 — передача подписок в долговечную очередь атомарна; cycle_core больше не выполняет код модулей.
 */
final class EventBus
{
    /** Wildcard listener: receives every event. */
    public const ANY = '*';

    private const MAX_NAME_LENGTH = 100;
    private const MAX_SOURCE_LENGTH = 100;
    private const NAME_PATTERN = '/^[A-Z][A-Z0-9_]{2,' . (self::MAX_NAME_LENGTH - 1) . '}$/D';

    /** @var array<string,array<int,array{priority:int,listener:callable}>> */
    private static array $listeners = array();

    private static ?Database $database = null;
    private static bool $databaseResolved = false;
    private static bool $mirrorDisabled = false;

    /** For tests and long-running tools: drop the resolved database. */
    public static function reset(): void
    {
        self::$listeners = array();
        self::$database = null;
        self::$databaseResolved = false;
        self::$mirrorDisabled = false;
    }

    public static function useDatabase(?Database $database): void
    {
        self::$database = $database;
        self::$databaseResolved = true;
    }

    /** Stops mirroring into the legacy `events` table (used by tests). */
    public static function disableMirroring(): void
    {
        self::$mirrorDisabled = true;
    }

    public static function on(string $name, callable $listener, int $priority = 0): void
    {
        $name = trim($name) === self::ANY ? self::ANY : self::normalizeName($name);
        self::$listeners[$name][] = array('priority' => $priority, 'listener' => $listener);
    }

    /**
     * Records and dispatches one system event.
     *
     * @param array<string,mixed> $payload
     * @return int event id (0 when the log is unavailable)
     */
    public static function emit(string $name, array $payload = array(), string $source = 'core'): int
    {
        $name = self::normalizeName($name);
        $source = trim($source) === '' ? 'core' : substr(trim($source), 0, self::MAX_SOURCE_LENGTH);
        $json = self::encodePayload($payload);

        $id = 0;
        $database = self::database();
        if ($database !== null) {
            try {
                $id = self::store($database, $name, $json, $source, date('Y-m-d H:i:s'));
            } catch (Throwable $error) {
                self::log('Cannot write system event ' . $name . ': ' . $error->getMessage());
            }
        }

        self::mirror($name, $payload);
        self::dispatch($name, $payload, $source);

        return $id;
    }

    /** Durable early-boot outbox; no DB, modules or listeners run in the caller. */
    public static function defer(string $root, string $name, array $payload, string $source = 'core'): void
    {
        $event = array('name' => self::normalizeName($name), 'payload' => $payload,
            'source' => substr($source, 0, self::MAX_SOURCE_LENGTH), 'created' => date('Y-m-d H:i:s'));
        $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $directory = rtrim($root, '/\\') . '/var/events';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create system event outbox');
        $target = $directory . '/' . hash('sha256', $json) . '.json';
        if (is_file($target)) return;
        $temporary = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $target)) throw new \RuntimeException('Cannot publish deferred system event');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    /** Called from an authenticated admin boot or cycle_core after runtime boot. */
    public static function flushDeferred(string $root, int $limit = 20): int
    {
        $directory = rtrim($root, '/\\') . '/var/events';
        if (!is_dir($directory) || $limit < 1) return 0;
        $database = self::database();
        if ($database === null) return 0;
        $processed = 0; $seen = 0;
        foreach (new \DirectoryIterator($directory) as $file) {
            if (!$file->isFile() || !preg_match('/^[a-f0-9]{64}\.json$/D', $file->getFilename())) continue;
            if (++$seen > $limit) break;
            $path = $file->getPathname();
            // Another consumer may remove the entry between readdir and fopen.
            $handle = self::openDeferred($path);
            if ($handle === null) continue;
            $acknowledged = false;
            try {
                if (!flock($handle, LOCK_EX | LOCK_NB)) continue;
                $raw = stream_get_contents($handle, 1048577);
                if ($raw === '') { $acknowledged = true; continue; }
                if ($raw === false || strlen($raw) > 1048576) throw new \RuntimeException('Invalid deferred system event');
                $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                $name = self::normalizeName((string)($event['name'] ?? ''));
                if (!is_array($event['payload'] ?? null)) throw new \RuntimeException('Invalid deferred event payload');
                $payload = $event['payload']; $json = self::encodePayload($payload);
                $source = substr((string)($event['source'] ?? 'core'), 0, self::MAX_SOURCE_LENGTH);
                $created = (string)($event['created'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $created)) throw new \RuntimeException('Invalid deferred event date');
                // A crash after the DB insert must not append a duplicate journal row.
                $existing = $database->one("SELECT ID FROM system_events WHERE NAME='" . $database->escape($name)
                    . "' AND SOURCE='" . $database->escape($source) . "' AND CREATED='" . $database->escape($created)
                    . "' AND PAYLOAD='" . $database->escape($json) . "' LIMIT 1");
                if ($existing === null) self::store($database, $name, $json, $source, $created);
                self::mirror($name, $payload);
                self::dispatch($name, $payload, $source);
                if (!ftruncate($handle, 0)) throw new \RuntimeException('Cannot acknowledge deferred event');
                $acknowledged = true;
                $processed++;
            } catch (Throwable $error) {
                self::log('Cannot flush deferred system event: ' . get_class($error));
            } finally {
                fclose($handle);
                if ($acknowledged && is_file($path)) unlink($path);
            }
        }
        return $processed;
    }

    private static function openDeferred(string $path): mixed
    {
        set_error_handler(static function (int $severity, string $message): bool {
            if (!str_contains($message, 'No such file') && !str_contains($message, 'not find the file')) throw new \RuntimeException('Cannot open deferred system event');
            return true;
        });
        try { $handle = fopen($path, 'r+'); }
        finally { restore_error_handler(); }
        return $handle === false ? null : $handle;
    }

    private static function store(Database $database, string $name, string $json, string $source, string $created): int
    {
        $record = array('NAME' => $name, 'PAYLOAD' => $json, 'SOURCE' => $source, 'CREATED' => $created, 'PROCESSED' => 0);
        $id = $database->insert('system_events', $record);
        if ($id < 1) throw new \RuntimeException('System event was not saved');
        return $id;
    }

    /**
     * Delivers queued events to module subscribers.
     *
     * @return int number of processed events
     */
    public static function drain(int $limit = 50): int
    {
        $database = self::database();
        if ($database === null || $limit < 1) {
            return 0;
        }
        try {
            $rows = $database->select('SELECT ID, NAME, PAYLOAD FROM system_events WHERE PROCESSED=0 ORDER BY ID LIMIT ' . (int)$limit);
        } catch (Throwable $error) {
            return 0;
        }
        $processed = 0;
        foreach ($rows as $row) {
            $id = (int)($row['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = (string)($row['NAME'] ?? '');
            $payload = json_decode((string)($row['PAYLOAD'] ?? ''), true);
            if (!is_array($payload)) {
                $payload = array();
            }
            $unit = null;
            $queued = false;
            try {
                $unit = $database->beginUnit();
                $database->requireExec('UPDATE system_events SET PROCESSED=1,PROCESSED_AT=NOW(),ERROR=NULL WHERE ID=' . $id . ' AND PROCESSED=0');
                if ($database->affectedRows() === 1) {
                    $data = json_encode(array('target' => $name, 'path' => 'core:subscriptions/' . $name,
                        'params' => array('event' => $name, 'details' => $payload)), JSON_THROW_ON_ERROR);
                    (new RuntimeQueue($database))->enqueue('subscriptions', $data, time() + 3600, 'event:' . $id);
                    $queued = true;
                }
                $database->commitUnit($unit);
                $unit = null;
                if ($queued) $processed++;
            } catch (Throwable $failure) {
                if ($unit !== null && $database->hasUnit($unit)) $database->rollbackUnit($unit);
                self::log('Cannot enqueue system event ' . $id . ': ' . $failure->getMessage());
            }
        }
        return $processed;
    }

    /**
     * Highest recorded event id, or 0 when the log is unavailable.
     *
     * @param string[] $names only count these event names
     */
    public static function lastId(array $names = array()): int
    {
        $database = self::database();
        if ($database === null) {
            return 0;
        }
        $where = '';
        if ($names !== array()) {
            $quoted = array();
            foreach ($names as $name) {
                $name = strtoupper(trim((string)$name));
                if (preg_match(self::NAME_PATTERN, $name)) {
                    $quoted[] = "'" . $database->escape($name) . "'";
                }
            }
            if ($quoted !== array()) {
                $where = ' WHERE NAME IN (' . implode(',', $quoted) . ')';
            }
        }
        try {
            $row = $database->one('SELECT MAX(ID) AS ID FROM system_events' . $where);
            return (int)(is_array($row) ? ($row['ID'] ?? 0) : 0);
        } catch (Throwable $error) {
            return 0;
        }
    }

    /**
     * @return array<int,array{id:int,name:string,payload:array,source:string,created:string}>
     */
    public static function since(int $afterId, int $limit = 100): array
    {
        $database = self::database();
        if ($database === null || $afterId < 0) {
            return array();
        }
        try {
            $rows = $database->select('SELECT ID, NAME, PAYLOAD, SOURCE, CREATED FROM system_events WHERE ID>' . (int)$afterId . ' ORDER BY ID LIMIT ' . max(1, $limit));
        } catch (Throwable $error) {
            return array();
        }
        $events = array();
        foreach ($rows as $row) {
            $payload = json_decode((string)($row['PAYLOAD'] ?? ''), true);
            $events[] = array(
                'id' => (int)($row['ID'] ?? 0),
                'name' => (string)($row['NAME'] ?? ''),
                'payload' => is_array($payload) ? $payload : array(),
                'source' => (string)($row['SOURCE'] ?? ''),
                'created' => (string)($row['CREATED'] ?? ''),
            );
        }
        return $events;
    }

    /** Removes history older than the retention window. */
    public static function prune(int $days = 90, int $keepMinimum = 1000): int
    {
        $database = self::database();
        if ($database === null || $days < 1) {
            return 0;
        }
        $days = min(3650, $days);
        try {
            $row = $database->one('SELECT MAX(ID) AS ID FROM system_events');
            $minimumId = (int)(is_array($row) ? ($row['ID'] ?? 0) : 0) - max(0, $keepMinimum);
            $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
            $database->exec("DELETE FROM system_events WHERE CREATED<'" . $database->escape($cutoff) . "' AND ID<=" . max(0, $minimumId));
            return max(0, $database->affectedRows());
        } catch (Throwable $error) {
            return 0;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private static function dispatch(string $name, array $payload, string $source): array
    {
        $handled = array();
        $listeners = array_merge(self::$listeners[$name] ?? array(), self::$listeners[self::ANY] ?? array());
        usort($listeners, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);
        foreach ($listeners as $entry) {
            try {
                ($entry['listener'])($name, $payload, $source);
                $handled[] = $name;
            } catch (Throwable $error) {
                self::log('System event listener failed for ' . $name . ': ' . $error->getMessage());
            }
        }
        return $handled;
    }

    /** Mirrors the event into the legacy feed without letting it break the emitter. */
    private static function mirror(string $name, array $payload): void
    {
        if (self::$mirrorDisabled || !function_exists('registerEvent')) {
            return;
        }
        // The legacy feed stores params as varchar(255): arrays are flattened,
        // nested values stay JSON so the feed never prints "Array".
        $flat = array();
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $flat[$key] = implode(', ', array_map(static fn($item): string => self::flattenValue($item), $value));
            } else {
                $flat[$key] = self::flattenValue($value);
            }
        }
        try {
            registerEvent($name, $flat);
        } catch (Throwable $error) {
            self::log('Cannot mirror system event ' . $name . ': ' . $error->getMessage());
        }
    }

    private static function flattenValue($value): string
    {
        if (is_scalar($value) || $value === null) {
            return substr((string)$value, 0, 240);
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return substr(is_string($json) ? $json : '', 0, 240);
    }

    private static function normalizeName(string $name): string
    {
        $name = strtoupper(trim($name));
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidArgumentException('Invalid system event name: ' . $name);
        }
        return $name;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function encodePayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) ? $json : '{}';
    }

    private static function database(): ?Database
    {
        if (self::$databaseResolved) {
            return self::$database;
        }
        self::$databaseResolved = true;
        if (!isset($GLOBALS['db'])) {
            return null;
        }
        try {
            self::$database = Core::database();
        } catch (Throwable $error) {
            self::$database = null;
        }
        return self::$database;
    }

    private static function log(string $message): void
    {
        if (function_exists('DebMes')) {
            try {
                DebMes($message, 'system_events', 'warning');
                return;
            } catch (Throwable $error) {
                // Fall through to the PHP log.
            }
        }
        error_log($message);
    }
}
