<?php

namespace MajorDoMo\Core\Console;

use MajorDoMo\Core\Config;
use MajorDoMo\Core\RedisConnection;

/** Reads a single runtime snapshot without loader, migrations, DDL or globals. */
final class CycleRuntimeReader
{
    public function read(): array
    {
        if (!extension_loaded('mysqli') || Config::string('DB_NAME') === '') {
            throw new \RuntimeException('Не настроена БД или отсутствует mysqli. Для желаемых состояний используйте cycles:status --offline.');
        }
        $db = mysqli_init();
        $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        try {
            if (!$db->real_connect(Config::string('DB_HOST'), Config::string('DB_USER'), Config::string('DB_PASSWORD'), Config::string('DB_NAME'), Config::int('DB_PORT') ?: 3306)) {
                throw new \RuntimeException('Connection failed');
            }
            $db->set_charset('utf8');
            if (!Config::get('USE_REDIS')) {
                $result = $db->query('SELECT TITLE, VALUE FROM cached_cycles');
                if (!$result) throw new \RuntimeException('Runtime table unavailable');
                $values = array();
                while ($row = $result->fetch_assoc()) $values[strtolower($row['TITLE'])] = $row['VALUE'];
                return $values;
            }
            // Legacy Redis connection settings live in settings, not in .env.
            $result = $db->query("SELECT NAME, VALUE FROM settings WHERE NAME IN ('SYSTEM_REDIS_PORT','SYSTEM_REDIS_DB','SYSTEM_REDIS_USERNAME','SYSTEM_REDIS_PASSWORD','SYSTEM_REDIS_TIMEOUT')");
            if (!$result) throw new \RuntimeException('Settings unavailable');
            $settings = array();
            while ($row = $result->fetch_assoc()) $settings[$row['NAME']] = $row['VALUE'];
        } catch (\Throwable $error) {
            // Driver errors can contain credentials. Expose a fixed message.
            throw new \RuntimeException('Не удалось прочитать состояния из БД. Проверьте подключение; --offline покажет желаемые состояния.');
        } finally {
            $db->close();
        }
        if (!extension_loaded('redis')) throw new \RuntimeException('Для чтения состояний требуется расширение Redis.');
        $setting = static fn(string $name, mixed $default) => Config::has($name) ? Config::get($name) : ($settings['SYSTEM_' . $name] ?? $default);
        $redis = new \Redis();
        try {
            $timeout = max(0.1, (float)$setting('REDIS_TIMEOUT', 2.5));
            if (!$redis->connect((string)Config::get('USE_REDIS'), (int)$setting('REDIS_PORT', 6379), $timeout)) throw new \RuntimeException('Connect failed');
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, $timeout);
            $password = (string)$setting('REDIS_PASSWORD', '');
            $username = (string)$setting('REDIS_USERNAME', '');
            if ($password !== '' && !$redis->auth($username !== '' ? array($username, $password) : $password)) throw new \RuntimeException('Auth failed');
            if (!$redis->select((int)$setting('REDIS_DB', 0))) throw new \RuntimeException('Select failed');
            $entries = $redis->hGetAll((new RedisConnection())->key('cycles'));
            if (!is_array($entries)) throw new \RuntimeException('Read failed');
            $values = array();
            foreach ($entries as $key => $encoded) {
                $entry = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($entry) || !isset($entry['VALUE'])) throw new \RuntimeException('Invalid runtime entry');
                $values[strtolower($key)] = $entry['VALUE'];
            }
            return $values;
        } catch (\Throwable $error) {
            throw new \RuntimeException('Не удалось прочитать состояния из Redis. Проверьте подключение; --offline покажет желаемые состояния.');
        } finally {
            if ($redis->isConnected()) $redis->close();
        }
    }
}
