<?php

namespace MajorDoMo\Core\Console;

final class Output
{
    private array $messages = array();
    private array $data = array();
    private ?string $error = null;
    private bool $json = false;
    private ?bool $ansi = null;
    private int $columns;

    public function __construct(private mixed $stdout, private mixed $stderr, ?int $columns = null)
    {
        $terminalColumns = getenv('COLUMNS');
        $this->columns = max(40, min(200, $columns ?? (ctype_digit((string)$terminalColumns) ? (int)$terminalColumns : 80)));
    }
    public function setJson(bool $json): void { $this->json = $json; }
    public function setAnsi(?bool $ansi): void { $this->ansi = $ansi; }
    public function isJson(): bool { return $this->json; }
    public function result(array $data): void { $this->data = $data; }
    public function write(string $text): void { fwrite($this->stdout, $text); }

    public function line(string $message = ''): void
    {
        if ($this->json) $this->messages[] = $message;
        else fwrite($this->stdout, $this->clean($message) . PHP_EOL);
    }

    public function title(string $text): void { $this->formatted($text, '1;36', true); }
    public function section(string $text): void { $this->formatted($text, '1;33', true); }
    public function muted(string $text): void { $this->formatted($text, '90'); }
    public function success(string $text): void { $this->status('OK', $text); }
    public function warning(string $text): void { $this->status('WARN', $text); }
    public function info(string $text): void { $this->status('INFO', $text); }

    private function formatted(string $text, string $style, bool $space = false): void
    {
        if ($this->json) { $this->messages[] = $text; return; }
        if ($space) fwrite($this->stdout, PHP_EOL);
        foreach ($this->wrap($this->clean($text), $this->columns - 4) as $line) {
            fwrite($this->stdout, '  ' . $this->paint($line, $style, $this->stdout) . PHP_EOL);
        }
    }

    public function status(string $status, string $text): void
    {
        $status = strtoupper($status);
        if ($status === 'FAIL') $status = 'ERROR';
        if ($this->json) { $this->messages[] = '[' . $status . '] ' . $text; return; }
        $label = '[' . $status . ']';
        $lines = $this->wrap($this->clean($text), max(10, $this->columns - 11));
        foreach ($lines as $i => $line) {
            fwrite($this->stdout, '  ' . ($i === 0 ? $this->paint(str_pad($label, 7), $this->statusStyle($status), $this->stdout) : '       ') . ' ' . $line . PHP_EOL);
        }
    }

    public function error(string $message): void
    {
        $this->error = $message;
        if (!$this->json) {
            fwrite($this->stderr, PHP_EOL . '  ' . $this->paint('[ERROR]', '1;31', $this->stderr) . ' ' . $this->clean($message) . PHP_EOL . PHP_EOL);
        }
    }

    public function table(array $headers, array $rows, bool $showHeader = true): void
    {
        if ($this->json) return;
        if ($rows === array()) { $this->muted('Нет записей.'); return; }
        $rows = array_map(fn(array $row) => array_map(fn($cell) => str_replace(array("\r", "\n", "\t"), ' ', $this->clean((string)$cell)), $row), $rows);
        $widths = array_fill(0, count($headers), 0);
        foreach (array_merge(array($headers), $rows) as $row) foreach ($row as $i => $cell) $widths[$i] = max($widths[$i], $this->width((string)$cell));
        $available = $this->columns - 4 - 2 * (count($headers) - 1);
        while (array_sum($widths) > $available) {
            $largest = array_search(max($widths), $widths, true);
            if ($widths[$largest] <= 2) break;
            $widths[$largest]--;
        }
        if ($showHeader) {
            $this->tableRow($headers, $widths, true);
            fwrite($this->stdout, '  ' . $this->paint(implode('  ', array_map(static fn($width) => str_repeat('─', $width), $widths)), '90', $this->stdout) . PHP_EOL);
        }
        foreach ($rows as $row) $this->tableRow($row, $widths, false);
    }

    private function tableRow(array $row, array $widths, bool $header): void
    {
        $wrapped = array();
        foreach ($row as $i => $cell) $wrapped[$i] = $this->wrap((string)$cell, $widths[$i]);
        $height = max(array_map('count', $wrapped));
        for ($line = 0; $line < $height; $line++) {
            $cells = array();
            foreach ($wrapped as $i => $parts) {
                $cell = $parts[$line] ?? '';
                $padding = str_repeat(' ', max(0, $widths[$i] - $this->width($cell)));
                $style = $header ? '1' : ($i === 0 ? '36' : $this->statusStyle((string)$row[$i]));
                $cells[] = $this->paint($cell, $style, $this->stdout) . $padding;
            }
            fwrite($this->stdout, '  ' . rtrim(implode('  ', $cells)) . PHP_EOL);
        }
    }

    private function wrap(string $text, int $width): array
    {
        $lines = array();
        $line = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: array() as $character) {
            if ($this->width($line . $character) > $width && $line !== '') {
                $space = strrpos($line, ' ');
                if ($space !== false && $space > 0) {
                    $lines[] = substr($line, 0, $space);
                    $line = ltrim(substr($line, $space + 1));
                } else { $lines[] = $line; $line = ''; }
            }
            $line .= $character;
        }
        $lines[] = $line;
        return $lines;
    }

    private function clean(string $text): string
    {
        // Strip complete escape sequences before styling, including OSC links.
        $text = preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/', '', $text) ?? '';
        $text = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $text) ?? '';
        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text) ?? '';
    }

    private function statusStyle(string $status): string
    {
        return match (strtolower($status)) {
            'ok', 'running', 'yes' => '32',
            'warn', 'warning', 'unknown', 'starting', 'stopping' => '33',
            'error', 'fail', 'hang', 'critical' => '1;31',
            'disabled', 'stopped', 'no' => '90',
            'info' => '36',
            default => '',
        };
    }

    private function paint(string $text, string $style, mixed $stream): string
    {
        $text = $this->clean($text);
        if ($this->json || $style === '' || $this->ansi === false) return $text;
        $terminal = function_exists('stream_isatty') && stream_isatty($stream);
        $enabled = $this->ansi === true || ($terminal && getenv('NO_COLOR') === false && getenv('TERM') !== 'dumb');
        if ($enabled && PHP_OS_FAMILY === 'Windows' && $terminal && function_exists('sapi_windows_vt100_support')) {
            $enabled = sapi_windows_vt100_support($stream, true);
        }
        return $enabled ? "\033[" . $style . 'm' . $text . "\033[0m" : $text;
    }

    private function width(string $text): int
    {
        return function_exists('mb_strwidth') ? mb_strwidth($text, 'UTF-8') : preg_match_all('/./us', $text);
    }

    public function finish(int $code): int
    {
        if ($this->json) {
            fwrite($this->stdout, json_encode(array(
                'ok' => $code === 0, 'data' => $this->data,
                'messages' => $this->messages, 'error' => $this->error,
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
        }
        return $code;
    }
}
