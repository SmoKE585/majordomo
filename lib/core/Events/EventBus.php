<?php

namespace MajorDoMo\Core\Events;

use InvalidArgumentException;
use MajorDoMo\Core\Core;
use MajorDoMo\Core\Database;
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
                $record = array(
                    'NAME' => $name,
                    'PAYLOAD' => $json,
                    'SOURCE' => $source,
                    'CREATED' => date('Y-m-d H:i:s'),
                    'PROCESSED' => 0,
                );
                $id = $database->insert('system_events', $record);
            } catch (Throwable $error) {
                self::log('Cannot write system event ' . $name . ': ' . $error->getMessage());
            }
        }

        self::mirror($name, $payload);
        self::dispatch($name, $payload, $source);

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
        if (!function_exists('processSubscriptions')) {
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
            $error = null;
            $audit = null;
            $auditOwned = false;
            if ($name !== '' && preg_match(self::NAME_PATTERN, $name) && class_exists(Core::class) && isset($GLOBALS['db'])) {
                try {
                    $audit = Core::audit();
                    $auditOwned = $audit->beginIfMissing('event', $name, $payload, array('source' => 'event:' . $name));
                } catch (Throwable $ignored) {
                    $audit = null;
                }
            }
            try {
                if ($name !== '' && preg_match(self::NAME_PATTERN, $name)) {
                    processSubscriptions($name, $payload);
                }
            } catch (Throwable $failure) {
                $error = substr($failure->getMessage(), 0, 1000);
                self::log('System event subscriber failed for ' . $name . ': ' . $error);
            } finally {
                if ($audit && $auditOwned) {
                    $audit->finishRun($error === null ? 'done' : 'error');
                    $audit->leave();
                }
            }
            try {
                $database->exec(
                    "UPDATE system_events SET PROCESSED=1, PROCESSED_AT=NOW(), ERROR=" .
                    ($error === null ? 'NULL' : "'" . $database->escape($error) . "'") .
                    ' WHERE ID=' . $id
                );
            } catch (Throwable $failure) {
                self::log('Cannot acknowledge system event ' . $id . ': ' . $failure->getMessage());
            }
            $processed++;
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
