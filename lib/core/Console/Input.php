<?php

namespace MajorDoMo\Core\Console;

final class Input
{
    private array $arguments = array();
    private array $options = array();

    public function __construct(array $tokens, Command $command)
    {
        $definitions = array('help' => false, 'json' => false, 'ansi' => false, 'no-ansi' => false, 'verbose' => false);
        foreach ($command->options() as $name => $description) {
            $definitions[rtrim($name, '=')] = str_ends_with($name, '=');
        }
        $positional = array();
        $literal = false;
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (!$literal && $token === '--') { $literal = true; continue; }
            if (!$literal && ($token === '-h' || $token === '-?')) $token = '--help';
            if (!$literal && str_starts_with($token, '-')) {
                if (!str_starts_with($token, '--')) throw new \InvalidArgumentException('Неизвестная опция: ' . $token);
                $parts = explode('=', substr($token, 2), 2);
                $name = $parts[0];
                if (!array_key_exists($name, $definitions)) throw new \InvalidArgumentException('Неизвестная опция: --' . $name);
                if (array_key_exists($name, $this->options)) throw new \InvalidArgumentException('Опция повторяется: --' . $name);
                if ($definitions[$name]) {
                    $value = $parts[1] ?? null;
                    if ($value === null && isset($tokens[$i + 1]) && !str_starts_with($tokens[$i + 1], '--')) $value = $tokens[++$i];
                    if ($value === null || $value === '') throw new \InvalidArgumentException('Укажите значение --' . $name);
                    $this->options[$name] = $value;
                } else {
                    if (isset($parts[1])) throw new \InvalidArgumentException('Опция --' . $name . ' не принимает значение');
                    $this->options[$name] = true;
                }
            } else {
                $positional[] = $token;
            }
        }
        if (count($positional) > count($command->arguments())) throw new \InvalidArgumentException('Слишком много аргументов');
        foreach (array_keys($command->arguments()) as $index => $name) {
            $key = rtrim($name, '?');
            $this->arguments[$key] = $positional[$index] ?? null;
            if ($this->arguments[$key] === null && !str_ends_with($name, '?') && !$this->option('help')) {
                throw new \InvalidArgumentException('Не указан аргумент: ' . $key);
            }
        }
    }

    public function argument(string $name, ?string $default = null): ?string { return $this->arguments[$name] ?? $default; }
    public function option(string $name, mixed $default = false): mixed { return $this->options[$name] ?? $default; }

    public function integer(string $name, int $default, int $min, int $max): int
    {
        $value = $this->option($name, (string)$default);
        if (!preg_match('/^\d+$/D', (string)$value) || (float)$value < $min || (float)$value > $max) {
            throw new \InvalidArgumentException('--' . $name . ': допустимо от ' . $min . ' до ' . $max);
        }
        return (int)$value;
    }
}
