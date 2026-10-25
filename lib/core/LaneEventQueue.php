<?php

namespace MajorDoMo\Core;

use \RuntimeException;

/**
 * Надёжная очередь событий с независимыми полосами (lane).
 *
 * Зачем нужна отдельная очередь, а не общий ZSET:
 *  - события одной управляемой сущности обязаны выполняться строго по порядку;
 *  - события разных сущностей можно обрабатывать параллельно;
 *  - произвольный PHP с неизвестными зависимостями помещается в полосу
 *    `serial` и не пересекается ни с одной другой полосой.
 *
 * Гарантии:
 *  - событие не теряется: оно лежит в списке полосы до подтверждения;
 *  - после сбоя обработчика аренда истекает, событие возвращается в голову
 *    своей полосы (reap), порядок сохраняется;
 *  - подтверждение проверяет токен аренды, поэтому запоздавший ACK не удалит
 *    событие, которое уже перехватил другой процесс;
 *  - счётчик `count` отражает неподтверждённые события, включая отложенные.
 *
 * Ограничение: порядок гарантируется только внутри полосы. Между разными
 * полосами порядок намеренно не определён.
 *
 * 2026-10-25 — повторы сохраняют голову полосы; serial чередуется с объектами и не блокирует их во время задержки.
 * 2026-10-25 — перенос старой очереди ядра в SQL при остановленных исполнителях.
 * 2026-10-23 — добавлена для быстрого пути MQTT и параллельного core_runtime.
 */
final class LaneEventQueue
{
    private string $base;

    public function __construct(
        private RedisConnection $redis,
        string $name,
        private int $leaseSeconds = 120
    ) {
        $this->base = $redis->key('lanes:' . $name . ':');
    }

    private function readyKey(): string { return $this->base . 'ready'; }
    private function inflightKey(): string { return $this->base . 'inflight'; }
    private function countKey(): string { return $this->base . 'count'; }
    private function activeKey(): string { return $this->base . 'active'; }
    private function serialKey(): string { return $this->base . 'serial'; }
    private function delayedKey(): string { return $this->base . 'delayed'; }
    private function delayedDataKey(): string { return $this->base . 'delayed:data'; }
    private function serialPendingKey(): string { return $this->base . 'serial:pending'; }
    private function lanePrefix(): string { return $this->base . 'lane:'; }
    private function lockPrefix(): string { return $this->base . 'lock:'; }
    private function failedKey(): string { return $this->base . 'failed'; }

    /**
     * Ставит событие в полосу. Событие считается принятым только после
     * успешного ответа Redis; при ошибке выбрасывается исключение.
     */
    public function push(array $data, string $lane = 'default', int $ttl = 3600, ?string $id = null, ?int $expires = null): string
    {
        $lane = trim($lane);
        if ($lane === '') $lane = 'default';
        $id ??= bin2hex(random_bytes(16));
        $event = array(
            'id' => $id,
            'at' => microtime(true),
            'lane' => $lane,
            'attempts' => 0,
            'expires' => $expires ?? ($ttl > 0 ? time() + $ttl : 0),
            'data' => $data,
        );
        $raw = json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $lua = <<<'LUA'
local event = cjson.decode(ARGV[3])
if redis.call('HSETNX', KEYS[4], event.id, '1') == 0 then return 0 end
redis.call('RPUSH', ARGV[1], ARGV[3])
redis.call('RPUSH', KEYS[1], ARGV[2])
redis.call('INCR', KEYS[2])
if ARGV[2] == 'serial' then redis.call('INCR', KEYS[3]) end
return 1
LUA;
        $result = $this->redis->requireConnection()->eval($lua, array(
            $this->readyKey(), $this->countKey(), $this->serialPendingKey(), $this->base . 'seen', $this->laneKey($lane), $lane, $raw,
        ), 4);
        if ($result === false) throw new RuntimeException('Event was not queued');
        return $id;
    }

