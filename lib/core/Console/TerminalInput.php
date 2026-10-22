<?php

namespace MajorDoMo\Core\Console;

/** Secret input never falls back to echoing the password or writing it to disk. */
final class TerminalInput
{
    public function __construct(private mixed $stdin = STDIN, private mixed $stderr = STDERR, private ?\Closure $hiddenReader = null) {}

    public function isInteractive(): bool
    {
        return $this->hiddenReader !== null || (function_exists('stream_isatty') && stream_isatty($this->stdin));
    }

    public function passwordFromStdin(): string
    {
        if ($this->isInteractive()) throw new \InvalidArgumentException('--password-stdin предназначен для перенаправленного stdin. В терминале используйте скрытый ввод без этого флага.');
        return $this->readLine();
    }

    public function secret(string $prompt): string
    {
        if (!$this->isInteractive()) throw new \InvalidArgumentException('Нужен интерактивный терминал для скрытого ввода или --password-stdin для чтения из pipe.');
        if ($this->hiddenReader !== null) return ($this->hiddenReader)($prompt);
        fwrite($this->stderr, '  ' . $prompt . ': ');
        try {
            return PHP_OS_FAMILY === 'Windows' ? $this->windowsSecret() : $this->unixSecret();
        } finally { fwrite($this->stderr, PHP_EOL); }
    }

    private function readLine(): string
    {
        $value = fgets($this->stdin, 1027);
        if ($value === false) throw new \InvalidArgumentException('Пароль не получен из stdin.');
        if (str_ends_with($value, "\n")) $value = substr($value, 0, -1);
        if (str_ends_with($value, "\r")) $value = substr($value, 0, -1);
        if (strlen($value) > 1024) throw new \InvalidArgumentException('Пароль длиннее 1024 байт.');
        return $value;
    }

    private function windowsSecret(): string
    {
        // ReadKey(true) reads directly from the inherited Windows console even
        // though stdout is a pipe. The password is never part of the command.
        $script = <<<'PS'
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$value = New-Object System.Text.StringBuilder
while ($true) {
    $key = [Console]::ReadKey($true)
    if ($key.Key -eq [ConsoleKey]::Enter) { break }
    if ($key.Key -eq [ConsoleKey]::Escape) { exit 2 }
    if ($key.Key -eq [ConsoleKey]::Backspace) {
        if ($value.Length -gt 0) { $null = $value.Remove($value.Length - 1, 1) }
    } elseif (-not [char]::IsControl($key.KeyChar)) {
        $null = $value.Append($key.KeyChar)
        if ($value.Length -gt 1024) { exit 2 }
    }
}
[Console]::Write($value.ToString())
PS;
        $process = proc_open(array('powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script), array(0 => $this->stdin, 1 => array('pipe', 'w'), 2 => $this->stderr), $pipes, null, null, array('bypass_shell' => true));
        if (!is_resource($process)) throw new \RuntimeException('Не удалось включить скрытый ввод PowerShell. Используйте --password-stdin.');
        try { $password = stream_get_contents($pipes[1], 8193); }
        finally { fclose($pipes[1]); $code = proc_close($process); }
        if ($code !== 0 || $password === false || strlen($password) > 1024) throw new \RuntimeException('Скрытый ввод отменён или недоступен. Используйте --password-stdin.');
        return $password;
    }

    private function unixSecret(): string
    {
        $state = trim($this->stty(array('-g')));
        if ($state === '') throw new \RuntimeException('Не удалось прочитать состояние терминала. Используйте --password-stdin.');
        $signals = array();
        $async = null;
        if (function_exists('pcntl_signal') && function_exists('pcntl_signal_get_handler') && function_exists('pcntl_async_signals')) {
            $async = pcntl_async_signals(true);
            foreach (array(SIGINT, SIGTERM, SIGHUP) as $signal) {
                $signals[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, static function () { throw new \RuntimeException('Ввод пароля отменён.'); });
            }
        }
        try {
            $this->stty(array('-echo'));
            return $this->readLine();
        } finally {
            try { $this->stty(array($state)); }
            finally {
                foreach ($signals as $signal => $handler) pcntl_signal($signal, $handler);
                if ($async !== null) pcntl_async_signals($async);
            }
        }
    }

    private function stty(array $arguments): string
    {
        $process = proc_open(array_merge(array('stty'), $arguments), array(0 => $this->stdin, 1 => array('pipe', 'w'), 2 => $this->stderr), $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Не удалось запустить stty для скрытого ввода.');
        try { $result = stream_get_contents($pipes[1]); }
        finally { fclose($pipes[1]); $code = proc_close($process); }
        if ($code !== 0 || $result === false) throw new \RuntimeException('Скрытый ввод недоступен. Используйте --password-stdin.');
        return $result;
    }
}
