<?php

namespace MajorDoMo\Core\Console;

final class Output
{
    private array $messages = array();
    private array $data = array();
    private ?string $error = null;
    private bool $json = false;

    public function __construct(private mixed $stdout, private mixed $stderr) {}
    public function setJson(bool $json): void { $this->json = $json; }
    public function isJson(): bool { return $this->json; }
    public function result(array $data): void { $this->data = $data; }
    public function write(string $text): void { fwrite($this->stdout, $text); }

    public function line(string $message = ''): void
    {
        if ($this->json) $this->messages[] = $message;
        else fwrite($this->stdout, $message . PHP_EOL);
    }

    public function error(string $message): void
    {
        $this->error = $message;
        if (!$this->json) fwrite($this->stderr, 'Ошибка: ' . $message . PHP_EOL);
    }

    public function table(array $headers, array $rows): void
    {
        if ($this->json) return;
        $rows = array_merge(array($headers), $rows);
        $widths = array_fill(0, count($headers), 0);
        foreach ($rows as $row) foreach ($row as $i => $cell) $widths[$i] = max($widths[$i], $this->width((string)$cell));
        foreach ($rows as $row) {
            $cells = array();
            foreach ($row as $i => $cell) {
                $cell = str_replace(array("\r", "\n", "\t", "\033"), ' ', (string)$cell);
                $cells[] = $cell . str_repeat(' ', max(0, $widths[$i] - $this->width($cell)));
            }
            $this->line(rtrim(implode('  ', $cells)));
        }
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
