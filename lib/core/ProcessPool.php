<?php

namespace MajorDoMo\Core;

use \Throwable;

/**
 * Пул долгоживущих дочерних процессов для циклов-супервизоров.
 *
 * Нужен там, где один PHP-процесс не может одновременно принимать MQTT,
 * обрабатывать события и публиковать команды. Пул не читает stdout через
 * анонимные pipe (на Windows это блокирует родителя), а пишет журнал каждого
 * ребёнка в отдельный файл и следит за кодом выхода.
 *
 * 2026-10-25 — три попытки восстановления, затем ошибка и ожидание рестарта цикла.
 * 2026-10-23 — добавлено для MQTT runtime и параллельного core_runtime.
 */
final class ProcessPool
{
    /** @var array<string,array<string,mixed>> */
    private array $slots = array();
    private bool $stopping = false;
    private Cycles\CycleWatchdog $recovery;

    public function __construct(private string $logDirectory)
    {
        $this->recovery = new Cycles\CycleWatchdog();
        if (!is_dir($logDirectory)) @mkdir($logDirectory, 0777, true);
    }

    /**
     * @param array<int,string> $command Команда без shell-обёртки.
     */
    public function add(string $name, array $command): void
    {
        $this->slots[$name] = array(
            'name' => $name,
            'command' => array_values($command),
            'handle' => null,
            'pid' => 0,
            'started' => 0.0,
            'restarts' => 0,
            'backoff' => 0,
            'next_start' => 0.0,
            'log' => $this->logFile($name),
            'last_error' => '',
            'failed' => false,
        );
    }

    public function start(): void
    {
        $this->stopping = false;
        foreach (array_keys($this->slots) as $name) {
            $this->spawn($name);
        }
    }

    /**
     * Проверяет процессы и поднимает упавшие с ограниченным backoff.
     *
     * @return array<int,string> События для журнала супервизора.
     */
    public function maintain(): array
    {
        $events = array();
        foreach ($this->slots as $name => &$slot) {
            if (!is_resource($slot['handle'])) {
                if ($this->stopping || $slot['failed']) continue;
                if (microtime(true) < $slot['next_start']) continue;
                if ($this->spawn($name)) {
                    $events[] = $name . ': started';
                } else {
                    $events[] = $name . ': start failed';
                }
                continue;
            }
            $status = proc_get_status($slot['handle']);
            if (!empty($status['running'])) {
                $this->recovery->stalled($name, (int)$slot['started'], time(), time());
                continue;
            }
            $exit = (int)($status['exitcode'] ?? -1);
            $signal = (int)($status['termsig'] ?? 0);
            $uptime = microtime(true) - (float)$slot['started'];
            $slot['last_error'] = ($signal > 0 ? 'Процесс остановлен сигналом ' . $signal : ($exit === 0 ? 'Процесс неожиданно завершился без сообщения об ошибке' : 'Процесс завершился с кодом ' . $exit)) . '; время работы ' . (int)$uptime . ' с';
            $tail = $this->lastOutput($slot);
            if ($tail !== '') $slot['last_error'] .= "\nПоследний вывод: " . ErrorDetails::clean($tail, 2500);
            proc_close($slot['handle']);
            $slot['handle'] = null;
            $slot['pid'] = 0;
            if ($this->stopping) continue;
            $slot['restarts']++;
            $this->scheduleRecovery($name);
            $events[] = $name . ': ' . $slot['last_error'] . ', restart in ' . $slot['backoff'] . 's';
            $this->appendLog($name, '--- process exited: ' . $slot['last_error'] . '; restart in ' . $slot['backoff'] . 's ---');
        }
        unset($slot);
        return $events;
    }

    public function stop(): void
    {
        $this->stopping = true;
        foreach ($this->slots as $name => &$slot) {
            if (!is_resource($slot['handle'])) continue;
            $status = proc_get_status($slot['handle']);
            if (!empty($status['running'])) {
                @proc_terminate($slot['handle']);
                $deadline = microtime(true) + 3;
                do {
                    usleep(50000);
                    $status = proc_get_status($slot['handle']);
                    if (empty($status['running'])) break;
                } while (microtime(true) < $deadline);
                if (!empty($status['running'])) {
                    @proc_terminate($slot['handle'], 9);
                    usleep(100000);
                }
            }
            @proc_close($slot['handle']);
            $slot['handle'] = null;
            $slot['pid'] = 0;
            $this->appendLog($name, '--- process stopped by supervisor ---');
        }
        unset($slot);
    }

    public function isStopping(): bool
    {
        return $this->stopping;
    }

