<?php

namespace MajorDoMo\Core\Cycles;

use RuntimeException;
use Throwable;

/** A process-owned update pause. OS locks disappear even after SIGKILL. */
final class CycleMaintenance
{
    private string $directory;
    private string $rebootPath;
    /** @var resource|null */
    private $lock = null;
    private string $token = '';

    public function __construct(string $root)
    {
        $root = rtrim($root, '/\\');
        $this->directory = $root . '/cms/cycles';
        $this->rebootPath = $root . '/reboot';
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
            throw new RuntimeException('Another system update or cycle supervisor startup is in progress');
        }
        $this->lock = $lock;
        $this->token = bin2hex(random_bytes(16));
        try {
            $this->write($this->directory . '/update.json', json_encode(array(
                'token' => $this->token,
                'pid' => getmypid(),
                'owner_cycle' => $ownerCycle,
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

    /** Called only after all supervised processes except the updater have stopped. */
    public function acknowledge(array $request): void
    {
        $token = (string)($request['token'] ?? '');
        $path = $this->directory . '/update.ready';
        if ($token !== '' && (!is_file($path) || trim((string)file_get_contents($path)) !== $token)) {
            $this->write($path, $token);
        }
    }

    public function waitUntilReady(int $timeout = 120): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            $ready = $this->directory . '/update.ready';
            if (is_file($ready) && trim((string)file_get_contents($ready)) === $this->token) return;
            usleep(100000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Cycle supervisor did not confirm the update pause; files were not replaced');
    }

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

    public function __destruct()
    {
        // Do not depend on destructors for recovery: active() handles hard kills.
        $this->unlock();
    }

    private function request(): array
    {
        $path = $this->directory . '/update.json';
        if (!is_file($path)) return array();
        $request = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($request)) throw new RuntimeException('Invalid cycle update request');
        return $request;
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
