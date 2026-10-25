<?php

namespace MajorDoMo\Core;


/**
 * Метаданные по версии Redis с небольшим кешем внутри исполнителя.
 * 2026-10-25 — повторный доступ не читает то же описание из Redis; версия проверяется каждый раз.
 */
final class MetadataCache
{
    private array $local = array();
    private ?string $localEpoch = null;
    public function __construct(private Database $db, private RedisConnection $redis) {}

    public function get($key, $load)
    {
        if (!Config::get('USE_REDIS')) return $load();
        $redis = $this->redis->requireConnection();
        $epoch = (string)($redis->get($this->redis->key('meta:epoch')) ?: '0');
        if ($this->localEpoch !== $epoch) {
            $this->local = array();
            $this->localEpoch = $epoch;
        }
        $cache_key = $this->redis->key('meta:') . $epoch . ':' . strtolower($key);
        if (isset($this->local[$cache_key]) && $this->local[$cache_key]['expires'] > microtime(true)) {
            return $this->local[$cache_key]['value'];
        }
        $encoded = $redis->get($cache_key);
        if ($encoded !== false) {
            $value = json_decode($encoded, true);
            if (is_array($value)) { $this->remember($cache_key, $value); return $value; }
        }
        $value = $load();
        if (is_array($value) && $value) {
            $redis->set($cache_key, json_encode($value, JSON_THROW_ON_ERROR), 300);
            $this->remember($cache_key, $value);
        }
        return $value;
    }

    private function remember(string $key, array $value): void
    {
        if (!isset($this->local[$key]) && count($this->local) >= 512) array_shift($this->local);
        $this->local[$key] = array('value' => $value, 'expires' => microtime(true) + 300);
    }

    public function invalidate()
    {
        $this->local = array();
        $this->localEpoch = null;
        unset($GLOBALS['class_properties_cached'], $GLOBALS['cached_class_properties'], $GLOBALS['class_templates_cached'], $GLOBALS['all_objects_cached']);
        if (Config::get('USE_REDIS')) $this->redis->requireConnection()->incr($this->redis->key('meta:epoch'));
    }

    public function changedBySql($query)
    {
        if (!preg_match('/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|ALTER\s+TABLE|TRUNCATE(?:\s+TABLE)?)\s+`?(objects|classes|properties|methods)`?\b/i', $query, $m)) return;
        if (strtolower($m[1]) === 'methods' && preg_match('/^\s*UPDATE\b/i', $query)) {
            preg_match('/\bSET\s+(.+)\s+WHERE\b/is', $query, $set);
            preg_match_all('/(?:^|,)\s*`?(\w+)`?\s*=/', $set[1] ?? '', $fields);
            if ($fields[1] && !array_diff(array_map('strtoupper', $fields[1]), array('EXECUTED', 'EXECUTED_SRC', 'EXECUTED_PARAMS'))) return;
        }
        $this->invalidate();
        if (in_array(strtolower($m[1]), array('objects', 'properties', 'classes'), true)) {
            if (Config::get('USE_REDIS')) {
                $redis = $this->redis->requireConnection();
                foreach (array('mjd:', 'p:') as $prefix) foreach (Core::cache()->redisKeys($prefix) as $key) $redis->del($key);
            } elseif ($this->db->tableExists('cached_values')) {
                $this->db->requireExec("DELETE FROM cached_values WHERE KEYWORD LIKE 'mjd:%' OR KEYWORD LIKE 'p:%'");
            }
        }
    }
}
