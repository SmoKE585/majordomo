<?php

namespace MajorDoMo\Core;

use \RuntimeException;

/**
 * Персистентная очередь и постановка внутренних фоновых операций.
 * Изменения:
 * 2026-10-25 — MQTT сохраняет порядок команд топика при повторе; последовательный core не пропускает старое задание.
 * 2026-10-25 — область NO_LINKED передаётся и без модуля-источника (прямой вход MQTT).
 * 2026-10-22 — вместе с цепочкой передаётся точка доставки: вложенный вызов
 *              знает, какой модуль и по какому свойству его запустил.
 * 2026-10-22 — внутренние операции ставятся без HTTP и API.
 */
final class OperationsQueue
{
    public function __construct(
        private Database $db,
        private RedisConnection $redis,
        private ?LaneEventQueue $lanes = null
    ) {}

    /**
     * Параллельный исполнитель включается только при наличии Redis и
     * CORE_WORKERS > 1. Иначе остаётся прежний последовательный путь.
     */
    public function parallelEnabled(): bool
    {
        return $this->lanes !== null && Config::int('CORE_WORKERS', 3) > 1
            && (bool)Config::get('USE_REDIS') && class_exists('Redis');
    }

    public function lanes(): ?LaneEventQueue
    {
        return $this->lanes;
    }

    /**
     * Полоса операции. Метод и уведомление модуля о свойстве относятся к
     * объекту; сценарии, API, подписки и события модулей — произвольный PHP,
     * поэтому идут в эксклюзивную полосу serial.
     */
    private function laneFor(string $operation, array $params, ?string $target): string
    {
        $explicit = (string)($params['_lane'] ?? '');
        if ($explicit !== '') return mb_substr($explicit, 0, 160, 'UTF-8');
        if ($operation === 'method') {
            $object = explode('.', (string)$target, 2)[0];
            if ($object !== '') return 'object:' . mb_substr($object, 0, 150, 'UTF-8');
        }
        if ($operation === 'modulepropertyset') {
            $object = (string)($params['object'] ?? '');
            if ($object !== '') return 'object:' . mb_substr($object, 0, 150, 'UTF-8');
        }
        return 'serial';
    }

    private function laneForItem(array $item): string
    {
        $payload = json_decode((string)($item['DATAVALUE'] ?? ''), true);
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : array();
        return $this->laneFor((string)($item['DATANAME'] ?? ''), $params, $payload['target'] ?? null);
    }

    public function enqueueRuntime(string $operation, array $params, ?string $target = null): bool
    {
        PropertyAudit::attach($params);
        if (!isset($params['_chain']) && PropertyChain::current()) $params['_chain'] = PropertyChain::current();
        if (!isset($params['_delivery']) && (PropertyChain::source() !== '' || PropertyChain::exclusions())) $params['_delivery'] = PropertyChain::delivery();
        $target ??= (string)($params['event'] ?? $operation);
        $payload = array('target' => $target, 'path' => 'core:' . $operation . '/' . $target, 'params' => $params);
        return $this->enqueueRuntimePayload($operation, $payload);
    }

