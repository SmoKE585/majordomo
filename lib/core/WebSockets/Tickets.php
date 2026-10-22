<?php

namespace MajorDoMo\Core\WebSockets;

/** One-use browser credentials; no permanent secret is embedded in HTML or URLs. */
final class Tickets
{
    public function __construct(private string $root) {}

    public function issue(array $user, string $sessionId, int $created, ?int $now = null): string
    {
        $now ??= time();
        if (!$this->sessionExists($sessionId) || $created <= $now - 43200) throw new \RuntimeException('Сессия администратора завершена.');
        $directory = $this->root . '/var/security/websocket-tickets';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Не удалось создать хранилище билетов WebSocket.');
        foreach (new \DirectoryIterator($directory) as $file) {
            if ($file->isFile() && $file->getMTime() < $now - 60) unlink($file->getPathname());
        }
        $ticket = bin2hex(random_bytes(32));
        $payload = array('id' => $user['id'], 'stamp' => $user['stamp'], 'session' => $sessionId,
            'redeem_before' => $now + 30, 'expires' => min($now + 3600, $created + 43200));
        $file = $directory . '/' . hash('sha256', $ticket);
        if (file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new \RuntimeException('Не удалось сохранить билет WebSocket.');
        chmod($file, 0600);
        return $ticket;
    }

    public function consume(string $ticket, ?int $now = null): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $ticket)) return null;
        $file = $this->root . '/var/security/websocket-tickets/' . hash('sha256', $ticket);
        if (!is_file($file)) return null;
        $handle = fopen($file, 'r+');
        if ($handle === false) return null;
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) return null;
            $contents = stream_get_contents($handle, 4096);
            ftruncate($handle, 0);
        } finally { fclose($handle); }
        if (is_file($file)) unlink($file);
        $payload = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        return is_array($payload) && ($payload['redeem_before'] ?? 0) > ($now ?? time()) ? $payload : null;
    }

    public function valid(array $identity, callable $adminState, ?int $now = null): bool
    {
        if (($identity['expires'] ?? 0) <= ($now ?? time()) || !$this->sessionExists((string)($identity['session'] ?? ''))) return false;
        $user = $adminState((int)($identity['id'] ?? 0));
        return $user !== null && hash_equals($user['stamp'], (string)($identity['stamp'] ?? ''));
    }

    private function sessionExists(string $id): bool
    {
        clearstatcache(true, $this->root . '/var/sessions/sess_' . $id);
        return preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $id) === 1 && is_file($this->root . '/var/sessions/sess_' . $id);
    }
}
