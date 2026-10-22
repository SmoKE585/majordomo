<?php

namespace MajorDoMo\Core\Cycles;

use MajorDoMo\Core\Core;
use MajorDoMo\Core\Config;

/**
 * Public API for managing background cycles.
 *
 * The web UI never kills a process itself. It writes a desired state (and, for
 * legacy callers, the same `cycle_*Control` flag older modules know) and the
 * `cycle.php` supervisor applies it. That keeps stop/restart/disable reliable
 * even when the request comes from a different PHP process.
 */
final class CycleManager
{
    private CycleStateStore $store;
    private string $root;
    private ?\Closure $runtimeReader;

    public function __construct(string $root, ?CycleStateStore $store = null, ?\Closure $runtimeReader = null)
    {
        $this->root = rtrim($root, '/\\');
        $this->store = $store ?? new CycleStateStore($this->root);
        $this->runtimeReader = $runtimeReader;
    }

    public function store(): CycleStateStore
    {
        return $this->store;
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        $names = array();
        $files = glob($this->root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'cycle_*.php');
        if (!is_array($files)) {
            return $names;
        }
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (preg_match('/^cycle_[A-Za-z0-9_]+$/D', $name)) {
                $names[$name] = $name;
            }
        }
        $names = array_values($names);
        sort($names, SORT_STRING);
        return $names;
    }

    public function exists(string $name): bool
    {
        return in_array($name, $this->names(), true);
    }

    /**
     * @return array{name:string,desired:string,status:string,online:bool,updated:int,status_updated:int,details:string,generation:int}
     */
    public function state(string $name): array
    {
        $name = $this->normalizeName($name);
        if ($name === '') {
            return array(
                'name' => '',
                'desired' => CycleStateStore::RUNNING,
                'status' => 'unknown',
                'online' => false,
                'updated' => 0,
                'status_updated' => 0,
                'details' => '',
                'generation' => 0,
            );
        }

        $desired = $this->store->desired($name);
        $updated = (int)$this->cycleValue($name . 'Run');
        $status = (string)$this->cycleValue($name . 'Status');
        $statusUpdated = (int)$this->cycleValue($name . 'StatusUpdated');
        $details = (string)$this->cycleValue($name . 'StatusDetails');
        $watchdog = new CycleWatchdog(Config::int('CYCLE_HEARTBEAT_TIMEOUT', 120), Config::int('CYCLE_STARTUP_TIMEOUT', 180), Config::arrayValue('CYCLE_TIMEOUTS'), defined('JOBS_EXECUTION_TIMEOUT') ? (int)JOBS_EXECUTION_TIMEOUT : 300);
        $online = $updated > 0 && $updated <= time() + 5 && (time() - $updated) < $watchdog->timeout($name);

        if (CycleSupervisor::configurationDisabled($name)) {
            $runtimeStatus = 'disabled';
            $online = false;
            $details = 'WebSockets disabled by configuration';
        } elseif ($desired === CycleStateStore::DISABLED) {
            $runtimeStatus = 'disabled';
        } elseif ($desired === CycleStateStore::STOPPED) {
            $runtimeStatus = $online ? 'stopping' : 'stopped';
        } elseif ($status === 'error' && $statusUpdated >= $updated) {
            $runtimeStatus = 'stopped';
            $online = false;
        } elseif ($online) {
            $runtimeStatus = 'running';
        } elseif (in_array($status, array('starting', 'stopping'), true) && $statusUpdated > 0 && (time() - $statusUpdated) < $watchdog->timeout($name, true)) {
            $runtimeStatus = $status;
        } elseif ($updated > 0) {
            $runtimeStatus = 'hang';
        } else {
            $runtimeStatus = 'stopped';
        }

        return array(
            'name' => $name,
            'desired' => $desired,
            'status' => $runtimeStatus,
            'online' => $online,
            'updated' => $updated,
            'status_updated' => $statusUpdated,
            'details' => $details,
            'generation' => $this->store->generation($name),
        );
    }

    public function start(string $name): array
    {
        return $this->command($name, 'start');
    }

    public function stop(string $name): array
    {
        return $this->command($name, 'stop');
    }

    public function restart(string $name): array
    {
        return $this->command($name, 'restart');
    }

    public function disable(string $name): array
    {
        return $this->command($name, 'disable');
    }

    public function enable(string $name): array
    {
        return $this->command($name, 'enable');
    }

    /**
     * Restart every discovered cycle. Used after saving settings so children
     * load the fresh `.env` values.
     *
     * @return array{ok:bool,cycles:int,supervisor_running:bool}
     */
    public function restartAll(): array
    {
        $this->ensureRuntimeTables();
        $names = array();
        foreach ($this->names() as $name) {
            if ($this->store->desired($name) === CycleStateStore::RUNNING) {
                $names[] = $name;
            }
        }
        $supervisorRunning = $this->isSupervisorRunning();
        if ($names !== array()) {
            $this->store->setMany($names, CycleStateStore::RUNNING, true);
            foreach ($names as $name) {
                $this->setStatus(
                    $name,
                    $supervisorRunning ? 'stopping' : 'stopped',
                    $supervisorRunning ? 'Settings changed: restart queued' : 'Settings changed: restart pending supervisor'
                );
            }
        }
        return array(
            'ok' => $names !== array(),
            'cycles' => count($names),
            'supervisor_running' => $supervisorRunning,
        );
    }

    /**
     * Stop all children and make the supervisor exit so systemd starts it with
     * the new `.env`. Needed when the database connection changes.
     */
    public function requestReload(): void
    {
        $this->ensureRuntimeTables();
        $this->store->requestReload();
    }

    public function isSupervisorRunning(): bool
    {
        return $this->store->isSupervisorRunning();
    }

    public function ensureRuntimeTables(): void
    {
        if (!isset($GLOBALS['db'])) {
            return;
        }
        try {
            $db = Core::database();
            $db->exec('CREATE TABLE IF NOT EXISTS `cached_cycles` (`TITLE` char(100) NOT NULL,`VALUE` char(255) NOT NULL,PRIMARY KEY (`TITLE`)) ENGINE=MEMORY DEFAULT CHARSET=utf8;');
            $db->exec('CREATE TABLE IF NOT EXISTS `cached_cycle_logs` (`ID` int(10) unsigned NOT NULL AUTO_INCREMENT,`CYCLE` char(100) NOT NULL,`ADDED` int(10) unsigned NOT NULL,`MESSAGE` varchar(1024) NOT NULL,PRIMARY KEY (`ID`),KEY `CYCLE_ADDED` (`CYCLE`,`ADDED`)) ENGINE=MEMORY DEFAULT CHARSET=utf8;');
        } catch (\Throwable $e) {
            // The supervisor creates these tables on boot. A missing DB must not
            // break the settings page or the X-Ray list.
        }
    }

    /**
     * @return array{ok:bool,name:string,command:string,status:string}
     */
    private function command(string $name, string $command): array
    {
        $name = $this->normalizeName($name);
        if ($name === '') {
            return array('ok' => false, 'name' => '', 'command' => $command, 'status' => 'unknown');
        }
        $this->ensureRuntimeTables();
        $this->store->mergeDefaults(array($name));

        switch ($command) {
            case 'start':
                $this->store->set($name, CycleStateStore::RUNNING);
                $this->cycleSet($name . 'Disabled', '');
                $this->cycleSet($name . 'Control', 'start');
                $this->setStatus($name, 'starting', 'Start requested by administrator');
                break;
            case 'stop':
                $this->store->set($name, CycleStateStore::STOPPED);
                $this->cycleSet($name . 'Control', 'stop');
                $this->setStatus($name, 'stopping', 'Stop requested by administrator');
                break;
            case 'restart':
                // The supervisor watches `generation` and restarts the process
                // itself, so no legacy control flag can race with this request.
                $this->store->set($name, CycleStateStore::RUNNING, true);
                $this->cycleSet($name . 'Disabled', '');
                $this->setStatus($name, 'stopping', 'Restart requested by administrator');
                break;
            case 'disable':
                $this->store->set($name, CycleStateStore::DISABLED);
                $this->cycleSet($name . 'Disabled', '1');
                $this->cycleSet($name . 'Control', 'stop');
                $this->setStatus($name, 'disabled', 'Disabled by administrator');
                break;
            case 'enable':
                $this->store->set($name, CycleStateStore::RUNNING);
                $this->cycleSet($name . 'Disabled', '');
                $this->cycleSet($name . 'Control', 'start');
                $this->setStatus($name, 'starting', 'Enabled by administrator');
                break;
            default:
                return array('ok' => false, 'name' => $name, 'command' => $command, 'status' => 'unknown');
        }

        return array(
            'ok' => true,
            'name' => $name,
            'command' => $command,
            'status' => $this->state($name)['status'],
        );
    }

    private function setStatus(string $name, string $status, string $details = ''): void
    {
        $this->cycleSet($name . 'Status', $status);
        $this->cycleSet($name . 'StatusUpdated', (string)time());
        $this->cycleSet($name . 'StatusDetails', substr($details, 0, 240));
    }

    private function cycleSet(string $key, string $value): void
    {
        if (!isset($GLOBALS['db'])) {
            return;
        }
        try {
            Core::cache()->saveCycle($key, $value);
        } catch (\Throwable $e) {
            // Runtime status is best effort; desired state is already persisted.
        }
    }

    /**
     * @return string|false
     */
    private function cycleValue(string $key)
    {
        if ($this->runtimeReader !== null) {
            return ($this->runtimeReader)($key);
        }
        if (!isset($GLOBALS['db'])) {
            return false;
        }
        try {
            return Core::cache()->cycle($key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function normalizeName(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$name);
        if ($name === '') {
            return '';
        }
        if (strpos($name, 'cycle_') !== 0) {
            $name = 'cycle_' . $name;
        }
        return $name;
    }
}
