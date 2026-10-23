<?php

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Core;

/**
 * Индекс привязок MQTT для обработчиков без SQL на каждое сообщение.
 *
 * Таблица `mqtt` хранит и настройку привязки, и последнее значение. На больших
 * установках читать её целиком каждые 15 секунд (как делал старый lookup)
 * дорого, а искать по `LINKED_OBJECT` без индекса — ещё дороже. Поэтому:
 *  - снимок привязок строится одним запросом и живёт в Redis по версии;
 *  - редактор привязки увеличивает версию, процессы перечитывают снимок без
 *    переподключения к брокеру;
 *  - точный путь, префиксы и пары «объект.свойство» индексируются в памяти.
 *
 * 2026-10-23 — добавлен для быстрого пути MQTT.
 */
final class MqttBindingIndex
{
    private const SNAPSHOT_TTL = 3600;
    private const LOCAL_TTL = 30;

    /** @var array<string,array<string,mixed>> */
    private array $byPath = array();
    /** @var array<string,int> */
    private array $writePaths = array();
    /** @var array<string,int> */
    private array $linkedPaths = array();
    /** @var array<string,int> */
    private array $linkedPrefixes = array();
    /** @var array<string,string> */
    private array $prefixObject = array();
    /** @var array<string,array<int,array<string,mixed>>> */
    private array $propertyMap = array();

    private string $version = '';
    private float $loadedAt = 0.0;
    private float $checkedAt = 0.0;
    private bool $failed = false;

    public function __construct(private \MajorDoMo\Core\RedisConnection $redis)
    {
    }