    /**
     * Постановка уже собранного payload (совместимость с внутренним API).
     * Позволяет не терять полосы там, где раньше вызывался enqueue() напрямую.
     */
    public function enqueueRuntimePayload(string $operation, array $payload): bool
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if ($this->parallelEnabled()) {
            $params = is_array($payload['params'] ?? null) ? $payload['params'] : array();
            $this->lanes->push(array(
                'TOPIC' => 'core_runtime',
                'DATANAME' => $operation,
                'DATAVALUE' => $json,
            ), $this->laneFor($operation, $params, $payload['target'] ?? null), 3600);
            return true;
        }
        return (bool)$this->enqueue('core_runtime', $operation, $json, false, 3600);
    }

    /** @return array<int,array<string,mixed>> */
    public function claimRuntime(int $limit = 1, int $lease = 300): array
    {
        if ($this->parallelEnabled()) return $this->lanes->claim($limit, $lease);
        return $this->claim('core_runtime', $limit, $lease);
    }

    public function acknowledgeRuntime(array $item): bool
    {
        if ($this->parallelEnabled() && isset($item['LANE'])) return $this->lanes->acknowledge($item);
        return (bool)$this->acknowledge($item);
    }

    public function releaseRuntime(array $item, int $delay = 5): bool
    {
        if ($this->parallelEnabled() && isset($item['LANE'])) return $this->lanes->release($item, $delay);
        return (bool)$this->release($item, $delay);
    }

    public function failRuntime(array $item, string $error): void
    {
        if ($this->parallelEnabled() && isset($item['LANE'])) {
            $this->lanes->fail($item, $error);
            return;
        }
        $this->acknowledge($item);
    }

    public function claimedRuntime(string $topic, string $id, string $token): array
    {
        if ($this->parallelEnabled()) return $this->lanes->claimed($id, $token);
        return $this->claimed($topic, $id, $token);
    }

    /**
     * Переносит элементы старого ZSET core_runtime в полосы. Нужен при
     * обновлении, когда в очереди уже лежат операции прежней версии.
     */
    public function migrateLegacyRuntime(int $limit = 200): int
    {
        if (!$this->parallelEnabled()) return 0;
        $moved = 0;
        for ($i = 0; $i < $limit; $i++) {
            $items = $this->claim('core_runtime', 1, 60);
            if (!$items) break;
            $item = $items[0];
            try {
                $this->lanes->push(array(
                    'TOPIC' => 'core_runtime',
                    'DATANAME' => $item['DATANAME'],
                    'DATAVALUE' => (string)$item['DATAVALUE'],
                ), $this->laneForItem($item), 3600);
                $this->acknowledge($item);
                $moved++;
            } catch (\Throwable $error) {
                $this->release($item, 5);
                throw $error;
            }
        }
        return $moved;
    }

    public function keys($topic)
    {
        $base = $this->redis->key('queue:v2:') . hash('sha256', (string)$topic) . ':';
        return array($base . 'ready', $base . 'processing', $base . 'payload', $base . 'unique', $base . 'sequence', $base . 'receipts');
    }

    private function orderKey(string $topic): string
    {
        return $this->redis->key('queue:v2:') . hash('sha256', $topic) . ':order';
    }

    public function enqueue($topic, $name, $value = '', $unique = false, $ttl = 60)
    {
        $expires = time() + max(0, (int)$ttl);
        if (Config::get('USE_REDIS')) {
            $payload = json_encode(array('TOPIC' => (string)$topic, 'DATANAME' => (string)$name, 'DATAVALUE' => (string)$value, 'EXPIRES' => $expires, 'UNIQUE' => (bool)$unique, 'NAME_KEY' => $this->redis->key('queue:v2:') . hash('sha256', (string)$topic) . ':names:' . sha1((string)$name)), JSON_THROW_ON_ERROR);
        $lua = <<<'LUA'
local id = string.format('%020d', redis.call('INCR',KEYS[5]))
if ARGV[3] == '1' then
    for _,old in ipairs(redis.call('SMEMBERS',KEYS[7])) do
        if redis.call('ZREM',KEYS[1],old) == 1 then
            redis.call('HDEL',KEYS[3],old)
            redis.call('SREM',KEYS[7],old)
            redis.call('ZREM',KEYS[8],old)
        end
    end
    redis.call('HSET',KEYS[4],ARGV[4],id)
end
redis.call('SADD',KEYS[7],id)
redis.call('HSET',KEYS[3],id,ARGV[1])
redis.call('ZADD',KEYS[1],ARGV[2],id)
if ARGV[5] == '1' then redis.call('ZADD',KEYS[8],0,id) end
return id
LUA;
            $id = $this->redis->requireConnection()->eval($lua, array_merge($this->keys($topic), array($this->redis->key('queue:v2:') . hash('sha256', (string)$topic) . ':names:' . sha1((string)$name), $this->orderKey((string)$topic)), array($payload, microtime(true), $unique ? '1' : '0', (string)$name, $topic === 'core_runtime' ? '1' : '0')), 8);
            if ($id === false) throw new RuntimeException('Operation was not queued');
            return $id;
        }
        $lock = 'mjd:queue:' . substr(hash('sha256', (string)$topic), 0, 40);
        $acquired = $this->db->one("SELECT GET_LOCK('" . $lock . "',5) AS OK");
        if ((int)($acquired['OK'] ?? 0) !== 1) throw new RuntimeException('Cannot lock operation queue');
        try {
            if ($unique) $this->db->exec("DELETE FROM operations_queue WHERE TOPIC='" . $this->db->escape($topic) . "' AND DATANAME='" . $this->db->escape($name) . "' AND CLAIM_TOKEN=''");
            $record = array('TOPIC' => $topic, 'DATANAME' => $name, 'DATAVALUE' => $value, 'EXPIRE' => date('Y-m-d H:i:s', $expires));
            $id = $this->db->insert('operations_queue', $record);
            if (!$id) throw new RuntimeException('Operation was not queued');
            return $id;
        } finally {
            $this->db->one("SELECT RELEASE_LOCK('" . $lock . "')");
        }
    }

    public function claim($topic, $limit = 100, $lease = 300)
    {
        $limit = max(1, min(1000, (int)$limit));
        $lease = max(5, (int)$lease);
        $token = bin2hex(random_bytes(16));
        if (Config::get('USE_REDIS')) {
        $lua = <<<'LUA'
local now, limit = tonumber(ARGV[1]), tonumber(ARGV[2])
local mode = ARGV[6]
local function forget(id, data)
    redis.call('ZREM',KEYS[1],id)
    redis.call('ZREM',KEYS[2],id)
    redis.call('HDEL',KEYS[3],id)
    redis.call('HDEL',KEYS[6],id)
    redis.call('ZREM',KEYS[8],id)
    if data and data.NAME_KEY then redis.call('SREM',data.NAME_KEY,id) end
end
if mode == 'global' and redis.call('SET',KEYS[8] .. ':layout','1','NX') then
    for _,id in ipairs(redis.call('HKEYS',KEYS[3])) do redis.call('ZADD',KEYS[8],0,id) end
end
-- Import the old list atomically during an upgrade; old producers may still be exiting.
for n=1,limit do
    local raw = redis.call('LPOP',KEYS[7])
    if not raw then break end
    local ok, data = pcall(cjson.decode,raw)
    if not ok or type(data) ~= 'table' or not data.DATANAME then
        local split = string.find(raw,'|',1,true)
        data = {DATANAME=split and string.sub(raw,1,split-1) or raw,DATAVALUE=split and string.sub(raw,split+1) or ''}
    end
    data.TOPIC, data.EXPIRES = ARGV[5], now + 60
    data.NAME_KEY = data.NAME_KEY or string.sub(KEYS[8],1,#KEYS[8]-5) .. 'names:' .. redis.sha1hex(data.DATANAME)
    local id = string.format('%020d',redis.call('INCR',KEYS[5]))
    redis.call('SADD',data.NAME_KEY,id)
    redis.call('HSET',KEYS[3],id,cjson.encode(data))
    redis.call('ZADD',KEYS[1],now,id)
    if mode == 'global' then redis.call('ZADD',KEYS[8],0,id) end
end
for _,id in ipairs(redis.call('ZRANGEBYSCORE',KEYS[2],'-inf',now,'LIMIT',0,limit)) do
    redis.call('ZREM',KEYS[2],id)
    redis.call('HDEL',KEYS[6],id)
    redis.call('ZADD',KEYS[1],now,id)
end
local result = {}
local scanLimit = mode == 'name' and math.max(20,limit * 10) or limit
local candidates = redis.call('ZRANGEBYSCORE',KEYS[1],'-inf',now,'LIMIT',0,scanLimit)
if mode == 'global' then
    candidates = {}
    while true do
        local head = redis.call('ZRANGE',KEYS[8],0,0)[1]
        if not head then break end
        local previous = redis.call('HGET',KEYS[3],head)
        local old = previous and cjson.decode(previous) or nil
        if old and tonumber(old.EXPIRES or 0) > now then
            local score = redis.call('ZSCORE',KEYS[1],head)
            if score and tonumber(score) <= now then candidates = {head} end
            break
        end
        forget(head,old)
    end
end
for _,id in ipairs(candidates) do
    local raw = redis.call('HGET',KEYS[3],id)
    if raw then
        local data = cjson.decode(raw)
        local newest = redis.call('HGET',KEYS[4],data.DATANAME)
        if tonumber(data.EXPIRES) > now and (not data.UNIQUE or not newest or newest == id) then
            local blocked = false
            if mode == 'global' then
                while true do
                    local head = redis.call('ZRANGE',KEYS[8],0,0)[1]
                    if not head or head == id then break end
                    local previous = redis.call('HGET',KEYS[3],head)
                    local old = previous and cjson.decode(previous) or nil
                    if old and tonumber(old.EXPIRES or 0) > now then blocked = true; break end
                    forget(head,old)
                end
            elseif mode == 'name' and data.NAME_KEY then
                for _,previousId in ipairs(redis.call('SMEMBERS',data.NAME_KEY)) do
                    if previousId < id then
                        local previous = redis.call('HGET',KEYS[3],previousId)
                        local old = previous and cjson.decode(previous) or nil
                        if old and tonumber(old.EXPIRES or 0) > now then blocked = true; break end
                        forget(previousId,old)
                    end
                end
            end
            if not blocked then
            redis.call('ZREM',KEYS[1],id)
            data.ATTEMPTS = (data.ATTEMPTS or 0) + 1
            raw = cjson.encode(data)
            redis.call('HSET',KEYS[3],id,raw)
            redis.call('ZADD',KEYS[2],now+tonumber(ARGV[3]),id)
            redis.call('HSET',KEYS[6],id,ARGV[4])
            table.insert(result,id)
            table.insert(result,raw)
            if #result >= limit * 2 then break end
            end
        else
            forget(id,data)
            if data.NAME_KEY then
                redis.call('SREM',data.NAME_KEY,id)
                if redis.call('SCARD',data.NAME_KEY) == 0 then redis.call('DEL',data.NAME_KEY) end
            end
            if newest == id then redis.call('HDEL',KEYS[4],data.DATANAME) end
        end
    else
        forget(id,nil)
    end
end
return result
LUA;
            $keys = array_merge($this->keys($topic), array('mjd:queue:' . $topic, $this->orderKey((string)$topic)));
            $mode = $topic === 'core_runtime' ? 'global' : ($topic === 'mqtt_queue' ? 'name' : '');
            $rows = $this->redis->requireConnection()->eval($lua, array_merge($keys, array(microtime(true), $limit, $lease, $token, (string)$topic, $mode)), 8);
            $result = array();
            for ($i = 0; $i < count($rows); $i += 2) {
                $row = json_decode($rows[$i + 1], true, 512, JSON_THROW_ON_ERROR);
                $row['QUEUE_ID'] = $rows[$i];
                $row['CLAIM_TOKEN'] = $token;
                $result[] = $row;
            }
            return $result;
        }
        $now = date('Y-m-d H:i:s');
        $this->db->exec("DELETE FROM operations_queue WHERE EXPIRE<='" . $now . "' LIMIT " . $limit);
        $scope = "TOPIC='" . $this->db->escape($topic) . "' AND EXPIRE>'" . $now . "' AND (CLAIM_TOKEN='' OR CLAIM_UNTIL<='" . $now . "')";
        if (in_array($topic, array('core_runtime', 'mqtt_queue'), true)) {
            $sameName = $topic === 'mqtt_queue' ? ' AND older.DATANAME=q.DATANAME' : '';
            $candidates = $this->db->select("SELECT q.ID FROM operations_queue q WHERE q.TOPIC='" . $this->db->escape($topic)
                . "' AND q.EXPIRE>'" . $now . "' AND (q.CLAIM_TOKEN='' OR q.CLAIM_UNTIL<='" . $now . "')"
                . " AND NOT EXISTS (SELECT 1 FROM operations_queue older WHERE older.TOPIC=q.TOPIC" . $sameName
                . " AND older.ID<q.ID AND older.EXPIRE>'" . $now . "') ORDER BY q.ID LIMIT " . $limit);
            if (!$candidates) return array();
            $scope .= ' AND ID IN (' . implode(',', array_map(static fn($row) => (int)$row['ID'], $candidates)) . ')';
            $tail = '';
        } else $tail = ' ORDER BY ID LIMIT ' . $limit;
        $this->db->exec("UPDATE operations_queue SET ATTEMPTS=ATTEMPTS+1,CLAIM_TOKEN='" . $token . "',CLAIM_UNTIL='" . date('Y-m-d H:i:s', time() + $lease) . "' WHERE " . $scope . $tail);
        return $this->db->select("SELECT *,ID AS QUEUE_ID FROM operations_queue WHERE CLAIM_TOKEN='" . $token . "' ORDER BY ID");
    }

    public function acknowledge($item)
    {
        if (Config::get('USE_REDIS')) {
        $lua = <<<'LUA'
if redis.call('HGET',KEYS[6],ARGV[1]) ~= ARGV[2] then return 0 end
local raw = redis.call('HGET',KEYS[3],ARGV[1])
if raw then
    local data = cjson.decode(raw)
    if data.NAME_KEY then
        redis.call('SREM',data.NAME_KEY,ARGV[1])
        if redis.call('SCARD',data.NAME_KEY) == 0 then redis.call('DEL',data.NAME_KEY) end
    end
end
redis.call('ZREM',KEYS[2],ARGV[1])
redis.call('HDEL',KEYS[3],ARGV[1])
redis.call('HDEL',KEYS[6],ARGV[1])
redis.call('ZREM',KEYS[7],ARGV[1])
if redis.call('HGET',KEYS[4],ARGV[3]) == ARGV[1] then redis.call('HDEL',KEYS[4],ARGV[3]) end
return 1
LUA;
            return $this->redis->requireConnection()->eval($lua, array_merge($this->keys($item['TOPIC']), array($this->orderKey((string)$item['TOPIC'])), array($item['QUEUE_ID'], $item['CLAIM_TOKEN'], $item['DATANAME'])), 7);
        }
        return $this->db->exec("DELETE FROM operations_queue WHERE ID=" . (int)$item['QUEUE_ID'] . " AND CLAIM_TOKEN='" . $this->db->escape($item['CLAIM_TOKEN']) . "'");
    }

    public function release($item, $delay = 5)
    {
        if (Config::get('USE_REDIS')) {
        $lua = <<<'LUA'
if redis.call('HGET',KEYS[6],ARGV[1]) ~= ARGV[2] then return 0 end
redis.call('ZREM',KEYS[2],ARGV[1])
redis.call('HDEL',KEYS[6],ARGV[1])
redis.call('ZADD',KEYS[1],ARGV[3],ARGV[1])
return 1
LUA;
            return $this->redis->requireConnection()->eval($lua, array_merge($this->keys($item['TOPIC']), array($item['QUEUE_ID'], $item['CLAIM_TOKEN'], microtime(true) + max(0, (int)$delay))), 6);
        }
        return $this->db->exec("UPDATE operations_queue SET CLAIM_UNTIL='" . date('Y-m-d H:i:s', time() + max(0, (int)$delay)) . "' WHERE ID=" . (int)$item['QUEUE_ID'] . " AND CLAIM_TOKEN='" . $this->db->escape($item['CLAIM_TOKEN']) . "'");
    }

    public function claimed($topic, $id, $token)
    {
        if (Config::get('USE_REDIS')) {
            $keys = $this->keys($topic);
            $redis = $this->redis->requireConnection();
            $owner = $redis->hGet($keys[5], $id);
            if ($owner === false || !hash_equals($owner, (string)$token)) throw new RuntimeException('Operation claim expired');
            $raw = $redis->hGet($keys[2], $id);
            if ($raw === false) throw new RuntimeException('Operation is no longer queued');
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        $row = $this->db->one("SELECT * FROM operations_queue WHERE ID=" . (int)$id . " AND TOPIC='" . $this->db->escape($topic) . "' AND CLAIM_TOKEN='" . $this->db->escape($token) . "' AND CLAIM_UNTIL>NOW()");
        if (!$row) throw new RuntimeException('Operation claim expired');
        return $row;
    }
}
