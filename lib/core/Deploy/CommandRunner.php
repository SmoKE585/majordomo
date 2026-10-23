<?php

namespace MajorDoMo\Core\Deploy;

/**
 * Запуск внешних команд деплоя без shell и с ограничением времени.
 *
 * 2026-10-23 — потоковый вывод строк и пульс: во время composer/npm журнал
 *              обновления больше не молчит минутами.
 * 2026-10-22 — добавлено для composer/npm/systemctl в пайплайне обновления.
 */
final class CommandRunner
{
    public function __construct(private string $root, private int $outputLimit = 12000)
    {
        $this->root = rtrim($root, '/\\');
    }

    /**
     * @param string[] $command
     * @param array<string,string> $environment
     * @param callable|null $onOutput function(string $line, bool $pulse = false): void
     *        Пульс приходит, когда команда молчит дольше $heartbeat секунд: по нему
     *        видно, что процесс жив, а не завис.
     * @return array{ok:bool,code:int,out:string,err:string,timed_out:bool,duration_ms:int,command:string}
     */
    public function run(array $command, int $timeout = 600, array $environment = array(), ?callable $onOutput = null, int $heartbeat = 0): array
    {
        $started = microtime(true);
        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $env = null;
        if ($environment !== array()) {
            $current = getenv();
            $env = is_array($current) ? array_merge($current, $environment) : $environment;
        }
        $pipes = array();
        $process = @proc_open($command, $descriptors, $pipes, $this->root, $env);
        if (!is_resource($process)) {
            return array(
                'ok' => false, 'code' => 127, 'out' => '', 'err' => 'Не удалось запустить команду: ' . implode(' ', $command),
                'timed_out' => false, 'duration_ms' => 0, 'command' => implode(' ', $command),
            );
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $timedOut = false;
        $status = array('running' => true, 'exitcode' => -1);
        $stdoutBuffer = '';
        $stderrBuffer = '';
        $lastEmit = microtime(true);
        $emit = static function (string $line, bool $pulse = false) use ($onOutput, &$lastEmit): void {
            if ($onOutput === null) {
                return;
            }
            $lastEmit = microtime(true);
            $onOutput($line, $pulse);
        };
        while (true) {
            $status = proc_get_status($process);
            $read = array($pipes[1], $pipes[2]);
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) !== false) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false || $chunk === '') continue;
                    if ($stream === $pipes[1]) {
                        $out .= $chunk;
                        $stdoutBuffer = $this->emitLines($stdoutBuffer . $chunk, $emit);
                    } else {
                        $err .= $chunk;
                        $stderrBuffer = $this->emitLines($stderrBuffer . $chunk, $emit);
                    }
                }
            }
            if (!$status['running']) break;
            if ($heartbeat > 0 && microtime(true) - $lastEmit >= $heartbeat) {
                $emit('выполняется ' . $this->elapsedText(microtime(true) - $started), true);
            }
            if (microtime(true) - $started > $timeout) {
                $timedOut = true;
                @proc_terminate($process, 9);
                break;
            }
        }
        foreach (array($pipes[1], $pipes[2]) as $stream) {
            if (!is_resource($stream)) continue;
            $chunk = stream_get_contents($stream);
            if ($chunk !== false && $chunk !== '') {
                if ($stream === $pipes[1]) $out .= $chunk;
                else $err .= $chunk;
            }
            fclose($stream);
        }
        foreach (array($stdoutBuffer, $stderrBuffer) as $rest) {
            $rest = trim((string)$rest);
            if ($rest !== '') {
                $emit(mb_substr($rest, 0, 300), false);
            }
        }
        $code = (int)($status['exitcode'] ?? -1);
        if ($code === -1) {
            $closed = proc_close($process);
            $code = $closed >= 0 ? $closed : 1;
        } else {
            proc_close($process);
        }
        return array(
            'ok' => !$timedOut && $code === 0,
            'code' => $code,
            'out' => mb_substr($out, -$this->outputLimit),
            'err' => mb_substr($err, -$this->outputLimit),
            'timed_out' => $timedOut,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'command' => implode(' ', $command),
        );
    }

    /** Найти исполняемый файл в PATH без shell. */
    public function find(string $binary): string
    {
        if ($binary === '') return '';
        if (str_contains($binary, '/') || str_contains($binary, '\\')) return is_file($binary) ? $binary : '';
        $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
        $extensions = array('');
        if (DIRECTORY_SEPARATOR === '\\') $extensions = array('.exe', '.bat', '.cmd', '');
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $binary . $extension;
                if (is_file($candidate) && (is_executable($candidate) || (DIRECTORY_SEPARATOR === '\\' && preg_match('/\.(exe|bat|cmd)$/i', $candidate)))) {
                    return $candidate;
                }
            }
        }
        return '';
    }

    /**
     * Разбить накопленный вывод на строки и отдать готовые.
     *
     * Composer и npm пишут прогресс через `\r`, поэтому перевод каретки тоже
     * считается концом строки: иначе весь прогресс склеивается в одну строку.
     *
     * @param callable $emit function(string $line, bool $pulse = false): void
     * @return string Незавершённый хвост, который нужно дочитать.
     */
    private function emitLines(string $buffer, callable $emit): string
    {
        $normalized = str_replace(array("\r\n", "\r"), "\n", $buffer);
        $parts = explode("\n", $normalized);
        $rest = (string)array_pop($parts);
        foreach ($parts as $line) {
            $line = trim($line);
            if ($line === '' || strlen($line) > 2000) {
                continue;
            }
            $emit($line, false);
        }
        return $rest;
    }

    private function elapsedText(float $seconds): string
    {
        $seconds = (int)round($seconds);
        if ($seconds < 60) {
            return $seconds . ' с';
        }
        return (int)floor($seconds / 60) . ' мин ' . ($seconds % 60) . ' с';
    }

    /** @return array{ok:bool,version:string,raw:array<string,mixed>} */
    public function version(string $command, string $argument = '--version', int $timeout = 30): array
    {
        $result = $this->run(array($command, $argument), $timeout);
        $first = trim(strtok($result['out'], "\n") ?: '');
        return array('ok' => $result['ok'], 'version' => $first, 'raw' => $result);
    }
}
