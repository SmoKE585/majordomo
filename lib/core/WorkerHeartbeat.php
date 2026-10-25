<?php

namespace MajorDoMo\Core;

/**
 * Прогресс контроллера воркера, независимый от MySQL и Redis.
 * 2026-10-25 — зависший контроллер можно перезапустить даже при живом процессе и остановившейся БД.
 */
final class WorkerHeartbeat
{
    private static function path(string $root, int $pid): string
    {
        if ($pid < 1) throw new \InvalidArgumentException('Некорректный PID исполнителя');
        return rtrim($root, '/\\') . '/cms/cycles/workers/' . $pid . '.beat';
    }
    public static function touch(string $root, int $pid): void
    {
        $path = self::path($root, $pid);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Не удалось создать каталог heartbeat воркеров');
        if (file_put_contents($path, (string)time(), LOCK_EX) === false) throw new \RuntimeException('Не удалось обновить heartbeat воркера');
    }
    public static function stalled(string $root, int $pid, float $uptime, int $timeout): bool
    {
        if ($uptime < $timeout) return false;
        $path = self::path($root, $pid);
        clearstatcache(true, $path);
        return !is_file($path) || time() - (int)filemtime($path) >= $timeout;
    }
    public static function remove(string $root, int $pid): void
    {
        $path = self::path($root, $pid);
        if (is_file($path)) unlink($path);
    }
}
