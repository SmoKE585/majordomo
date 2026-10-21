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
        $prefix = Config::has('REDIS_PREFIX')
            ? Config::string('REDIS_PREFIX')
            : 'mjd:' . substr(hash('sha256', Config::string('DB_NAME')), 0, 12) . ':';
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
        $redisHost = Config::get('USE_REDIS', false);
        if (!$redisHost) {
            return null;
        }

        $redisConnection = &$this->connection;
        $retry_after = &$this->retryAfter;
        if (microtime(true) < $retry_after) return null;
        if (isset($redisConnection) && is_object($redisConnection)) {
            return $redisConnection;
        }

        $host = (string)$redisHost;
        if ($host === '') {
            $host = '127.0.0.1';
        }

        $port = Config::has('REDIS_PORT')
            ? Config::int('REDIS_PORT')
            : (defined('SETTINGS_SYSTEM_REDIS_PORT') ? (int)SETTINGS_SYSTEM_REDIS_PORT : 6379);

        $db = Config::has('REDIS_DB')
            ? Config::int('REDIS_DB')
            : (defined('SETTINGS_SYSTEM_REDIS_DB') ? (int)SETTINGS_SYSTEM_REDIS_DB : 0);

        $username = Config::has('REDIS_USERNAME')
            ? Config::string('REDIS_USERNAME')
            : (defined('SETTINGS_SYSTEM_REDIS_USERNAME') ? (string)SETTINGS_SYSTEM_REDIS_USERNAME : '');

        $password = Config::has('REDIS_PASSWORD')
            ? Config::string('REDIS_PASSWORD')
            : (defined('SETTINGS_SYSTEM_REDIS_PASSWORD') ? (string)SETTINGS_SYSTEM_REDIS_PASSWORD : '');

        $timeout = Config::has('REDIS_TIMEOUT')
            ? Config::float('REDIS_TIMEOUT')
            : (defined('SETTINGS_SYSTEM_REDIS_TIMEOUT') ? (float)SETTINGS_SYSTEM_REDIS_TIMEOUT : 2.5);

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
