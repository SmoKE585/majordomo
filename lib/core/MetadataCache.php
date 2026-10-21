<?php

namespace MajorDoMo\Core;


final class MetadataCache
{
    public function __construct(private Database $db, private RedisConnection $redis) {}

    public function get($key, $load)
    {
        if (!Config::get('USE_REDIS')) return $load();
        $redis = $this->redis->requireConnection();
        $epoch = $redis->get($this->redis->key('meta:epoch')) ?: '0';
        $cache_key = $this->redis->key('meta:') . $epoch . ':' . strtolower($key);
        $encoded = $redis->get($cache_key);
        if ($encoded !== false) {
            $value = json_decode($encoded, true);
            if (is_array($value)) return $value;
        }
        $value = $load();
        if (is_array($value) && $value) $redis->set($cache_key, json_encode($value, JSON_THROW_ON_ERROR), 300);
        return $value;
    }

    public function invalidate()
    {
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
