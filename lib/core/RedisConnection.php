<?php

namespace MajorDoMo\Core;

use \Throwable;
use \RuntimeException;
use \Redis;

final class RedisConnection
{
    private mixed $connection = null;
    private float $retryAfter = 0;

    public function key(string $suffix): string
    {
        $prefix = defined('REDIS_PREFIX') ? (string)REDIS_PREFIX : 'mjd:' . substr(hash('sha256', (string)DB_NAME), 0, 12) . ':';
        return $prefix . $suffix;
    }

    public function requireConnection(): object
    {
        $connection = $this->get();
        if (!$connection) throw new RuntimeException('Redis is unavailable; operation was not accepted');
        return new RedisClient($this);
    }

    public function discard(): void
    {
        $this->connection = null;
        $this->retryAfter = microtime(true) + 2;
    }

    public function get()
    {
        if (!defined('USE_REDIS') || !USE_REDIS) {
            return null;
        }

        $redisConnection = &$this->connection;
        $retry_after = &$this->retryAfter;
        if (microtime(true) < $retry_after) return null;
        if (isset($redisConnection) && is_object($redisConnection)) {
            return $redisConnection;
        }

        $host = (string)USE_REDIS;
        if ($host === '') {
            $host = '127.0.0.1';
        }

        $port = 6379;
        if (defined('REDIS_PORT')) {
            $port = (int)REDIS_PORT;
        } elseif (defined('SETTINGS_SYSTEM_REDIS_PORT')) {
            $port = (int)SETTINGS_SYSTEM_REDIS_PORT;
        }

        $db = 0;
        if (defined('REDIS_DB')) {
            $db = (int)REDIS_DB;
        } elseif (defined('SETTINGS_SYSTEM_REDIS_DB')) {
            $db = (int)SETTINGS_SYSTEM_REDIS_DB;
        }

        $username = '';
        if (defined('REDIS_USERNAME')) {
            $username = (string)REDIS_USERNAME;
        } elseif (defined('SETTINGS_SYSTEM_REDIS_USERNAME')) {
            $username = (string)SETTINGS_SYSTEM_REDIS_USERNAME;
        }

        $password = '';
        if (defined('REDIS_PASSWORD')) {
            $password = (string)REDIS_PASSWORD;
        } elseif (defined('SETTINGS_SYSTEM_REDIS_PASSWORD')) {
            $password = (string)SETTINGS_SYSTEM_REDIS_PASSWORD;
        }

        $timeout = 2.5;
        if (defined('REDIS_TIMEOUT')) {
            $timeout = (float)REDIS_TIMEOUT;
        } elseif (defined('SETTINGS_SYSTEM_REDIS_TIMEOUT')) {
            $timeout = (float)SETTINGS_SYSTEM_REDIS_TIMEOUT;
        }

        try {
            $redis = new Redis();
            if (!$redis->pconnect($host, $port, $timeout, 'majordomo:' . $db)) {
                throw new RuntimeException('Redis connection failed');
            }
            $redis->setOption(Redis::OPT_READ_TIMEOUT, $timeout);

            if ($password !== '') {
                if ($username !== '') {
                    if (!$redis->auth(array($username, $password))) {
                        throw new RuntimeException('Redis authentication failed');
                    }
                } else {
                    if (!$redis->auth($password)) {
                        throw new RuntimeException('Redis authentication failed');
                    }
                }
            }

            if (!$redis->select($db)) {
                throw new RuntimeException('Redis database selection failed');
            }

            $redisConnection = $redis;
            return $redisConnection;
        } catch (Throwable $e) {
            $redisConnection = null;
            $retry_after = microtime(true) + 2;
            error_log('MajorDoMo: Redis connection unavailable (' . get_class($e) . ')');
            return null;
        }
    }
}
