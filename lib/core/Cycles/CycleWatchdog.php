<?php

namespace MajorDoMo\Core\Cycles;

/** Health deadlines and bounded restart backoff, independent of database availability. */
final class CycleWatchdog
{
    private array $failures = array();
    private array $healthySince = array();

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

    /** Keep trying indefinitely; repeated failures produce a critical incident and a slower retry. */
    public function failure(string $name, int $now): array
    {
        unset($this->healthySince[$name]);
        $recent = array_filter($this->failures[$name] ?? array(), static fn($timestamp) => $now - $timestamp <= 600);
        $recent[] = $now;
        $this->failures[$name] = array_slice($recent, -32);
        $count = count($this->failures[$name]);
        return array('count' => $count, 'critical' => $count >= 3, 'delay' => min(60, 2 ** min($count, 6)));
    }
}
