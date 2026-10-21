<?php

namespace MajorDoMo\Core;

use \RuntimeException;
use \Redis;

final class Cache
{
    public function __construct(
        private Database $db,
        private RedisConnection $redis,
        private MetadataCache $metadata
    ) {}

    public function saveCycle($key, $value)
    {
        if (Config::get('USE_REDIS')) {
            if (is_array($value)) return false;
            return $this->redis->requireConnection()->hSet($this->redis->key('cycles'), strtolower((string)$key), json_encode(array('TITLE' => (string)$key, 'VALUE' => (string)$value), JSON_THROW_ON_ERROR));
        }
        if (is_array($value) || strlen((string)$value) > 255) {
            $this->db->exec("DELETE FROM cached_cycles WHERE TITLE='" . $this->db->escape($key) . "'");
            return false;
        }
        return $this->db->exec("REPLACE INTO cached_cycles (TITLE, VALUE) VALUES ('" . $this->db->escape($key) . "','" . $this->db->escape($value) . "')");
    }

    public function cycle($key)
    {
        if (Config::get('USE_REDIS')) {
            $encoded = $this->redis->requireConnection()->hGet($this->redis->key('cycles'), strtolower((string)$key));
            if ($encoded !== false) return json_decode($encoded, true, 512, JSON_THROW_ON_ERROR)['VALUE'];
            return false;
        }
        $rec = $this->db->one("SELECT VALUE FROM cached_cycles WHERE TITLE='" . $this->db->escape($key) . "'");
        return $rec['VALUE'] ?? false;
    }

    public function cycles(): array
    {
        if (!Config::get('USE_REDIS')) return $this->db->select('SELECT TITLE,VALUE FROM cached_cycles');
        $rows = $this->redis->requireConnection()->hGetAll($this->redis->key('cycles'));
        return array_values(array_map(fn($row) => json_decode($row, true, 512, JSON_THROW_ON_ERROR), $rows));
    }

    public function clearCycles(): void
    {
        if (Config::get('USE_REDIS')) $this->redis->requireConnection()->del($this->redis->key('cycles'));
        else $this->db->requireExec('DELETE FROM cached_cycles');
    }

    public function ensureSqlTable()
    {
        static $checked = false;
        if ($checked) return true;
        if ($this->db->tableExists('cached_values')) return $checked = true;
        $size = max(1, Config::int('CACHE_VALUE_MAX_SIZE', 255));
        return $checked = (bool)$this->db->exec("CREATE TABLE IF NOT EXISTS cached_values (KEYWORD CHAR(100) NOT NULL PRIMARY KEY, DATAVALUE VARCHAR($size) NOT NULL) ENGINE=MEMORY DEFAULT CHARSET=utf8");
    }

    public function redisKeys($prefix = '')
    {
        $redis = $this->redis->requireConnection();
        // Quote Redis glob metacharacters: the public argument is a literal prefix.
        $pattern = $this->redis->key('cache:') . strtr(strtolower((string)$prefix), array('\\' => '\\\\', '*' => '\\*', '?' => '\\?', '[' => '\\[', ']' => '\\]')) . '*';
        $cursor = null;
        do {
            $keys = $redis->scan($cursor, $pattern, 500);
            if (is_array($keys)) foreach ($keys as $key) yield $key;
        } while ($cursor !== 0);
    }

    public function clear($prefix = '')
    {
        $prefix = strtolower((string)$prefix);
        if (Config::get('USE_REDIS')) {
            $redis = $this->redis->requireConnection();
            // Epoch change prevents a racing metadata fill from reviving an old entry.
            $this->metadata->invalidate();
            foreach ($this->redisKeys($prefix) as $key) $redis->del($key);
            return;
        }
        if (!$this->ensureSqlTable()) return;
        if ($prefix === '') $this->db->exec('TRUNCATE TABLE cached_values');
        else $this->db->exec("DELETE FROM cached_values WHERE KEYWORD LIKE '" . $this->db->escapeLike($prefix) . "%'");
    }

    public function all($prefix = '')
    {
        if (Config::get('USE_REDIS')) {
            $redis = $this->redis->requireConnection();
            $out = array();
            foreach ($this->redisKeys($prefix) as $key) {
                $value = $redis->get($key);
                if ($value !== false) $out[substr($key, strlen($this->redis->key('cache:')))] = $value;
            }
            return $out;
        }
        if (!$this->ensureSqlTable()) return array();
        return $this->db->select("SELECT * FROM cached_values WHERE KEYWORD LIKE '" . $this->db->escapeLike(strtolower((string)$prefix)) . "%'");
    }

    public function set($key, $value, $only_if_missing = false)
    {
        $key = strtolower((string)$key);
        if (is_array($value) || is_object($value) || $value === false) {
            if (!$only_if_missing) $this->delete($key);
            return false;
        }
        if (Config::get('USE_REDIS')) {
            $redis = $this->redis->requireConnection();
            $result = $redis->set($this->redis->key('cache:') . $key, (string)$value, $only_if_missing ? array('nx') : array());
            if ($result === false && !$only_if_missing) throw new RuntimeException('Redis cache write failed');
            return $result;
        }
        $size = Config::int('CACHE_VALUE_MAX_SIZE', 255);
        if (strlen((string)$value) > $size) return $this->delete($key);
        if (!$this->ensureSqlTable()) return false;
        $verb = $only_if_missing ? 'INSERT IGNORE' : 'REPLACE';
        return $this->db->exec($verb . " INTO cached_values (KEYWORD,DATAVALUE) VALUES ('" . $this->db->escape($key) . "','" . $this->db->escape($value) . "')");
    }

    public function delete($key)
    {
        $key = strtolower((string)$key);
        if (Config::get('USE_REDIS')) return $this->redis->requireConnection()->del($this->redis->key('cache:') . $key);
        if (!$this->ensureSqlTable()) return false;
        return $this->db->exec("DELETE FROM cached_values WHERE KEYWORD='" . $this->db->escape($key) . "'");
    }

    public function get($key)
    {
        $key = strtolower((string)$key);
        if (Config::get('USE_REDIS')) return $this->redis->requireConnection()->get($this->redis->key('cache:') . $key);
        if (!$this->ensureSqlTable()) return false;
        $rec = $this->db->one("SELECT DATAVALUE FROM cached_values WHERE KEYWORD='" . $this->db->escape($key) . "'");
        return $rec['DATAVALUE'] ?? false;
    }
}
