<?php

namespace MajorDoMo\Core\Maintenance;

/**
 * Режим обслуживания на время обновления и ручных работ.
 *
 * Маркер лежит в `cms/maintenance.json`, а не в БД: обновление может менять
 * схему и временно ломать подключение, а сайт всё равно должен отдавать
 * понятный 503. Файл переживает замену кода и перезапуск службы.
 *
 * 2026-10-22 — добавлено для деплоя без «полуживого» сайта.
 */
final class MaintenanceMode
{
    public const DEFAULT_TTL = 3600;

    private string $root;
    private string $file;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
        $this->file = $this->root . '/cms/maintenance.json';
    }

    public function path(): string
    {
        return $this->file;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function enable(string $reason = 'system_update', int $ttl = self::DEFAULT_TTL, string $actor = 'cli', array $extra = array()): array
    {
        $now = time();
        $status = array(
            'active' => true,
            'reason' => $reason,
            'message' => (string)($extra['message'] ?? ''),
            'started_at' => (int)($extra['started_at'] ?? $now),
            'updated_at' => $now,
            'expires_at' => $ttl > 0 ? $now + $ttl : 0,
            'actor' => $actor,
            'failed' => false,
            'deploy_id' => (string)($extra['deploy_id'] ?? ''),
        );
        $this->write($status);
        return $status;
    }

    /**
     * Обновить сообщение/признак ошибки, не снимая режим. После ошибки маркер
     * остаётся активным до ручного `maintenance:off`: иначе пользователи увидят
     * недоделанное обновление.
     *
     * @return array<string,mixed>
     */
    public function markFailed(string $message): array
    {
        $status = $this->status();
        if (empty($status['active'])) {
            $status = $this->enable('system_update');
        }
        $status['active'] = true;
        $status['failed'] = true;
        $status['message'] = $message;
        $status['expires_at'] = 0;
        $status['updated_at'] = time();
        $this->write($status);
        return $status;
    }

    /** @param array<string,mixed> $changes */
    public function update(array $changes): array
    {
        $status = $this->status();
        if (empty($status['active'])) throw new \RuntimeException('Режим обслуживания не включён.');
        foreach ($changes as $key => $value) {
            if (in_array($key, array('active', 'reason', 'message', 'started_at', 'updated_at', 'expires_at', 'actor', 'failed', 'deploy_id'), true)) {
                $status[$key] = $value;
            }
        }
        $status['updated_at'] = time();
        $this->write($status);
        return $status;
    }

    public function disable(): bool
    {
        if (!is_file($this->file)) return false;
        return @unlink($this->file);
    }

    /**
     * @return array{active:bool,expired:bool,reason:string,message:string,started_at:int,updated_at:int,expires_at:int,actor:string,failed:bool,deploy_id:string,error:string}
     */
    public function status(): array
    {
        $empty = array(
            'active' => false,
            'expired' => false,
            'reason' => '',
            'message' => '',
            'started_at' => 0,
            'updated_at' => 0,
            'expires_at' => 0,
            'actor' => '',
            'failed' => false,
            'deploy_id' => '',
            'error' => '',
        );
        if (!is_file($this->file)) return $empty;
        $data = json_decode((string)@file_get_contents($this->file), true);
        if (!is_array($data)) {
            $empty['error'] = 'Повреждён cms/maintenance.json';
            return $empty;
        }
        $status = array_merge($empty, $data);
        $status['active'] = !empty($status['active']);
        $status['failed'] = !empty($status['failed']);
        $status['expires_at'] = (int)($status['expires_at'] ?? 0);
        $status['started_at'] = (int)($status['started_at'] ?? 0);
        $status['updated_at'] = (int)($status['updated_at'] ?? 0);
        $status['expired'] = $status['active'] && $status['expires_at'] > 0 && $status['expires_at'] <= time();
        if ($status['expired']) $status['active'] = false;
        return $status;
    }

    public function isActive(): bool
    {
        return (bool)$this->status()['active'];
    }

    /**
     * Снять режим после успешного деплоя, если веб-запрос не успел это сделать
     * (auto-update завершает процесс до `maintenance:off`). Вызывается при
     * старте cycle_main, когда служба уже поднялась.
     */
    public function completeIfDeployed(): bool
    {
        $status = $this->status();
        if (empty($status['active']) || $status['reason'] !== 'system_update') return false;
        $file = $this->root . '/cms/deploy/last.json';
        if (!is_file($file)) return false;
        $state = json_decode((string)@file_get_contents($file), true);
        if (!is_array($state) || ($state['status'] ?? '') !== 'success') return false;
        return $this->disable();
    }

    /** @param array<string,mixed> $status */
    private function write(array $status): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать каталог cms для режима обслуживания.');
        }
        $json = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) throw new \RuntimeException('Не удалось сериализовать режим обслуживания.');
        $temporary = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать режим обслуживания.');
        }
        if (!@rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new \RuntimeException('Не удалось заменить cms/maintenance.json.');
        }
    }
}
