<?php

namespace MajorDoMo\Core\Maintenance;

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Console\PhpToolRunner;

/**
 * Расписание обслуживания с сохранением результатов и ограниченными повторами.
 * 2026-11-11 — лёгкая очистка каждые 15 минут; тяжёлые этапы после 04:00 в отдельных процессах.
 */
final class AutomaticMaintenance
{
    public const TASKS = array('cleanup', 'backup', 'files', 'tables');

    public function __construct(private string $root, private ?\Closure $execute = null) {}

    public function status(): array
    {
        $path = $this->directory() . '/automatic.json';
        if (!is_file($path)) return array();
        $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new \RuntimeException('Некорректное состояние автообслуживания');
        return $state;
    }

    /** Одновременно работает только один планировщик; неудачный этап не блокирует остальные. */
    public function runDue(?callable $heartbeat = null, bool $force = false, ?int $now = null): array
    {
        $now ??= time();
        $lock = self::lock($this->directory(), 'schedule.lock');
        if ($lock === null) return array('busy' => true, 'tasks' => array());
        try {
            $state = $this->status();
            $results = array();
            foreach (self::TASKS as $task) {
                $period = $task === 'cleanup' ? (string)intdiv($now, max(60, Config::int('MAINTENANCE_INTERVAL', 900))) : date('Y-m-d', $now);
                $previous = $state[$task] ?? array();
                if (!$force) {
                    if ($task !== 'cleanup' && (int)date('G', $now) < max(0, min(23, Config::int('MAINTENANCE_HOUR', 4)))) continue;
                    if (($previous['period'] ?? '') === $period
                        && (($previous['status'] ?? '') === 'done' || (int)($previous['attempts'] ?? 0) >= 3
                            || (int)($previous['retry_at'] ?? 0) > $now)) continue;
                }
                $attempts = ($previous['period'] ?? '') === $period ? (int)($previous['attempts'] ?? 0) + 1 : 1;
                $timeout = $task === 'backup' ? max(60, Config::int('MAINTENANCE_TIMEOUT', 1800)) : ($task === 'cleanup' ? 60 : 300);
                $state[$task] = array('period' => $period, 'status' => 'running', 'attempts' => $attempts,
                    'started_at' => $now, 'retry_at' => $now + $timeout + 30);
                $this->save($state);
                if ($heartbeat) $heartbeat();
                try {
                    $result = $this->execute !== null ? ($this->execute)($task, $heartbeat)
                        : (new PhpToolRunner())->run($this->root . '/majordomo', array('maintenance:run', '--task=' . $task, '--json'),
                            $this->root, $timeout, $heartbeat);
                    $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
                    if ($result['code'] !== 0 || !is_array($payload) || empty($payload['ok'])) {
                        throw new \RuntimeException((string)($payload['error'] ?? trim($result['stderr']) ?: 'Этап не завершён'));
                    }
                    $state[$task]['status'] = 'done';
                    $state[$task]['result'] = $payload['data'];
                    $state[$task]['finished_at'] = time();
                    if (function_exists('DebMes')) \DebMes('Обслуживание: ' . $task . ' завершён', 'maintenance');
                } catch (\Throwable $error) {
                    $state[$task]['status'] = 'failed';
                    $state[$task]['error'] = $error->getMessage();
                    $state[$task]['retry_at'] = $now + min(1800, 300 * $attempts);
                    if (function_exists('DebMes')) \DebMes('Обслуживание: ' . $task . ', попытка ' . $attempts . ': ' . $error->getMessage(), 'maintenance', 'error');
                }
                $this->save($state);
                $results[$task] = $state[$task];
            }
            return array('busy' => false, 'tasks' => $results);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Неблокирующая блокировка освобождается ОС даже при аварии владельца. */
    public static function lock(string $directory, string $name): mixed
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать каталог обслуживания');
        }
        if (!chmod($directory, 0777)) throw new \RuntimeException('Не удалось настроить права каталога обслуживания');
        $handle = fopen($directory . '/' . $name, 'c+');
        if ($handle === false) throw new \RuntimeException('Не удалось открыть блокировку обслуживания');
        if (!chmod($directory . '/' . $name, 0666)) { fclose($handle); throw new \RuntimeException('Не удалось настроить права блокировки'); }
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return null; }
        return $handle;
    }

    private function save(array $state): void
    {
        $path = $this->directory() . '/automatic.json';
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX) === false
                || !chmod($temporary, 0666) || !rename($temporary, $path)) throw new \RuntimeException('Не удалось сохранить состояние обслуживания');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    public function directory(): string { return rtrim($this->root, '/\\') . '/cms/cycles/maintenance'; }
}