    public static function enabled(): bool
    {
        return (bool)Config::get('USE_REDIS') && class_exists('Redis') && Config::bool('MQTT_ASYNC_ENABLED', true);
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * Перечитывает снимок, если версия в Redis изменилась. Проверка версии
     * делается не чаще двух раз в секунду, чтобы не добавлять round-trip на
     * каждое сообщение.
     */
    public function ensureFresh(float $maxAgeSeconds = 0.5): void
    {
        $now = microtime(true);
        if ($this->loadedAt > 0 && $now - $this->checkedAt < $maxAgeSeconds) return;
        $this->checkedAt = $now;
        try {
            $version = $this->remoteVersion();
            if ($this->loadedAt > 0 && $version === $this->version && $now - $this->loadedAt < self::LOCAL_TTL) return;
            $this->load($version);
            $this->failed = false;
        } catch (Throwable $error) {
            // Redis недоступен: работаем по последнему снимку, а если его нет —
            // строим локально. Вызывающий код сам решает, можно ли продолжать.
            if ($this->loadedAt > 0) return;
            try {
                $this->hydrate($this->compact(''));
                $this->version = '';
                $this->loadedAt = microtime(true);
                $this->failed = false;
            } catch (Throwable $ignored) {
                $this->failed = true;
            }
        }
    }

    /** Готов ли снимок к обработке (есть данные или таблица действительно пуста). */
    public function ready(): bool
    {
        return $this->loadedAt > 0 && !$this->failed;
    }

    public function load(?string $version = null): void
    {
        $version = $version ?? $this->remoteVersion();
        if ($this->loadedAt > 0 && $version === $this->version) {
            $this->loadedAt = microtime(true);
            return;
        }
        $snapshot = $this->readSnapshot($version);
        if ($snapshot === null) {
            $snapshot = $this->buildSnapshot($version);
        }
        $this->hydrate($snapshot);
        $this->version = $version;
        $this->loadedAt = microtime(true);
        $this->failed = false;
    }

    public function record(string $path): array
    {
        return $this->byPath[$path] ?? array();
    }

    public function isWritePath(string $path): bool
    {
        return isset($this->writePaths[$path]);
    }

    public function isLinked(string $path): bool
    {
        return isset($this->linkedPaths[$path]);
    }

    public function hasLinkedPrefix(string $path): bool
    {
        return $path !== '' && isset($this->linkedPrefixes[$path]);
    }

    public function isLinkedOrPrefix(string $path): bool
    {
        return $this->isLinked($path) || $this->hasLinkedPrefix($path);
    }

    /**
     * Полоса обработки. Всё, что относится к одному объекту, идёт в одну
     * полосу; JSON-пакет с привязками к разным объектам — в полосу топика,
     * чтобы не потерять порядок листьев.
     */
    public function laneFor(string $topic): string
    {
        $record = $this->byPath[$topic] ?? null;
        if (is_array($record) && (string)($record['LINKED_OBJECT'] ?? '') !== '') {
            return 'object:' . $record['LINKED_OBJECT'];
        }
        $object = $this->prefixObject[$topic] ?? '';
        if ($object !== '' && $object !== '*') return 'object:' . $object;
        return $topic !== '' ? 'topic:' . $topic : 'serial';
    }

    /** @return array<int,array<string,mixed>> */
    public function propertyRecords(string $object, string $property): array
    {
        $key = $object . "\0" . $property;
        return $this->propertyMap[$key] ?? array();
    }

    public function invalidate(): void
    {
        try {
            $this->redis->requireConnection()->incr($this->redis->key('mqtt:runtime:bindings'));
            $this->checkedAt = 0;
            $this->loadedAt = 0;
        } catch (Throwable $error) {
            if (function_exists('DebMes')) {
                DebMes('MQTT binding invalidation failed: ' . $error->getMessage(), 'mqtt', 'warning');
            }
        }
    }

    public function remoteVersion(): string
    {
        $bindings = $this->redis->requireConnection()->get($this->redis->key('mqtt:runtime:bindings'));
        $bindings = $bindings === false || $bindings === null ? '0' : (string)$bindings;
        // Переименование свойства обновляет mqtt.LINKED_PROPERTY в ядре, не зная
        // о модуле MQTT. Эпоха метаданных ловит такое изменение без отдельного
        // хука: снимок перечитывается, даже если версия привязок не менялась.
        $epoch = $this->redis->requireConnection()->get($this->redis->key('meta:epoch'));
        $epoch = $epoch === false || $epoch === null ? '0' : (string)$epoch;
        return $bindings . ':' . $epoch;
    }

    private function readSnapshot(string $version): ?array
    {
        try {
            $raw = $this->redis->requireConnection()->get($this->snapshotKey($version));
            if ($raw === false || $raw === null || $raw === '') return null;
            $json = function_exists('gzuncompress') ? @gzuncompress((string)$raw) : (string)$raw;
            if ($json === false || $json === '') return null;
            $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($snapshot) ? $snapshot : null;
        } catch (Throwable $error) {
            return null;
        }
    }

    private function buildSnapshot(string $version): array
    {
        // Один строитель на версию: остальные процессы ждут готовый снимок,
        // а не читают всю таблицу одновременно.
        $lockKey = $this->redis->key('mqtt:bindings:lock:' . $version);
        $locked = false;
        try {
            $locked = (bool)$this->redis->requireConnection()->set($lockKey, (string)getmypid(), array('nx', 'ex' => 10));
            if (!$locked) {
                for ($i = 0; $i < 10; $i++) {
                    usleep(50000);
                    $snapshot = $this->readSnapshot($version);
                    if ($snapshot !== null) return $snapshot;
                }
            }
        } catch (Throwable $error) {
            $locked = false;
        }
        $snapshot = $this->compact($version);
        if ($locked) {
            try {
                $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if (function_exists('gzcompress')) $json = gzcompress($json, 6);
                $this->redis->requireConnection()->set($this->snapshotKey($version), $json, array('ex' => self::SNAPSHOT_TTL));
            } catch (Throwable $error) {
                if (function_exists('DebMes')) {
                    DebMes('MQTT binding snapshot was not cached: ' . $error->getMessage(), 'mqtt', 'warning');
                }
            } finally {
                try { $this->redis->requireConnection()->del($lockKey); } catch (Throwable $ignored) {}
            }
        }
        return $snapshot;
    }

    private function compact(string $version): array
    {
        $rows = SQLSelect('SELECT ID,PATH,TITLE,PATH_WRITE,LINKED_OBJECT,LINKED_PROPERTY,LINKED_METHOD,'
            . 'LOGGING,ONLY_NEW_VALUE,REPLACE_LIST,READONLY,QOS,RETAIN,WRITE_TYPE,'
            . "IF(ONLY_NEW_VALUE=1,VALUE,'') AS VALUE FROM mqtt");
        $records = array();
        foreach ($rows as $row) {
            $path = (string)($row['PATH'] ?? '');
            if ($path === '') continue;
            $record = array(
                'ID' => (int)($row['ID'] ?? 0),
                'PATH' => $path,
                'TITLE' => (string)($row['TITLE'] ?? ''),
                'PATH_WRITE' => (string)($row['PATH_WRITE'] ?? ''),
                'LINKED_OBJECT' => (string)($row['LINKED_OBJECT'] ?? ''),
                'LINKED_PROPERTY' => (string)($row['LINKED_PROPERTY'] ?? ''),
                'LINKED_METHOD' => (string)($row['LINKED_METHOD'] ?? ''),
                'LOGGING' => (int)($row['LOGGING'] ?? 0),
                'ONLY_NEW_VALUE' => (int)($row['ONLY_NEW_VALUE'] ?? 0),
                'REPLACE_LIST' => (string)($row['REPLACE_LIST'] ?? ''),
                'READONLY' => (int)($row['READONLY'] ?? 0),
                'QOS' => (int)($row['QOS'] ?? 0),
                'RETAIN' => (int)($row['RETAIN'] ?? 0),
                'WRITE_TYPE' => (int)($row['WRITE_TYPE'] ?? 0),
                // Значение нужно только для ONLY_NEW_VALUE; остальным его
                // хранит состояние в Redis.
                'VALUE' => (int)($row['ONLY_NEW_VALUE'] ?? 0) ? (string)($row['VALUE'] ?? '') : '',
            );
            $records[] = $record;
        }
        return array('version' => $version, 'records' => $records, 'built' => time());
    }

    private function hydrate(array $snapshot): void
    {
        $this->byPath = array();
        $this->writePaths = array();
        $this->linkedPaths = array();
        $this->linkedPrefixes = array();
        $this->prefixObject = array();
        $this->propertyMap = array();
        foreach (($snapshot['records'] ?? array()) as $record) {
            if (!is_array($record)) continue;
            $path = (string)($record['PATH'] ?? '');
            if ($path === '') continue;
            $this->byPath[$path] = $record;
            if ((string)($record['PATH_WRITE'] ?? '') !== '') {
                $this->writePaths[$record['PATH_WRITE']] = 1;
            }
            $object = (string)($record['LINKED_OBJECT'] ?? '');
            $property = (string)($record['LINKED_PROPERTY'] ?? '');
            $method = (string)($record['LINKED_METHOD'] ?? '');
            if ($object !== '' && ($property !== '' || $method !== '')) {
                $this->linkedPaths[$path] = 1;
                foreach ($this->ancestors($path) as $prefix) {
                    $this->linkedPrefixes[$prefix] = 1;
                    if (!isset($this->prefixObject[$prefix])) {
                        $this->prefixObject[$prefix] = $object;
                    } elseif ($this->prefixObject[$prefix] !== $object) {
                        $this->prefixObject[$prefix] = '*';
                    }
                }
                if ($property !== '') {
                    $this->propertyMap[$object . "\0" . $property][] = $record;
                }
            }
        }
    }

    /** @return array<int,string> Путь и все его родительские префиксы. */
    private function ancestors(string $path): array
    {
        // Ведущий слэш — часть топика: /var/now и var/now разные пути.
        $path = rtrim($path, '/');
        if ($path === '') return array();
        $leading = str_starts_with($path, '/');
        $body = ltrim($path, '/');
        if ($body === '') return array();
        $parts = explode('/', $body);
        $result = array();
        $current = '';
        foreach ($parts as $part) {
            $current = $current === '' ? $part : $current . '/' . $part;
            $result[] = ($leading ? '/' : '') . $current;
        }
        return $result;
    }

    private function snapshotKey(string $version): string
    {
        return $this->redis->key('mqtt:bindings:' . $version);
    }
}
