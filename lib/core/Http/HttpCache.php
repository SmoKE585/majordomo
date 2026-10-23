<?php

namespace MajorDoMo\Core\Http;

use MajorDoMo\Core\Config;
use MajorDoMo\Core\RedisConnection;

/**
 * Кэш HTTP-ответов с валидацией (ETag / Last-Modified).
 *
 * Хранилище выбирается автоматически: Redis, если он включён и доступен,
 * иначе файл в cms/cached/http. Наружу отдаётся один и тот же формат записи,
 * поэтому getURL() не знает, откуда пришёл ответ.
 *
 * Запись содержит тело, валидаторы и время получения. Срок жизни проверяет
 * вызывающий код: getURL() при истёкшем TTL делает условный запрос
 * (If-None-Match / If-Modified-Since) и на 304 переиспользует тело.
 *
 * 2026-10-23 — добавлено вместо «сырого» файлового кэша cms/cached/urls.
 */
final class HttpCache
{
    private string $root;
    private ?RedisConnection $redis;
    private bool $redisEnabled;

    public function __construct(?string $root = null, ?RedisConnection $redis = null)
    {
        $this->root = rtrim($root ?? (defined('ROOT') ? ROOT : Config::root()), '/\\');
        $this->redis = $redis;
        $this->redisEnabled = class_exists('\\Redis') && (bool)Config::get('USE_REDIS', false);
    }

    /**
     * Ключ записи. URL без метода/тела — это GET; POST получает отдельный ключ,
     * иначе два разных запроса к одному адресу подменяли бы друг друга.
     */
    public static function key(string $url, string $method = 'GET', string $payload = ''): string
    {
        return hash('sha256', strtoupper($method) . "\n" . $url . "\n" . $payload);
    }

    public static function filePath(string $root, string $key): string
    {
        return self::directoryFor($root) . DIRECTORY_SEPARATOR . $key . '.json';
    }

    private static function directoryFor(string $root): string
    {
        $prefix = $root !== '' ? rtrim($root, '/\\') . DIRECTORY_SEPARATOR : '';
        return $prefix . 'cms' . DIRECTORY_SEPARATOR . 'cached' . DIRECTORY_SEPARATOR . 'http';
    }

    private function directory(): string
    {
        return self::directoryFor($this->root);
    }

    /**
     * @return array{body:string,etag:string,last_modified:string,stored_at:int}|null
     */
    public function read(string $key): ?array
    {
        if ($this->redisEnabled) {
            $entry = $this->readRedis($key);
            if ($entry !== null) {
                return $entry;
            }
        }
        return $this->readFile($key);
    }

    public function write(string $key, string $body, array $meta = array()): bool
    {
        $entry = array(
            'body' => $body,
            'etag' => (string)($meta['etag'] ?? ''),
            'last_modified' => (string)($meta['last_modified'] ?? ''),
            'stored_at' => time(),
        );

        if ($this->redisEnabled && $this->writeRedis($key, $entry, (int)($meta['ttl'] ?? 0))) {
            // Файл мог остаться от периода без Redis — он больше не главный.
            $this->deleteFile($key);
            return true;
        }
        return $this->writeFile($key, $entry);
    }

    public function touch(string $key): bool
    {
        $entry = $this->read($key);
        if ($entry === null) {
            return false;
        }
        return $this->write($key, $entry['body'], array(
            'etag' => $entry['etag'],
            'last_modified' => $entry['last_modified'],
        ));
    }

    public function forget(string $key): void
    {
        if ($this->redisEnabled) {
            try {
                $this->redis()->requireConnection()->del($this->redisKey($key));
            } catch (\Throwable $error) {
                // Сбой Redis не должен мешать удалить файловую копию.
            }
        }
        $this->deleteFile($key);
    }