    /**
     * Забирает до $limit событий из разных свободных полос.
     *
     * @return array<int,array<string,mixed>> События с QUEUE_ID, CLAIM_TOKEN, LANE.
     */
    public function claim(int $limit = 1, ?int $leaseSeconds = null): array
    {
        $limit = max(1, min(100, $limit));
        $lease = max(1, $leaseSeconds ?? $this->leaseSeconds);
        // Reaper должен ждать ровно столько же, сколько живёт аренда claim.
        $this->leaseSeconds = $lease;
        $leaseMs = $lease * 1000;
        $token = bin2hex(random_bytes(16));
        $now = (int)round(microtime(true) * 1000);
        $lua = <<<'LUA'
local out = {}
local now, lease = tonumber(ARGV[6]), tonumber(ARGV[7])
local serialKey, turnKey = ARGV[8], ARGV[9]
-- Старые отложенные события ещё не лежали в голове полосы. Возвращаем их
-- туда один раз при обновлении, прежде чем разрешить последующим обгонять их.
if redis.call('SET', ARGV[10], '2', 'NX') then
    local lanes = {}
    for _,raw in ipairs(redis.call('HVALS', KEYS[7])) do
        local ok,event = pcall(cjson.decode, raw)
        if ok and type(event) == 'table' and not event.not_before then
            event.not_before = tonumber(redis.call('ZSCORE', KEYS[6], event.id) or now)
            local encoded = cjson.encode(event)
            redis.call('HSET', KEYS[7], event.id, encoded)
            lanes[event.lane] = lanes[event.lane] or {}
            table.insert(lanes[event.lane], event)
        end
    end
    for lane,events in pairs(lanes) do
        table.sort(events, function(a,b) return tonumber(a.at or 0) < tonumber(b.at or 0) end)
        for i=#events,1,-1 do redis.call('LPUSH', ARGV[4] .. redis.sha1hex(lane), cjson.encode(events[i])) end
        redis.call('RPUSH', KEYS[1], lane)
    end
    -- Блокировка живёт до ACK/reap: истечение TTL не разрешает A2 обогнать
    -- зависший A1 между истечением аренды и следующим проходом супервизора.
    for _,raw in ipairs(redis.call('HVALS', KEYS[4])) do
        local ok,event = pcall(cjson.decode, raw)
        if ok and type(event) == 'table' then
            local key = event.lane == 'serial' and serialKey or ARGV[3] .. redis.sha1hex(event.lane)
            if redis.call('GET', key) == event.token then redis.call('PERSIST', key)
            else redis.call('SET', key, event.token, 'NX') end
        end
    end
end
local function due(lane)
    local raw = redis.call('LINDEX', ARGV[4] .. redis.sha1hex(lane), 0)
    if not raw then return false end
    local ok,event = pcall(cjson.decode, raw)
    if not ok or type(event) ~= 'table' then return true end
    local expires = tonumber(event.expires or 0)
    return (expires > 0 and expires < math.floor(now / 1000)) or tonumber(event.not_before or 0) <= now
end
local serialReady = due('serial')
local preferObjects = redis.call('GET', turnKey) == 'object'
local function take(lane, claimToken)
    if not due(lane) then return false end
    local listKey = ARGV[4] .. redis.sha1hex(lane)
    local lockKey = lane == 'serial' and serialKey or ARGV[3] .. redis.sha1hex(lane)
    if lane == 'serial' then
        if redis.call('HLEN', KEYS[4]) > 0 then return false end
    elseif redis.call('EXISTS', serialKey) == 1 or (serialReady and not preferObjects) then return false end
    if not redis.call('SET', lockKey, claimToken, 'NX') then return false end
    local raw = redis.call('LPOP', listKey)
    local ok,event = pcall(cjson.decode, raw)
    if not ok or type(event) ~= 'table' or (tonumber(event.expires or 0) > 0 and tonumber(event.expires) < math.floor(now / 1000)) then
        redis.call('DEL', lockKey)
        redis.call('DECR', KEYS[2])
        if lane == 'serial' then redis.call('DECR', KEYS[5]) end
        if ok and type(event) == 'table' and event.id then
            redis.call('HDEL', ARGV[11], event.id)
            redis.call('ZREM', KEYS[6], event.id)
            redis.call('HDEL', KEYS[7], event.id)
        end
        if redis.call('LLEN', listKey) > 0 then redis.call('RPUSH', KEYS[1], lane) end
        return false
    end
    event.attempts = (tonumber(event.attempts) or 0) + 1
    event.token, event.started, event.lane, event.lease_ms = claimToken, now, lane, lease
    event.not_before = nil
    redis.call('ZREM', KEYS[6], event.id)
    redis.call('HDEL', KEYS[7], event.id)
    local encoded = cjson.encode(event)
    redis.call('HSET', KEYS[4], event.id, encoded)
    if lane ~= 'serial' then redis.call('ZADD', KEYS[3], now + lease, lane) end
    redis.call('SET', turnKey, lane == 'serial' and 'object' or 'serial')
    preferObjects = false
    table.insert(out, encoded)
    return true
end
local sawSerial = false
for i=1,tonumber(ARGV[1]) do
    local lane = redis.call('LPOP', KEYS[1])
    if not lane then break end
    local taken = false
    if lane == 'serial' and preferObjects then sawSerial = true
    else taken = take(lane, ARGV[5] .. ':' .. i) end
    if not taken and redis.call('LLEN', ARGV[4] .. redis.sha1hex(lane)) > 0 then redis.call('RPUSH', KEYS[1], lane) end
    if #out >= tonumber(ARGV[2]) then break end
end
-- Если объектов, готовых к выполнению, нет, serial не должен ждать пустую фазу.
if #out == 0 and sawSerial then
    preferObjects = false
    if take('serial', ARGV[5] .. ':serial') then redis.call('LREM', KEYS[1], 1, 'serial') end
end
return out
LUA;
        // KEYS: ready, count, active, inflight, serialPending.
        $rows = $this->redis->requireConnection()->eval($lua, array(
            $this->readyKey(), $this->countKey(), $this->activeKey(), $this->inflightKey(), $this->serialPendingKey(),
            $this->delayedKey(), $this->delayedDataKey(),
            '20', (string)$limit, $this->lockPrefix(), $this->lanePrefix(),
            $token, (string)$now, (string)$leaseMs, $this->serialKey(), $this->base . 'turn', $this->base . 'layout', $this->base . 'seen',
        ), 7);
        $events = array();
        if ($rows === false) throw new RuntimeException('Lane queue claim failed');
        foreach (is_array($rows) ? $rows : array() as $raw) {
            $event = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
            $events[] = $this->present($event);
        }
        return $events;
    }

