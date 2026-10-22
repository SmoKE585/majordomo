<?php

namespace MajorDoMo\Core\Cycles;

use RuntimeException;
use Throwable;

/**
 * A process-owned pause of the supervised cycles for the duration of a system update.
 *
 * The updater takes an exclusive OS lock, writes a request and waits for the
 * supervisor to confirm that every child process is gone. A lock cannot outlive
 * the process that owns it, so a killed updater never leaves a permanent pause
 * behind: the supervisor detects the abandoned request and returns to normal.
 *
 * Waiting is always bounded and always tied to a live supervisor. An installation
 * where `cycle.php` is not running has no children to stop, so the update must
 * not wait for an acknowledgement that cannot arrive.
 */
final class CycleMaintenance
{
    /** Default number of seconds an updater waits for the supervisor to acknowledge. */
    public const READY_TIMEOUT = 120;

    /** A supervisor heartbeat older than this is considered dead. */
    private const HEARTBEAT_TIMEOUT = 60;

    /** How often a waiting updater reports progress, in seconds. */
    private const PROGRESS_INTERVAL = 10;

    private string $directory;
    private string $rebootPath;
    private string $requestPath;
    private string $readyPath;
    private string $blockedPath;
    private string $statePath;
    /** @var resource|null */
    private $lock = null;
    private string $token = '';

    public function __construct(string $root)
    {
        $root = rtrim($root, '/\\');
        $this->directory = $root . '/cms/cycles';
        $this->rebootPath = $root . '/reboot';
        $this->requestPath = $this->directory . '/update.json';
        $this->readyPath = $this->directory . '/update.ready';
        $this->blockedPath = $this->directory . '/update.blocked';
        $this->statePath = $this->directory . '/state.json';
    }

    /** Protect startup/config loading from a concurrent replacement of files. */
    public function beginStartup(): void
    {
        $lock = $this->openLock();
        while (!flock($lock, LOCK_SH | LOCK_NB, $blocked)) {
            if (!$blocked) {
                fclose($lock);
                throw new RuntimeException('Cannot lock cycle supervisor startup');
            }
            // This supervisor has no children yet, so the updater can proceed.
            $this->acknowledge($this->request());
            sleep(1);
        }
        $this->lock = $lock;
    }

    public function endStartup(): void
    {
        $this->unlock();
    }

