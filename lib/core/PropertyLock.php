<?php

namespace MajorDoMo\Core;

use \RuntimeException;

final class PropertyLock
{
    public function __construct(private Database $db, private RedisConnection $redis) {}

    public function acquire($object_id, $property)
    {
        $key = $this->redis->key('lock:property:') . (int)$object_id . ':' . hash('sha256', strtolower($property));
        $token = bin2hex(random_bytes(16));
        if (Config::get('USE_REDIS')) {
            $redis = $this->redis->requireConnection();
            $deadline = microtime(true) + 5;
            do {
                if ($redis->set($key, $token, array('nx', 'px' => 120000))) return array($key, $token, 'redis');
                usleep(10000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Property update is busy; retry later');
        }
        $lock_name = substr($key, 0, 64);
        $row = $this->db->one("SELECT GET_LOCK('" . $this->db->escape($lock_name) . "',5) AS ACQUIRED");
        if ((int)($row['ACQUIRED'] ?? 0) !== 1) throw new RuntimeException('Cannot lock property update');
        return array($lock_name, $token, 'sql');
    }

    public function check($lock)
    {
        if ($lock[2] === 'redis') {
            $valid = $this->redis->requireConnection()->eval("if redis.call('GET',KEYS[1]) == ARGV[1] then return redis.call('PEXPIRE',KEYS[1],120000) else return 0 end", array($lock[0], $lock[1]), 1);
            if (!$valid) throw new RuntimeException('Property update lock expired');
        }
    }

    public function release($lock)
    {
        if ($lock[2] === 'redis') {
            $this->redis->requireConnection()->eval("if redis.call('GET',KEYS[1]) == ARGV[1] then return redis.call('DEL',KEYS[1]) else return 0 end", array($lock[0], $lock[1]), 1);
        } else {
            $this->db->one("SELECT RELEASE_LOCK('" . $this->db->escape($lock[0]) . "')");
        }
    }
}
