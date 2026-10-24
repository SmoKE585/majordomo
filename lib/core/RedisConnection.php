<?php

namespace MajorDoMo\Core;

use \Throwable;
use \RuntimeException;
use \Redis;

/**
 * Общее соединение Redis и диагностика его доступности без изменения данных.
 * Изменения: 2026-10-24 — PING и показатели INFO для мониторинга на главной.
 */
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

    /** Проверяет доступность отдельно от прав на чтение статистики. */
    public function diagnostics(): array
    {
        $enabled = (bool)Config::get('USE_REDIS', false);
        $result = array(
            'enabled' => $enabled, 'available' => false,
            'level' => $enabled ? 'danger' : 'neutral',
            'status_text' => $enabled ? 'Недоступен' : 'Отключён',
            'note' => $enabled ? 'Проверьте Redis, подключение и авторизацию в общих настройках.' : 'Redis отключён в настройках системы.',
            'latency_ms' => null, 'memory_used' => null, 'memory_max' => null,
            'memory_percent' => null, 'clients' => null, 'ops' => null,
            'keys' => null, 'uptime' => null, 'evicted_keys' => null,
        );
        if (!$enabled) return $result;

        $client = $this->get();
        if (!$client) return $result;
        try {
            $started = microtime(true);
            $pong = $client->ping();
            if ($pong !== true && strtoupper(ltrim((string)$pong, '+')) !== 'PONG') {
                throw new RuntimeException('Redis PING failed');
            }
            $result['latency_ms'] = round((microtime(true) - $started) * 1000, 2);
        } catch (Throwable $e) {
            $this->discard();
            return $result;
        }

        $result['available'] = true;
        $result['level'] = 'ok';
        $result['status_text'] = 'Доступен';
        $result['note'] = 'Время ответа — PING; показатели нагрузки — по всему серверу Redis.';
        try {
            $info = $client->info();
            if (!is_array($info) || !$info) throw new RuntimeException('Redis INFO unavailable');
            foreach (array('memory_used' => 'used_memory', 'memory_max' => 'maxmemory',
                'clients' => 'connected_clients', 'ops' => 'instantaneous_ops_per_sec',
                'uptime' => 'uptime_in_seconds', 'evicted_keys' => 'evicted_keys') as $field => $key) {
                if (isset($info[$key]) && is_numeric($info[$key])) $result[$field] = (int)$info[$key];
            }
            $db = Config::int('REDIS_DB', defined('SETTINGS_SYSTEM_REDIS_DB') ? (int)SETTINGS_SYSTEM_REDIS_DB : 0);
            $keyspace = $info['db' . $db] ?? '';
            if ($keyspace === '' && isset($info['redis_version'])) $result['keys'] = 0;
            if (is_array($keyspace) && isset($keyspace['keys'])) {
                $result['keys'] = (int)$keyspace['keys'];
            } elseif (is_string($keyspace) && preg_match('/(?:^|,)keys=(\d+)/', $keyspace, $match)) {
                $result['keys'] = (int)$match[1];
            }
            if ($result['memory_used'] !== null && $result['memory_max'] > 0) {
                $result['memory_percent'] = round($result['memory_used'] / $result['memory_max'] * 100, 1);
                if ($result['memory_percent'] >= 80) {
                    $result['level'] = $result['memory_percent'] >= 90 ? 'danger' : 'warning';
                    $result['status_text'] = 'Мало свободной памяти';
                }
            }
        } catch (Throwable $e) {
            // Отказ INFO (например, ACL) не означает, что сам Redis недоступен.
            $result['level'] = 'warning';
            $result['status_text'] = 'Доступен, нет статистики';
            $result['note'] = 'PING успешен, но INFO недоступен. Проверьте права пользователя Redis на чтение статистики.';
        }
        return $result;
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
            $message = 'Redis connection unavailable (' . get_class($e) . ')';
            if (function_exists('DebMes')) {
                DebMes($message, 'redis', 'error');
            } else {
                error_log('MajorDoMo: ' . $message);
            }
            return null;
        }
    }
}
