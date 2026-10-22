<?php

namespace MajorDoMo\Core\Cycles;

use MajorDoMo\Core\Core;
use MajorDoMo\Core\Config;

/**
 * Supervisor-side reconciler.
 *
 * `cycle.php` owns the child processes. This class is deliberately small: it
 * translates the persistent desired state into the existing start/stop queues,
 * without touching the legacy process management code that old cycles rely on.
 */
final class CycleSupervisor
{
    private CycleStateStore $store;
    /** @var array<string,int> */
    private array $seenGenerations = array();

    public function __construct(CycleStateStore $store)
    {
        $this->store = $store;
    }

    /**
     * @param string[] $names
     */
    public function bootstrap(array $names): void
    {
        $this->store->clearReload();
        $this->store->mergeDefaults($names);
        $this->store->setSupervisorPid(getmypid());

        foreach ($names as $name) {
            $this->seenGenerations[$name] = $this->store->generation($name);
            // One-time migration from the legacy cache flag. New actions write
            // the file, but installations and modules upgraded in place may
            // still have `cycle_xDisabled=1` in the cache.
            if (!$this->store->isDisabled($name) && $this->legacyDisabled($name)) {
                $this->store->set($name, CycleStateStore::DISABLED);
            }
        }
    }

    public function touch(): void
    {
        $this->store->touchSupervisor();
    }

    public function shutdown(): void
    {
        $this->store->clearSupervisorPid();
    }

    public function desired(string $name): string
    {
        return $this->store->desired($name);
    }

    public function isDesiredRunning(string $name): bool
    {
        return $this->store->desired($name) === CycleStateStore::RUNNING && !self::configurationDisabled($name);
    }

    public static function configurationDisabled(string $name): bool
    {
        return in_array($name, array('cycle_websockets', 'cycle_wscache'), true) && Config::bool('DISABLE_WEBSOCKETS');
    }

    public function isDisabled(string $name): bool
    {
        return $this->store->desired($name) === CycleStateStore::DISABLED || self::configurationDisabled($name);
    }

    public function isStoppedByUser(string $name): bool
    {
        return !$this->isDesiredRunning($name);
    }

    public function shouldStartAtBoot(string $name): bool
    {
        return $this->isDesiredRunning($name);
    }

    public function reloadRequested(): bool
    {
        return $this->store->reloadRequested() > 0;
    }

    /**
     * @param string[] $names
     * @param array<string,int> $isRunning
     * @param array<string,int> $toStart
     * @param array<string,int> $toStop
     */
    public function reconcile(array $names, array $isRunning, array &$toStart, array &$toStop): void
    {
        foreach ($names as $name) {
            $configurationDisabled = self::configurationDisabled($name);
            $desired = $configurationDisabled ? CycleStateStore::DISABLED : $this->store->desired($name);
            $generation = $this->store->generation($name);
            $seen = $this->seenGenerations[$name] ?? 0;

            if ($desired !== CycleStateStore::RUNNING) {
                unset($toStart[$name]);
                if (isset($isRunning[$name]) && !isset($toStop[$name])) {
                    $toStop[$name] = time();
                    $this->setStatus($name, 'stopping', $configurationDisabled ? 'WebSockets disabled by configuration' : ($desired === CycleStateStore::DISABLED ? 'Disabled by administrator' : 'Stopped by administrator'));
                }
                continue;
            }

            if ($generation <= $seen) {
                continue;
            }

            $this->seenGenerations[$name] = $generation;
            if (isset($isRunning[$name])) {
                $toStop[$name] = time();
                $toStart[$name] = time() + 4;
                $this->setStatus($name, 'stopping', 'Restart requested');
            } else {
                $toStart[$name] = time() + 2;
                $this->setStatus($name, 'starting', 'Start requested');
            }
        }
    }

    private function legacyDisabled(string $name): bool
    {
        if (!isset($GLOBALS['db'])) {
            return false;
        }
        try {
            $value = Core::cache()->cycle($name . 'Disabled');
        } catch (\Throwable $e) {
            return false;
        }
        return $value !== false && $value !== '' && $value !== '0';
    }

    private function setStatus(string $name, string $status, string $details = ''): void
    {
        if (!isset($GLOBALS['db'])) {
            return;
        }
        try {
            $cache = Core::cache();
            $cache->saveCycle($name . 'Status', $status);
            $cache->saveCycle($name . 'StatusUpdated', (string)time());
            $cache->saveCycle($name . 'StatusDetails', substr($details, 0, 240));
        } catch (\Throwable $e) {
            // Best effort only.
        }
    }
}