    /**
     * Возвращает зарезервированное событие по id и токену. Нужен дочернему
     * процессу, который получил только id/token от пула.
     */
    public function claimed(string $id, string $token): array
    {
        $raw = $this->redis->requireConnection()->hGet($this->inflightKey(), $id);
        if ($raw === false || $raw === null) throw new RuntimeException('Event reservation expired');
        $event = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($event) || !hash_equals((string)($event['token'] ?? ''), $token)) {
            throw new RuntimeException('Event reservation expired');
        }
        return $this->present($event);
    }

    /** Подтверждает успешную обработку. Возвращает false, если аренда уже истекла. */
    public function acknowledge(array $event): bool    {
        $lua = <<<'LUA'
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw then return 0 end
local ok, value = pcall(cjson.decode, raw)
if not ok or type(value) ~= 'table' or value.token ~= ARGV[2] then return 0 end
redis.call('HDEL', KEYS[1], ARGV[1])
redis.call('ZREM', KEYS[4], value.lane)
if value.lane == 'serial' then
    if redis.call('GET', ARGV[5]) == ARGV[2] then redis.call('DEL', ARGV[5]) end
    redis.call('DECR', KEYS[5])
else
    local lockKey = ARGV[3] .. redis.sha1hex(value.lane)
    if redis.call('GET', lockKey) == ARGV[2] then redis.call('DEL', lockKey) end
end
redis.call('DECR', KEYS[2])
if redis.call('LLEN', ARGV[4] .. redis.sha1hex(value.lane)) > 0 then
    redis.call('RPUSH', KEYS[3], value.lane)
end
redis.call('HDEL', ARGV[6], ARGV[1])
return 1
LUA;
        return (bool)$this->redis->requireConnection()->eval($lua, array(
            $this->inflightKey(), $this->countKey(), $this->readyKey(), $this->activeKey(), $this->serialPendingKey(),
            (string)($event['QUEUE_ID'] ?? ''), (string)($event['CLAIM_TOKEN'] ?? ''),
            $this->lockPrefix(), $this->lanePrefix(), $this->serialKey(), $this->base . 'seen',
        ), 5);
    }

    /**
     * Возвращает событие в очередь. $delay > 0 откладывает повтор, чтобы
     * временная ошибка MySQL/брокера не превратилась в busy-loop.
     */
    public function release(array $event, int $delaySeconds = 0): bool
    {
        $now = (int)round(microtime(true) * 1000);
        $lua = <<<'LUA'
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw then return 0 end
local ok, value = pcall(cjson.decode, raw)
if not ok or type(value) ~= 'table' or value.token ~= ARGV[2] then return 0 end
redis.call('HDEL', KEYS[1], ARGV[1])
redis.call('ZREM', KEYS[3], value.lane)
if value.lane == 'serial' then
    if redis.call('GET', ARGV[5]) == ARGV[2] then redis.call('DEL', ARGV[5]) end
else
    local lockKey = ARGV[3] .. redis.sha1hex(value.lane)
    if redis.call('GET', lockKey) == ARGV[2] then redis.call('DEL', lockKey) end
end
value.not_before = tonumber(ARGV[7]) + tonumber(ARGV[6])
raw = cjson.encode(value)
redis.call('LPUSH', ARGV[4] .. redis.sha1hex(value.lane), raw)
redis.call('RPUSH', KEYS[2], value.lane)
if tonumber(ARGV[6]) > 0 then
    redis.call('ZADD', KEYS[4], tonumber(ARGV[7]) + tonumber(ARGV[6]), value.id)
    redis.call('HSET', KEYS[5], value.id, raw)
end
return 1
LUA;
        // KEYS: inflight, ready, active, delayed, delayedData.
        return (bool)$this->redis->requireConnection()->eval($lua, array(
            $this->inflightKey(), $this->readyKey(), $this->activeKey(),
            $this->delayedKey(), $this->delayedDataKey(),
            (string)($event['QUEUE_ID'] ?? ''), (string)($event['CLAIM_TOKEN'] ?? ''),
            $this->lockPrefix(), $this->lanePrefix(), $this->serialKey(),
            (string)(max(0, $delaySeconds) * 1000), (string)$now,
        ), 5);
    }

    /**
     * Возвращает зависшие события в их полосы. Вызывается супервизором
     * периодически; не зависит от того, жив ли обработчик.
     */
    public function reap(int $limit = 200, ?int $leaseSeconds = null): int
    {
        $limit = max(1, min(2000, $limit));
        $lease = max(1, $leaseSeconds ?? $this->leaseSeconds);
        $redis = $this->redis->requireConnection();
        $now = (int)round(microtime(true) * 1000);
        $lua = <<<'LUA'
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw then return 0 end
local ok, value = pcall(cjson.decode, raw)
if not ok or type(value) ~= 'table' then
    redis.call('HDEL', KEYS[1], ARGV[1])
    return 0
end
if (tonumber(value.started) or 0) + tonumber(value.lease_ms or ARGV[3]) > tonumber(ARGV[2]) then return 0 end
redis.call('HDEL', KEYS[1], ARGV[1])
redis.call('ZREM', KEYS[2], value.lane)
if value.lane == 'serial' then
    if redis.call('GET', ARGV[4]) == value.token then redis.call('DEL', ARGV[4]) end
else
    local lockKey = ARGV[5] .. redis.sha1hex(value.lane)
    if redis.call('GET', lockKey) == value.token then redis.call('DEL', lockKey) end
end
redis.call('LPUSH', ARGV[6] .. redis.sha1hex(value.lane), raw)
redis.call('RPUSH', KEYS[3], value.lane)
return 1
LUA;
        $reaped = 0;
        $inflight = $redis->hKeys($this->inflightKey());
        foreach (is_array($inflight) ? $inflight : array() as $id) {
            $reaped += (int)$redis->eval($lua, array(
                $this->inflightKey(), $this->activeKey(), $this->readyKey(),
                (string)$id, (string)$now, (string)($lease * 1000),
                $this->serialKey(), $this->lockPrefix(), $this->lanePrefix(),
            ), 3);
            if ($reaped >= $limit) break;
        }
        return $reaped;
    }

    /** Поднимает отложенные события, срок которых наступил. */
    public function promoteDelayed(int $limit = 200): int
    {
        $limit = max(1, min(2000, $limit));
        $redis = $this->redis->requireConnection();
        $nowMs = (string)round(microtime(true) * 1000);
        $ids = $redis->zRangeByScore($this->delayedKey(), '-inf', $nowMs, array('limit' => array(0, $limit)));
        if (!is_array($ids)) return 0;
        $lua = <<<'LUA'
local score = tonumber(redis.call('ZSCORE', KEYS[1], ARGV[1]) or '-1')
if score < 0 or score > tonumber(ARGV[3]) then return 0 end
local raw = redis.call('HGET', KEYS[2], ARGV[1])
redis.call('ZREM', KEYS[1], ARGV[1])
redis.call('HDEL', KEYS[2], ARGV[1])
if not raw then return 0 end
local ok, value = pcall(cjson.decode, raw)
if not ok or type(value) ~= 'table' then return 0 end
if not value.not_before then redis.call('LPUSH', ARGV[2] .. redis.sha1hex(value.lane), raw) end
redis.call('RPUSH', KEYS[3], value.lane)
return 1
LUA;
        $moved = 0;
        foreach ($ids as $id) {
            $moved += (int)$redis->eval($lua, array(
                $this->delayedKey(), $this->delayedDataKey(), $this->readyKey(),
                (string)$id, $this->lanePrefix(), $nowMs,
            ), 3);
        }
        return $moved;
    }

    /** Переносит событие в журнал неудачных после исчерпания повторов. */
    public function fail(array $event, string $error): void
    {
        $error = mb_substr($error, 0, 4096, 'UTF-8');
        $record = json_encode(array(
            'event' => $event,
            'error' => $error,
            'at' => date('c'),
        ), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $lua = <<<'LUA'
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw then return 0 end
local ok, value = pcall(cjson.decode, raw)
if not ok or type(value) ~= 'table' or value.token ~= ARGV[2] then return 0 end
redis.call('HDEL', KEYS[1], ARGV[1])
redis.call('ZREM', KEYS[3], value.lane)
if value.lane == 'serial' then
    if redis.call('GET', ARGV[5]) == ARGV[2] then redis.call('DEL', ARGV[5]) end
    redis.call('DECR', KEYS[5])
else
    local lockKey = ARGV[3] .. redis.sha1hex(value.lane)
    if redis.call('GET', lockKey) == ARGV[2] then redis.call('DEL', lockKey) end
end
redis.call('DECR', KEYS[2])
redis.call('RPUSH', KEYS[4], ARGV[4])
redis.call('LTRIM', KEYS[4], -1000, -1)
redis.call('HDEL', ARGV[6], ARGV[1])
return 1
LUA;
        $this->redis->requireConnection()->eval($lua, array(
            $this->inflightKey(), $this->countKey(), $this->activeKey(), $this->failedKey(), $this->serialPendingKey(),
            (string)($event['QUEUE_ID'] ?? ''), (string)($event['CLAIM_TOKEN'] ?? ''),
            $this->lockPrefix(), (string)$record, $this->serialKey(), $this->base . 'seen',
        ), 5);
    }

    public function size(): int
    {
        return (int)$this->redis->requireConnection()->get($this->countKey());
    }

    public function inflightCount(): int
    {
        return (int)$this->redis->requireConnection()->hLen($this->inflightKey());
    }

    public function readyCount(): int
    {
        return (int)$this->redis->requireConnection()->lLen($this->readyKey());
    }

    public function delayedCount(): int
    {
        return (int)$this->redis->requireConnection()->zCard($this->delayedKey());
    }

    public function failedCount(): int
    {
        return (int)$this->redis->requireConnection()->lLen($this->failedKey());
    }

    /** @return array<int,array<string,mixed>> Последние неудачные события без удаления. */
    public function failed(int $limit = 50): array
    {
        $limit = max(1, min(1000, $limit));
        $rows = $this->redis->requireConnection()->lRange($this->failedKey(), -$limit, -1);
        $rows = array_reverse(is_array($rows) ? $rows : array());
        $result = array();
        foreach ($rows as $raw) {
            $row = json_decode((string)$raw, true);
            if (is_array($row)) $result[] = $row;
        }
        return $result;
    }

    private function laneKey(string $lane): string
    {
        return $this->lanePrefix() . sha1($lane);
    }

    /** Только миграция остановленной очереди: сначала сохранить SQL, затем убрать Redis. */
    public function migrateTo(callable $store): int
    {
        $redis = $this->redis->requireConnection();
        $count = 0;
        // Прерванное задание должно остаться впереди головы своей полосы.
        foreach (array($this->inflightKey(), $this->delayedDataKey()) as $key) {
            foreach ($redis->hKeys($key) as $id) {
                $event = json_decode((string)$redis->hGet($key, $id), true, 512, JSON_THROW_ON_ERROR);
                $store($event);
                $count++;
            }
        }
        $cursor = null;
        do {
            $keys = $redis->scan($cursor, $this->lanePrefix() . '*', 200);
            foreach (is_array($keys) ? $keys : array() as $key) {
                $offset = 0;
                do {
                    $rows = $redis->lRange($key, $offset, $offset + 199);
                    foreach ($rows as $raw) { $store(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)); $count++; }
                    $offset += count($rows);
                } while (count($rows) === 200);
            }
        } while ($cursor !== 0);
        // Прежний журнал отказов сохраняется для диагностики; рабочие ключи
        // очищаем только после успешного переноса всего снимка.
        $cursor = null;
        do {
            $keys = $redis->scan($cursor, $this->base . '*', 200);
            foreach (is_array($keys) ? $keys : array() as $key) if ($key !== $this->failedKey()) $redis->del($key);
        } while ($cursor !== 0);
        return $count;
    }

    private function present(array $event): array
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : array();
        $data['QUEUE_ID'] = (string)($event['id'] ?? '');
        $data['CLAIM_TOKEN'] = (string)($event['token'] ?? '');
        $data['LANE'] = (string)($event['lane'] ?? '');
        $data['ATTEMPTS'] = (int)($event['attempts'] ?? 1);
        $data['QUEUED_AT'] = (float)($event['at'] ?? 0);
        return $data;
    }
}