    /**
     * Полностью очистить HTTP-кэш.
     *
     * Нужен общей кнопке/действию «очистить кэш»: старые записи в
     * cms/cached/http иначе переживают её и продолжают отдавать старые ответы.
     * 2026-10-23 — добавлено.
     */
    public function clear(): void
    {
        if ($this->redisEnabled) {
            try {
                $client = $this->redis()->requireConnection();
                $cursor = null;
                $pattern = $this->redis()->key('http:') . '*';
                do {
                    $keys = $client->scan($cursor, $pattern, 500);
                    if ($keys === false) {
                        break;
                    }
                    if ($keys !== array()) {
                        $client->del($keys);
                    }
                } while ($cursor !== 0);
            } catch (\Throwable $error) {
                // Файлы всё равно чистим: Redis мог быть недоступен.
            }
        }
        foreach (array('*.json', '*.tmp') as $mask) {
            $files = glob($this->directory() . DIRECTORY_SEPARATOR . $mask);
            if (!is_array($files)) {
                continue;
            }
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    private function redis(): RedisConnection
    {
        return $this->redis ??= new RedisConnection();
    }

    private function redisKey(string $key): string
    {
        return $this->redis()->key('http:' . $key);
    }

    private function readRedis(string $key): ?array
    {
        try {
            $row = $this->redis()->requireConnection()->hGetAll($this->redisKey($key));
        } catch (\Throwable $error) {
            return null;
        }
        if (!is_array($row) || !isset($row['body']) || $row['body'] === '') {
            return null;
        }
        return array(
            'body' => (string)$row['body'],
            'etag' => (string)($row['etag'] ?? ''),
            'last_modified' => (string)($row['last_modified'] ?? ''),
            'stored_at' => (int)($row['stored_at'] ?? 0),
        );
    }

    private function writeRedis(string $key, array $entry, int $ttl): bool
    {
        try {
            $client = $this->redis()->requireConnection();
            if ($client->hMSet($this->redisKey($key), $entry) === false) {
                return false;
            }
            // Держим запись заметно дольше TTL: она нужна для 304 и для
            // диагностики, а место в Redis ограничено самим TTL-запасом.
            $client->expire($this->redisKey($key), max(86400, $ttl + 7 * 24 * 60 * 60));
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    private function readFile(string $key): ?array
    {
        $file = self::filePath($this->root, $key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['body'])) {
            // Битый файл не должен навсегда отравлять кэш.
            @unlink($file);
            return null;
        }
        $body = base64_decode((string)$data['body'], true);
        if ($body === false) {
            @unlink($file);
            return null;
        }
        return array(
            'body' => $body,
            'etag' => (string)($data['etag'] ?? ''),
            'last_modified' => (string)($data['last_modified'] ?? ''),
            'stored_at' => (int)($data['stored_at'] ?? 0),
        );
    }

    private function writeFile(string $key, array $entry): bool
    {
        $file = self::filePath($this->root, $key);
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            return false;
        }
        $payload = array(
            // base64: лента/HTML/JSON могут быть не в UTF-8, json_encode на них падает.
            'body' => base64_encode($entry['body']),
            'etag' => $entry['etag'],
            'last_modified' => $entry['last_modified'],
            'stored_at' => $entry['stored_at'],
        );
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            return false;
        }
        $this->collectGarbage();
        return true;
    }

    private function deleteFile(string $key): void
    {
        $file = self::filePath($this->root, $key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Редкая уборка: без неё файловый кэш растёт бесконечно, потому что
     * getURL() удаляет запись только по конкретному URL.
     */
    private function collectGarbage(): void
    {
        if (random_int(1, 200) !== 1) {
            return;
        }
        $files = glob($this->directory() . DIRECTORY_SEPARATOR . '*.json');
        if (!is_array($files)) {
            return;
        }
        $threshold = time() - 30 * 24 * 60 * 60;
        foreach ($files as $file) {
            $modified = @filemtime($file);
            if ($modified !== false && $modified < $threshold) {
                @unlink($file);
            }
        }
    }
}
