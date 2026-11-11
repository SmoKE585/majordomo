<?php

namespace MajorDoMo\Core\Console;

/**
 * Изолированные PHP-команды с ограничением времени и захватом вывода.
 * 2026-11-11 — heartbeat родителя и завершение группы процессов обслуживающего задания.
 */
final class PhpToolRunner
{
    public function run(string $file, array $arguments, string $directory, int $timeout = 60, ?callable $heartbeat = null): array
    {
        if (!is_file($file)) throw new \RuntimeException('Не найден инструмент: ' . basename($file));
        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) {
            if (is_resource($stdout)) fclose($stdout);
            if (is_resource($stderr)) fclose($stderr);
            throw new \RuntimeException('Не удалось создать временные потоки');
        }
        $process = null;
        $group = 0;
        try {
            $process = proc_open(array_merge(array(PHP_BINARY, $file), $arguments), array(0 => array('pipe', 'r'), 1 => $stdout, 2 => $stderr), $pipes, $directory, null, array('bypass_shell' => true));
            if (!is_resource($process)) throw new \RuntimeException('Не удалось запустить PHP-инструмент');
            fclose($pipes[0]);
            $deadline = microtime(true) + max(1, $timeout);
            do {
                $status = proc_get_status($process);
                if ($status['running'] && function_exists('posix_getpgid') && posix_getpgid((int)$status['pid']) === (int)$status['pid']) $group = (int)$status['pid'];
                if (!$status['running']) break;
                if ($heartbeat) $heartbeat();
                if (microtime(true) >= $deadline) {
                    proc_terminate($process);
                    usleep(100000);
                    if (proc_get_status($process)['running']) proc_terminate($process, 9);
                    throw new \RuntimeException('PHP-инструмент превысил таймаут ' . $timeout . ' секунд');
                }
                usleep(20000);
            } while (true);
            $code = $status['exitcode'];
            $closed = proc_close($process);
            $process = null;
            if ($code < 0) $code = $closed;
            rewind($stdout);
            rewind($stderr);
            $out = stream_get_contents($stdout, 4 * 1024 * 1024 + 1);
            $err = stream_get_contents($stderr, 65537);
            if (strlen($out) > 4 * 1024 * 1024 || strlen($err) > 65536) throw new \RuntimeException('Превышен лимит вывода PHP-инструмента');
            return array('code' => $code, 'stdout' => $out, 'stderr' => $err);
        } finally {
            if (is_resource($process)) {
                if ($group > 0) posix_kill(-$group, 15);
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                    usleep(100000);
                    if (proc_get_status($process)['running']) proc_terminate($process, 9);
                }
                if ($group > 0) posix_kill(-$group, 9);
                proc_close($process);
            }
            fclose($stdout);
            fclose($stderr);
        }
    }
}