    /** Принудительно перезапускает один процесс (зависание без выхода). */
    public function restart(string $name): bool
    {
        if (!isset($this->slots[$name])) return false;
        $slot = &$this->slots[$name];
        if ($slot['failed']) return false;
        if (is_resource($slot['handle'])) {
            @proc_terminate($slot['handle']);
            $deadline = microtime(true) + 1.0;
            do {
                $status = proc_get_status($slot['handle']);
                if (empty($status['running'])) break;
                usleep(50000);
            } while (microtime(true) < $deadline);
            if (!empty($status['running'])) {
                @proc_terminate($slot['handle'], 9);
                usleep(100000);
            }
            @proc_close($slot['handle']);
            $slot['handle'] = null;
            $slot['pid'] = 0;
        }
        $this->appendLog($name, '--- restart requested by supervisor (stuck heartbeat) ---');
        $slot['last_error'] = 'Исполнитель перестал подтверждать работу';
        $this->scheduleRecovery($name);
        return !$slot['failed'];
    }

    public function uptime(string $name): float
    {
        $started = (float)($this->slots[$name]['started'] ?? 0);
        return $started > 0 ? microtime(true) - $started : 0.0;
    }

    public function pids(): array
    {
        $result = array();
        foreach ($this->slots as $name => $slot) {
            $result[$name] = (int)$slot['pid'];
        }
        return $result;
    }

    public function restarts(string $name): int
    {
        return (int)($this->slots[$name]['restarts'] ?? 0);
    }

    public function lastError(string $name): string
    {
        return (string)($this->slots[$name]['last_error'] ?? '');
    }

    private function spawn(string $name): bool
    {
        $slot = &$this->slots[$name];
        $command = $slot['command'];
        if (!$command) return false;
        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('file', $slot['log'], 'a'),
            2 => array('file', $slot['log'], 'a'),
        );
        $this->appendLog($name, '--- starting: ' . implode(' ', $command) . ' ---');
        clearstatcache(true, $slot['log']);
        $slot['log_offset'] = is_file($slot['log']) ? (int)filesize($slot['log']) : 0;
        try {
            $process = proc_open($command, $descriptors, $pipes, defined('ROOT') ? ROOT : null, null, array('bypass_shell' => true));
        } catch (Throwable $error) {
            $slot['last_error'] = $error->getMessage();
            $this->scheduleRecovery($name);
            return false;
        }
        if (!is_resource($process)) {
            $slot['last_error'] = 'proc_open failed';
            $this->scheduleRecovery($name);
            return false;
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) fclose($pipe);
        }
        $status = proc_get_status($process);
        $slot['handle'] = $process;
        $slot['pid'] = (int)($status['pid'] ?? 0);
        $slot['started'] = microtime(true);
        $slot['backoff'] = 0;
        $slot['next_start'] = 0.0;
        return true;
    }

    private function logFile(string $name): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $name);
        return rtrim($this->logDirectory, '/\\') . DIRECTORY_SEPARATOR
            . 'log_' . date('Y-m-d') . '-pool-' . $safe . '.txt';
    }

    private function scheduleRecovery(string $name): void
    {
        $slot = &$this->slots[$name];
        $retry = $this->recovery->failure($name, time());
        $slot['backoff'] = $retry['delay'];
        $slot['next_start'] = microtime(true) + $retry['delay'];
        if (!$retry['critical']) return;
        $slot['failed'] = true;
        $message = 'Исполнитель ' . $name . ' не восстановлен после трёх повторных запусков. ' . $slot['last_error'];
        $this->appendLog($name, $message);
        if ($retry['notify'] && function_exists('registerError')) {
            registerError('worker_stop:' . $name, ErrorDetails::encode($message, array('source' => $name,
                'entity' => $name, 'file' => (string)($slot['command'][1] ?? ''),
                'action' => 'Проверьте журнал исполнителя ' . basename($slot['log']) . ' и перезапустите управляющий цикл.')));
        }
    }

    /** Читаем только хвост текущего запуска, не смешивая его с прежним падением. */
    private function lastOutput(array $slot): string
    {
        if (!is_file($slot['log'])) return '';
        $handle = fopen($slot['log'], 'rb');
        if ($handle === false) return '';
        try {
            $size = (int)(fstat($handle)['size'] ?? 0);
            fseek($handle, max((int)($slot['log_offset'] ?? 0), $size - 4096));
            return trim((string)stream_get_contents($handle, 4096));
        } finally { fclose($handle); }
    }

    private function appendLog(string $name, string $line): void
    {
        $file = $this->slots[$name]['log'] ?? $this->logFile($name);
        @file_put_contents($file, date('Y-m-d H:i:s') . ' ' . $line . PHP_EOL, FILE_APPEND);
    }
}
