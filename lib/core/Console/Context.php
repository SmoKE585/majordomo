<?php

namespace MajorDoMo\Core\Console;

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Cycles\CycleManager;

final class Context
{
    /** Optional reader is for isolated CLI tests and embedded callers. */
    public function __construct(public readonly string $root, public readonly Application $application, private ?\Closure $runtimeReader = null) {}

    public function config(): void { Config::boot($this->root); }

    public function cycles(bool $runtime = false): CycleManager
    {
        $this->config();
        $reader = null;
        if ($runtime) {
            $values = $this->runtimeReader !== null ? ($this->runtimeReader)() : (new CycleRuntimeReader())->read();
            $reader = static fn(string $key) => $values[strtolower($key)] ?? false;
        }
        return new CycleManager($this->root, null, $reader);
    }

    public function cycleName(string $value, CycleManager $manager): string
    {
        if (!preg_match('/^(?:cycle_)?[A-Za-z0-9_]+$/D', $value)) throw new \InvalidArgumentException('Недопустимое имя цикла');
        $name = str_starts_with($value, 'cycle_') ? $value : 'cycle_' . $value;
        if (!$manager->exists($name)) throw new \InvalidArgumentException('Цикл не найден: ' . $value);
        return $name;
    }
}
