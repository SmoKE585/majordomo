<?php

namespace MajorDoMo\Core\Cycles;

/**
 * Дедлайны здоровья и три попытки восстановления без зависимости от БД.
 * 2026-10-25 — паузы 5/15/30 секунд; после третьего повтора нужен ручной запуск.
 */
final class CycleWatchdog
{
    private array $failures = array();
    private array $healthySince = array();
    private array $generations = array();

    public function __construct(private int $heartbeatTimeout = 120, private int $startupTimeout = 180, private array $overrides = array(), private int $jobExecutionTimeout = 300) {}

    public function timeout(string $name, bool $startup = false): int
    {
        $default = $startup ? $this->startupTimeout : $this->heartbeatTimeout;
        // A scheduler job has its own enforced deadline; allow it to finish before
        // declaring the parent stalled. Progress between jobs refreshes its heartbeat.
        if ($name === 'cycle_scheduler') $default = max($default, max(1, $this->jobExecutionTimeout) + 60);
        return max(10, (int)($this->overrides[$name] ?? $default));
    }

    public function stalled(string $name, int $started, int $heartbeat, int $now): ?string
    {
        if ($heartbeat < $started || $heartbeat > $now + 5) {
            if ($now - $started < $this->timeout($name, true)) return null;
            unset($this->healthySince[$name]);
            return 'No heartbeat since process start (' . ($now - $started) . 's)';
        }
        if ($now - $heartbeat >= $this->timeout($name)) {
            unset($this->healthySince[$name]);
            return 'Heartbeat stalled for ' . ($now - $heartbeat) . 's; last heartbeat: ' . date('Y-m-d H:i:s', $heartbeat);
        }
        $this->healthySince[$name] ??= $now;
        if ($now - $this->healthySince[$name] >= 600) unset($this->failures[$name]);
        return null;
    }

    public function reset(string $name): void
    {
        unset($this->failures[$name], $this->healthySince[$name]);
    }

    /** Первичный сбой даёт три повтора; четвёртый сбой исчерпывает восстановление. */
    public function failure(string $name, int $now, int $generation = 0): array
    {
        if (($this->generations[$name] ?? $generation) !== $generation) $this->reset($name);
        $this->generations[$name] = $generation;
        unset($this->healthySince[$name]);
        $count = min(5, (int)($this->failures[$name] ?? 0) + 1);
        $this->failures[$name] = $count;
        return array('count' => $count, 'critical' => $count >= 4, 'notify' => $count === 4,
            'delay' => array(1 => 5, 2 => 15, 3 => 30)[$count] ?? 0);
    }
}
