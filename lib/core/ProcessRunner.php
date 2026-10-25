<?php

namespace MajorDoMo\Core;


/**
 * Изолированный процесс на задание с таймаутом и предварительной загрузкой ядра.
 * 2026-10-25 — остановка активного задания и его группы процессов; отдельный дедлайн дочернего исполнителя.
 * 2026-10-25 — следующий исполнитель готовится заранее; пользовательские задания не делят процесс.
 */
final class ProcessRunner
{
    private ?array $prepared = null;
    private ?array $active = null;

    public function __construct(private ?string $root = null) {}

    /** Подготовленный процесс ждёт одно задание; настройки читает после его получения. */
    public function prepare(): bool
    {
        if ($this->prepared !== null) {
            $this->readOutput($this->prepared);
            if (proc_get_status($this->prepared['process'])['running']) return true;
            $this->closeProcess($this->prepared);
            $this->prepared = null;
        }
        $root = $this->root ?? ROOT;
        if (!is_file($root . 'scripts/core_worker.php')) return false;
        $this->prepared = $this->start(array('--standby'));
        return $this->prepared !== null;
    }

    private function start(array $arguments): ?array
    {
        $root = $this->root ?? ROOT;
        $command = array_merge(array(defined('PATH_TO_PHP') ? PATH_TO_PHP : PHP_BINARY, $root . 'scripts/core_worker.php'), $arguments);
        $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root);
        if (!is_resource($process)) return null;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return array('process' => $process, 'pipes' => $pipes, 'output' => '');
    }

    private function readOutput(array &$slot): void
    {
        $slot['output'] = substr($slot['output'] . stream_get_contents($slot['pipes'][1]), -65536);
        $errors = stream_get_contents($slot['pipes'][2]);
        if ($errors !== '') DebMes('Worker stderr: ' . substr($errors, -4096), 'core_worker', 'error');
    }

    private function closeProcess(array $slot, bool $terminate = false): void
    {
        $status = proc_get_status($slot['process']);
        $pid = (int)$status['pid'];
        $group = (int)($slot['group'] ?? 0);
        if ($terminate && !$group && !empty($status['running']) && function_exists('posix_getpgid') && posix_getpgid($pid) === $pid) $group = $pid;
        if ($terminate && $group > 0) posix_kill(-$group, 15);
        if ($terminate && proc_get_status($slot['process'])['running']) {
            proc_terminate($slot['process']);
            usleep(100000);
            if (proc_get_status($slot['process'])['running']) {
                proc_terminate($slot['process'], 9);
            }
        }
        if ($terminate && $group > 0) posix_kill(-$group, 9);
        foreach ($slot['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($slot['process']);
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function stop(): void
    {
        foreach (array('active', 'prepared') as $name) {
            $slot = $this->{$name};
            $this->{$name} = null;
            if ($slot !== null) $this->closeProcess($slot, true);
        }
    }

    public function run($kind, $id, $token, $timeout = 300)
    {
        if ($this->prepared !== null && !proc_get_status($this->prepared['process'])['running']) {
            $this->closeProcess($this->prepared);
            $this->prepared = null;
        }
        if ($kind === 'operation' && $this->prepared !== null) {
            $slot = $this->prepared;
            $this->prepared = null;
            $message = json_encode(array('kind' => $kind, 'id' => (string)$id, 'token' => (string)$token, 'timeout' => max(1, (int)$timeout)), JSON_THROW_ON_ERROR) . "\n";
            $offset = 0;
            set_error_handler(static function ($severity, $message) { throw new \RuntimeException($message); });
            try {
                while ($offset < strlen($message)) {
                    $written = fwrite($slot['pipes'][0], substr($message, $offset));
                    if (!$written) throw new \RuntimeException('Prepared worker did not accept the operation');
                    $offset += $written;
                }
            } catch (\Throwable $error) {
                $this->closeProcess($slot, true);
                return array('success' => false, 'error' => 'Prepared worker request failed: ' . $error->getMessage());
            } finally {
                restore_error_handler();
            }
            fclose($slot['pipes'][0]);
            // Загрузка следующего ядра перекрывается с работой текущего задания.
            // Следующий процесс ещё не выполняет пользовательский код.
            $this->prepare();
        } else {
            $slot = $this->start(array($kind, (string)$id, (string)$token, (string)max(1, (int)$timeout)));
            if ($slot === null) return array('success' => false, 'error' => 'Cannot start worker process');
            fclose($slot['pipes'][0]);
        }
        $this->active = $slot;
        $deadline = microtime(true) + max(1, (int)$timeout);
        do {
            $this->readOutput($slot);
            $status = proc_get_status($slot['process']);
            if (!empty($status['running']) && function_exists('posix_getpgid') && posix_getpgid((int)$status['pid']) === (int)$status['pid']) {
                $slot['group'] = (int)$status['pid'];
                $this->active['group'] = $slot['group'];
            }
            if (!$status['running']) break;
            if (microtime(true) >= $deadline) {
                $this->closeProcess($slot, true);
                $this->active = null;
                return array('success' => false, 'error' => 'Worker execution timeout');
            }
            // Linux будит родителя при появлении результата, без задержки опроса.
            $read = array_values(array_filter(array($slot['pipes'][1], $slot['pipes'][2]), static fn($pipe) => !feof($pipe)));
            if ($read) {
                $write = $except = null;
                stream_select($read, $write, $except, 0, (int)max(1, min(100000, ($deadline - microtime(true)) * 1000000)));
            } else usleep(1000);
        } while (true);
        $this->readOutput($slot);
        $this->closeProcess($slot, ($status['exitcode'] ?? null) === 124);
        $this->active = null;
        if (($status['exitcode'] ?? null) === 124) return array('success' => false, 'error' => 'Worker execution timeout');
        if (preg_match('/^MJD_WORKER_RESULT (\{[^\r\n]*\})\r?$/m', $slot['output'], $match)) {
            $result = json_decode($match[1], true);
            if (is_array($result) && array_key_exists('success', $result)) return $result;
        }
        return array('success' => false, 'error' => 'Worker exited without an execution result');
    }
}