    public function begin(string $ownerCycle = ''): void
    {
        $lock = $this->openLock();
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Основной цикл (cycle.php) сейчас запускается или уже выполняется другое обновление. Повторите через минуту.');
        }
        $this->lock = $lock;
        $this->token = bin2hex(random_bytes(16));
        try {
            // A previous acknowledgement must never confirm this request.
            $this->remove($this->readyPath);
            $this->remove($this->blockedPath);
            $this->write($this->requestPath, json_encode(array(
                'token' => $this->token,
                'pid' => getmypid(),
                'owner_cycle' => $ownerCycle,
                'created' => time(),
            ), JSON_THROW_ON_ERROR));
            $this->write($this->rebootPath, 'system_update');
        } catch (Throwable $error) {
            $this->unlock();
            throw $error;
        }
    }

    /** Returns null after also converting an abandoned pause to a normal restart. */
    public function active(): ?array
    {
        $lock = $this->openLock();
        if (!flock($lock, LOCK_EX | LOCK_NB, $blocked)) {
            fclose($lock);
            if (!$blocked) throw new RuntimeException('Cannot check system update lock');
            return $this->request();
        }
        try {
            if ($this->rebootReason() === 'system_update') {
                $this->write($this->rebootPath, 'system_update_recovery');
                error_log('Cycle supervisor: abandoned system update pause; scheduling cycle recovery');
            }
            return null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * True while the supervisor that owns `cms/cycles/state.json` is alive.
     *
     * Upgrades from a supervisor without the maintenance protocol have no state
     * file: they cannot acknowledge a pause either, so they are treated as
     * absent and the updater proceeds without waiting.
     */
    public function supervisorRunning(): bool
    {
        if (!is_file($this->statePath)) {
            return false;
        }
        $state = json_decode((string)@file_get_contents($this->statePath), true);
        if (!is_array($state)) {
            return false;
        }
        $pid = (int)($state['supervisor_pid'] ?? 0);
        $heartbeat = (int)($state['supervisor_heartbeat'] ?? 0);
        if ($pid <= 0 || $heartbeat <= 0) {
            return false;
        }
        if ($heartbeat <= time() && (time() - $heartbeat) > self::HEARTBEAT_TIMEOUT) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        return true;
    }

    /**
     * Waits until the supervisor confirms that all supervised processes stopped.
     *
     * Returns false without waiting when there is no live supervisor: nothing can
     * acknowledge the pause and nothing is left to stop. Throws when the
     * supervisor is alive but could not stop one of its children, so that the
     * updater reports the affected cycles instead of replacing files under them.
     *
     * @param callable|null $onProgress function(int $elapsed, string[] $blocked): void
     */
    public function waitUntilReady(int $timeout = self::READY_TIMEOUT, ?callable $onProgress = null): bool
    {
        if (!$this->supervisorRunning()) {
            return false;
        }
        $timeout = max(1, $timeout);
        $started = microtime(true);
        $deadline = $started + $timeout;
        $reported = 0;
        while (true) {
            $ready = $this->readToken($this->readyPath);
            if ($ready !== null && hash_equals($this->token, $ready)) {
                return true;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            $elapsed = (int)(microtime(true) - $started);
            if ($onProgress !== null && $elapsed >= $reported + self::PROGRESS_INTERVAL) {
                $reported = $elapsed;
                $onProgress($elapsed, $this->blockedCycles());
            }
            usleep(100000);
        }
        if (!$this->supervisorRunning()) {
            // The supervisor died while stopping its children: it cannot confirm
            // anything any more, and the update must not hang on it.
            return false;
        }
        throw new RuntimeException($this->timeoutMessage($timeout));
    }

    /**
     * Cycles the supervisor reported as impossible to stop for the current request.
     *
     * @return string[]
     */
    public function blockedCycles(): array
    {
        if (!is_file($this->blockedPath)) {
            return array();
        }
        $blocked = json_decode((string)@file_get_contents($this->blockedPath), true);
        if (!is_array($blocked)) {
            return array();
        }
        if ($this->token !== '' && (string)($blocked['token'] ?? '') !== $this->token) {
            return array();
        }
        $cycles = array();
        foreach ((array)($blocked['cycles'] ?? array()) as $cycle) {
            $cycle = trim((string)$cycle);
            if ($cycle !== '' && preg_match('/^cycle_[A-Za-z0-9_]+$/D', $cycle)) {
                $cycles[$cycle] = $cycle;
            }
        }
        return array_values($cycles);
    }

    /** Publishes the cycles that block the pause so the updater can name them. */
    public function publishBlocked(array $request, array $cycles): void
    {
        $token = (string)($request['token'] ?? '');
        if ($token === '') {
            return;
        }
        $cycles = array_values(array_unique(array_filter(array_map('strval', $cycles))));
        if ($cycles === array()) {
            $this->remove($this->blockedPath);
            return;
        }
        try {
            $this->write($this->blockedPath, json_encode(array(
                'token' => $token,
                'cycles' => $cycles,
                'updated' => time(),
            ), JSON_THROW_ON_ERROR));
        } catch (Throwable $error) {
            error_log('Cycle supervisor cannot publish paused cycle list: ' . $error->getMessage());
        }
    }

    /** Called only after all supervised processes except the updater have stopped. */
    public function acknowledge(array $request): void
    {
        $token = (string)($request['token'] ?? '');
        $path = $this->readyPath;
        if ($token !== '' && (!is_file($path) || trim((string)file_get_contents($path)) !== $token)) {
            $this->write($path, $token);
        }
        if ($token !== '') {
            $this->remove($this->blockedPath);
        }
    }

    /** The file replacement finished: keep normal cycle recovery. */
    public function finish(): void
    {
        if (!is_resource($this->lock) || $this->token === '') return;
        try {
            if ($this->rebootReason() === 'system_update') {
                $this->write($this->rebootPath, 'system_update_complete');
            }
        } finally {
            $this->unlock();
        }
    }

    /**
     * The update was not applied: release the pause and request a normal restart
     * of the cycles it stopped, so an aborted update does not leave them dead.
     */
    public function abort(string $reason = ''): void
    {
        if (!is_resource($this->lock) || $this->token === '') return;
        try {
            if ($this->rebootReason() === 'system_update') {
                $this->write($this->rebootPath, 'system_update_aborted');
            }
        } catch (Throwable $error) {
            error_log('Cycle supervisor cannot record aborted update: ' . $error->getMessage());
        } finally {
            $this->unlock();
        }
        // Only after the lock is released: `active()` reads the request only
        // while the updater still owns the pause.
        $this->remove($this->readyPath);
        $this->remove($this->blockedPath);
        $this->remove($this->requestPath);
        if ($reason !== '') {
            error_log('Cycle supervisor: system update aborted: ' . $reason);
        }
    }

    public function __destruct()
    {
        // Do not depend on destructors for recovery: active() handles hard kills.
        $this->unlock();
    }

    private function timeoutMessage(int $timeout): string
    {
        $message = 'Основной цикл не подтвердил остановку фоновых процессов за ' . $timeout . ' с, файлы не заменены.';
        $blocked = $this->blockedCycles();
        if ($blocked !== array()) {
            $message .= ' Не удалось остановить: ' . implode(', ', $blocked)
                . '. Остановите их в X-Ray или перезапустите службу cycle.php и повторите обновление.';
        } else {
            $message .= ' Супервизор cycle.php не отвечает: перезапустите службу и повторите обновление.';
        }
        return $message;
    }

    private function request(): array
    {
        if (!is_file($this->requestPath)) return array();
        $contents = (string)@file_get_contents($this->requestPath);
        $request = json_decode($contents, true);
        // A damaged request must not keep the supervisor from starting; the
        // updater rewrites it atomically on every attempt.
        return is_array($request) ? $request : array();
    }

    private function readToken(string $path): ?string
    {
        if (!is_file($path)) return null;
        $token = trim((string)@file_get_contents($path));
        return $token === '' ? null : $token;
    }

    private function rebootReason(): string
    {
        return is_file($this->rebootPath) ? trim((string)file_get_contents($this->rebootPath)) : '';
    }

    private function openLock()
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create cycle maintenance directory');
        }
        $lock = fopen($this->directory . '/update.lock', 'c+');
        if (!is_resource($lock)) throw new RuntimeException('Cannot open cycle update lock');
        return $lock;
    }

    private function unlock(): void
    {
        if (!is_resource($this->lock)) return;
        flock($this->lock, LOCK_UN);
        fclose($this->lock);
        $this->lock = null;
    }

    private function remove(string $path): void
    {
        if (is_file($path)) @unlink($path);
    }

    private function write(string $path, string $contents): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            if (file_put_contents($temporary, $contents . PHP_EOL) !== strlen($contents) + strlen(PHP_EOL)) {
                throw new RuntimeException('Cannot write cycle maintenance file: ' . $path);
            }
            if (!rename($temporary, $path)) throw new RuntimeException('Cannot replace cycle maintenance file: ' . $path);
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
}
