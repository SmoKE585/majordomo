<?php

namespace MajorDoMo\Core\Console;

/** Existing isolated PHP tools; argument arrays and file streams work on Windows too. */
final class PhpToolRunner
{
    public function run(string $file, array $arguments, string $directory): array
    {
        if (!is_file($file)) throw new \RuntimeException('Не найден инструмент: ' . basename($file));
        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) throw new \RuntimeException('Не удалось создать временные потоки');
        $process = null;
        try {
            $process = proc_open(array_merge(array(PHP_BINARY, $file), $arguments), array(0 => array('pipe', 'r'), 1 => $stdout, 2 => $stderr), $pipes, $directory, null, array('bypass_shell' => true));
            if (!is_resource($process)) throw new \RuntimeException('Не удалось запустить PHP-инструмент');
            fclose($pipes[0]);
            $deadline = microtime(true) + 60;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) break;
                if (microtime(true) >= $deadline) {
                    proc_terminate($process);
                    usleep(100000);
                    if (proc_get_status($process)['running']) proc_terminate($process, 9);
                    throw new \RuntimeException('PHP-инструмент превысил таймаут 60 секунд');
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
            if (is_resource($process)) proc_close($process);
            fclose($stdout);
            fclose($stderr);
        }
    }
}
