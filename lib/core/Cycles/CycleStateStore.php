<?php

namespace MajorDoMo\Core\Cycles;

/**
 * Persistent desired state of the background cycles.
 *
 * Cycle processes are children of the long-running `cycle.php` supervisor, so
 * a web request cannot signal them directly. The legacy implementation used
 * MEMORY-table flags for that, which disappeared on a database restart and
 * could leave a cycle half-stopped. This store is the source of truth:
 *
 *   running  — the supervisor must keep the process alive;
 *   stopped  — intentionally stopped, no health checks and no auto-restart;
 *   disabled — intentionally off, hidden from health checks, never started
 *              until an administrator enables it again.
 *
 * The file lives outside the document root and survives database switches,
 * which is exactly what the settings page needs when it changes DB credentials.
 */
final class CycleStateStore
{
    public const RUNNING = 'running';
    public const STOPPED = 'stopped';
    public const DISABLED = 'disabled';

    private const STATE_VERSION = 1;

    private string $root;
    private string $directory;
    private string $statePath;
    private string $lockPath;
    private string $holdPath;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
        $this->directory = $this->root . DIRECTORY_SEPARATOR . 'cms' . DIRECTORY_SEPARATOR . 'cycles';
        $this->statePath = $this->directory . DIRECTORY_SEPARATOR . 'state.json';
        $this->lockPath = $this->directory . DIRECTORY_SEPARATOR . 'state.lock';
        $this->holdPath = $this->directory . DIRECTORY_SEPARATOR . 'deploy.hold';
    }

    /**
     * Удерживать циклы остановленными после неудачного деплоя.
     *
     * Это не `disabled` и не `stopped`: желаемое состояние пользователя не
     * меняется, но супервизор не поднимает процессы, пока администратор не
     * снимет hold через `cycles:start`/`maintenance:off`.
     */
    public function hold(string $reason = 'deploy_failed'): void
    {
        $this->ensureDirectory();
        $payload = json_encode(array('reason' => $reason, 'created' => time(), 'pid' => getmypid()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $temporary = $this->holdPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $payload . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $this->holdPath)) {
            @unlink($temporary);
            throw new \RuntimeException('Не удалось записать hold циклов.');
        }
    }

    public function releaseHold(): bool
    {
        if (!is_file($this->holdPath)) return false;
        return @unlink($this->holdPath);
    }

    public function isHeld(): bool
    {
        return is_file($this->holdPath);
    }

    public function holdReason(): string
    {
        if (!is_file($this->holdPath)) return '';
        $data = json_decode((string)@file_get_contents($this->holdPath), true);
        return is_array($data) ? (string)($data['reason'] ?? '') : '';
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $lock = $this->openLock(LOCK_SH);
        if ($lock === null) {
            return $this->blankState();
        }
        try {
            return $this->readUnlocked();
        } finally {
            $this->closeLock($lock);
        }
    }

    /**
     * @return array<string,array{desired:string,generation:int,updated:int}>
     */
    public function entries(): array
    {
        $state = $this->snapshot();
        $entries = $state['cycles'] ?? array();
        return is_array($entries) ? $entries : array();
    }

    public function desired(string $name): string
    {
        $entries = $this->entries();
        $entry = isset($entries[$name]) && is_array($entries[$name]) ? $entries[$name] : array();
        return $this->normalizeDesired((string)($entry['desired'] ?? self::RUNNING));
    }

    public function generation(string $name): int
    {
        $entries = $this->entries();
        $entry = isset($entries[$name]) && is_array($entries[$name]) ? $entries[$name] : array();
        return (int)($entry['generation'] ?? 0);
    }

    public function isRunning(string $name): bool
    {
        return $this->desired($name) === self::RUNNING;
    }

    public function isStopped(string $name): bool
    {
        return $this->desired($name) === self::STOPPED;
    }

    public function isDisabled(string $name): bool
    {
        return $this->desired($name) === self::DISABLED;
    }

    /**
     * Persist a desired state. A generation bump asks the supervisor to
     * stop and start the process even when the state itself does not change.
     *
     * @return array<string,mixed>
     */
    public function set(string $name, string $desired, bool $bumpGeneration = false): array
    {
        return $this->setMany(array($name), $desired, $bumpGeneration);
    }

    /**
     * @param string[] $names
     * @return array<string,mixed>
     */
    public function setMany(array $names, string $desired, bool $bumpGeneration = false, bool $requestReload = false): array
    {
        $desired = $this->normalizeDesired($desired);
        $names = $this->normalizeNames($names);
        return $this->mutate(function (array $state) use ($names, $desired, $bumpGeneration, $requestReload): array {
            $cycles = isset($state['cycles']) && is_array($state['cycles']) ? $state['cycles'] : array();
            $now = time();
            foreach ($names as $name) {
                $entry = isset($cycles[$name]) && is_array($cycles[$name]) ? $cycles[$name] : array();
                $entry['desired'] = $desired;
                $entry['updated'] = $now;
                $entry['generation'] = max(0, (int)($entry['generation'] ?? 0)) + ($bumpGeneration ? 1 : 0);
                $cycles[$name] = $entry;
            }
            $state['cycles'] = $cycles;
            if ($requestReload) {
                $state['reload'] = $now;
            }
            return $state;
        });
    }

    /**
     * Ask the supervisor to stop every cycle and exit so systemd can start it
     * again with the new `.env`. Needed after a database switch.
     */
    public function requestReload(): void
    {
        $this->mutate(function (array $state): array {
            $state['reload'] = time();
            return $state;
        });
    }

    public function reloadRequested(): int
    {
        return (int)($this->snapshot()['reload'] ?? 0);
    }

    /**
     * Clears the reload marker after the supervisor has handled it (or on a
     * fresh start, where there is nothing left to reload).
     */
    public function clearReload(): void
    {
        $this->mutate(function (array $state): array {
            $state['reload'] = null;
            return $state;
        });
    }

    public function supervisorPid(): int
    {
        return (int)($this->snapshot()['supervisor_pid'] ?? 0);
    }

    public function setSupervisorPid(int $pid): void
    {
        $this->mutate(function (array $state) use ($pid): array {
            $state['supervisor_pid'] = $pid > 0 ? $pid : null;
            $state['supervisor_heartbeat'] = time();
            return $state;
        });
    }

    /**
     * Called by the supervisor every loop. The heartbeat is what makes
     * `isSupervisorRunning()` trustworthy after a crash or a container restart.
     */
    public function touchSupervisor(): void
    {
        $this->mutate(function (array $state): array {
            $state['supervisor_pid'] = getmypid();
            $state['supervisor_heartbeat'] = time();
            return $state;
        });
    }

    public function clearSupervisorPid(): void
    {
        $pid = getmypid();
        $this->mutate(function (array $state) use ($pid): array {
            if ((int)($state['supervisor_pid'] ?? 0) === $pid) {
                $state['supervisor_pid'] = null;
                $state['supervisor_heartbeat'] = null;
            }
            return $state;
        });
    }

    public function isSupervisorRunning(): bool
    {
        $state = $this->snapshot();
        $pid = (int)($state['supervisor_pid'] ?? 0);
        $heartbeat = (int)($state['supervisor_heartbeat'] ?? 0);
        if ($pid <= 0 || $heartbeat <= 0 || (time() - $heartbeat) > 30) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        return true;
    }

    /**
     * Drops entries whose script no longer exists. Called at supervisor startup,
     * where the scripts directory is consistent (an update holds the maintenance
     * lock), so removing a module does not leave dead cycles in the state file.
     *
     * @param string[] $names
     * @return string[] removed cycle names
     */
    public function prune(array $names): array
    {
        $names = $this->normalizeNames($names);
        $known = array_fill_keys($names, true);
        $removed = array();
        $this->mutate(function (array $state) use ($known, &$removed): array {
            $cycles = isset($state['cycles']) && is_array($state['cycles']) ? $state['cycles'] : array();
            foreach (array_keys($cycles) as $name) {
                if (isset($known[$name])) {
                    continue;
                }
                unset($cycles[$name]);
                $removed[] = $name;
            }
            $state['cycles'] = $cycles;
            return $state;
        });
        return $removed;
    }

    /**
     * @param string[] $names
     * @return string[]
     */
    public function mergeDefaults(array $names): array
    {
        $names = $this->normalizeNames($names);
        $missing = array();
        $entries = $this->entries();
        foreach ($names as $name) {
            if (!isset($entries[$name])) {
                $missing[] = $name;
            }
        }
        if ($missing === array()) {
            return array();
        }
        $this->mutate(function (array $state) use ($missing): array {
            $cycles = isset($state['cycles']) && is_array($state['cycles']) ? $state['cycles'] : array();
            $now = time();
            foreach ($missing as $name) {
                $cycles[$name] = array(
                    'desired' => self::RUNNING,
                    'generation' => 0,
                    'updated' => $now,
                );
            }
            $state['cycles'] = $cycles;
            return $state;
        });
        return $missing;
    }

    /**
     * @return array<string,mixed>
     */
    private function mutate(callable $callback): array
    {
        $lock = $this->openLock(LOCK_EX);
        if ($lock === null) {
            throw new \RuntimeException('Cannot lock cycle state: ' . $this->lockPath);
        }
        try {
            $state = $this->readUnlocked();
            $state = $callback($state);
            if (!is_array($state)) {
                $state = $this->blankState();
            }
            $state['version'] = self::STATE_VERSION;
            $state['updated'] = time();
            if (!$this->writeUnlocked($state)) {
                throw new \RuntimeException('Cannot persist cycle state: ' . $this->statePath);
            }
            return $state;
        } finally {
            $this->closeLock($lock);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function blankState(): array
    {
        return array(
            'version' => self::STATE_VERSION,
            'updated' => 0,
            'reload' => null,
            'supervisor_pid' => null,
            'supervisor_heartbeat' => null,
            'cycles' => array(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function readUnlocked(): array
    {
        if (!is_file($this->statePath)) {
            return $this->blankState();
        }
        $contents = @file_get_contents($this->statePath);
        if (!is_string($contents) || trim($contents) === '') {
            return $this->blankState();
        }
        $state = json_decode($contents, true);
        if (!is_array($state)) {
            return $this->blankState();
        }
        $state += $this->blankState();
        if (!is_array($state['cycles'])) {
            $state['cycles'] = array();
        }
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function writeUnlocked(array $state): bool
    {
        if (!$this->ensureDirectory()) {
            return false;
        }
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return false;
        }
        $temporary = $this->statePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            @unlink($temporary);
            return false;
        }
        @chmod($temporary, 0640);
        if (!@rename($temporary, $this->statePath)) {
            @unlink($this->statePath);
            if (!@rename($temporary, $this->statePath)) {
                @unlink($temporary);
                return false;
            }
        }
        return true;
    }

    /**
     * @return resource|null
     */
    private function openLock(int $mode)
    {
        if (!$this->ensureDirectory()) {
            return null;
        }
        $handle = @fopen($this->lockPath, 'c+');
        if (!is_resource($handle)) {
            return null;
        }
        if (!@flock($handle, $mode)) {
            @fclose($handle);
            return null;
        }
        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function closeLock($handle): void
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            return true;
        }
        if (@mkdir($this->directory, 0775, true)) {
            return true;
        }
        return is_dir($this->directory);
    }

    private function normalizeDesired(string $desired): string
    {
        $desired = strtolower(trim($desired));
        if (in_array($desired, array(self::RUNNING, self::STOPPED, self::DISABLED), true)) {
            return $desired;
        }
        return self::RUNNING;
    }

    /**
     * @param string[] $names
     * @return string[]
     */
    private function normalizeNames(array $names): array
    {
        $result = array();
        foreach ($names as $name) {
            $name = trim((string)$name);
            if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/D', $name)) {
                continue;
            }
            $result[$name] = $name;
        }
        return array_values($result);
    }
}
