<?php

namespace MajorDoMo\Core\Console;

/** Bounded initial tail; following reopens by path to handle rotation/truncation. */
final class LogTail
{
    public function lastLines(string $path, int $lines): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new \RuntimeException('Не удалось открыть журнал');
        try {
            $stat = fstat($handle);
            $end = $stat['size'];
            $position = $end;
            $buffer = '';
            $limit = 4 * 1024 * 1024;
            while ($position > 0 && substr_count($buffer, "\n") <= $lines && strlen($buffer) < $limit) {
                $length = min(8192, $position);
                $position -= $length;
                fseek($handle, $position);
                $chunk = fread($handle, $length);
                if ($chunk === false) throw new \RuntimeException('Не удалось прочитать журнал');
                $buffer = $chunk . $buffer;
            }
            $truncated = $position > 0 && substr_count($buffer, "\n") <= $lines;
            $rows = $buffer === '' ? array() : explode("\n", rtrim($buffer, "\n"));
            return array('lines' => array_slice($rows, -$lines), 'offset' => $end, 'identity' => $stat['dev'] . ':' . $stat['ino'], 'truncated' => $truncated);
        } finally { fclose($handle); }
    }

    public function follow(callable $path, int $offset, string $identity, Output $output): void
    {
        $current = $path();
        while (true) {
            $next = $path();
            if ($next !== $current) { $offset = 0; $identity = ''; $current = $next; }
            clearstatcache(true, $current);
            if (is_file($current)) {
                $handle = fopen($current, 'rb');
                if ($handle === false) throw new \RuntimeException('Не удалось открыть журнал');
                try {
                    $stat = fstat($handle);
                    $id = $stat['dev'] . ':' . $stat['ino'];
                    if ($id !== $identity || $stat['size'] < $offset) $offset = 0;
                    $identity = $id;
                    if (fseek($handle, $offset) !== 0) throw new \RuntimeException('Не удалось перейти к позиции журнала');
                    // Bounded reads keep a busy writer from monopolising the loop.
                    $chunk = stream_get_contents($handle, 65536);
                    if ($chunk === false) throw new \RuntimeException('Не удалось прочитать журнал');
                    $offset = ftell($handle);
                    if ($chunk !== '') $output->write($chunk);
                } finally { fclose($handle); }
            }
            usleep(200000);
        }
    }
}
