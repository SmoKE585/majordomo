<?php

namespace MajorDoMo\Core\Cycles;

/**
 * Готовность конкретного процесса main, а не оставшийся от прошлого запуска heartbeat.
 * 2026-11-11 — зависимые циклы ожидают завершения инициализации без расходования повторов.
 */
final class CycleReadiness
{
    public function __construct(private string $root) {}

    public function reset(): void
    {
        $path = $this->path();
        if (is_file($path) && !unlink($path)) throw new \RuntimeException('Не удалось сбросить готовность main');
    }

    public function markReady(): void
    {
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать каталог состояния циклов');
        }
        $temporary = $path . '.' . getmypid() . '.tmp';
        try {
            if (file_put_contents($temporary, json_encode(array('pid' => getmypid(), 'ready_at' => time()), JSON_THROW_ON_ERROR), LOCK_EX) === false
                || !chmod($temporary, 0666) || !rename($temporary, $path)) {
                throw new \RuntimeException('Не удалось записать готовность main');
            }
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }

    public function ready(int $pid): bool
    {
        if ($pid < 1 || !is_file($this->path())) return false;
        $data = json_decode(file_get_contents($this->path()), true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) && (int)($data['pid'] ?? 0) === $pid;
    }

    private function path(): string { return rtrim($this->root, '/\\') . '/cms/cycles/main.ready.json'; }
}
