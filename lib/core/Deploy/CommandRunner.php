<?php

namespace MajorDoMo\Core\Deploy;

/**
 * Запуск внешних команд деплоя без shell и с ограничением времени.
 *
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
     * @return array{ok:bool,code:int,out:string,err:string,timed_out:bool,duration_ms:int,command:string}
     */
    public function run(array $command, int $timeout = 600, array $environment = array()): array
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
        while (true) {
            $status = proc_get_status($process);
            $read = array($pipes[1], $pipes[2]);
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) !== false) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false || $chunk === '') continue;
                    if ($stream === $pipes[1]) $out .= $chunk;
                    else $err .= $chunk;
                }
            }
            if (!$status['running']) break;
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

    /** @return array{ok:bool,version:string,raw:array<string,mixed>} */
    public function version(string $command, string $argument = '--version', int $timeout = 30): array
    {
        $result = $this->run(array($command, $argument), $timeout);
        $first = trim(strtok($result['out'], "\n") ?: '');
        return array('ok' => $result['ok'], 'version' => $first, 'raw' => $result);
    }
}
