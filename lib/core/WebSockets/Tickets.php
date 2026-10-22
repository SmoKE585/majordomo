<?php

namespace MajorDoMo\Core\WebSockets;

use MajorDoMo\Core\Http\Session;

/**
 * One-use browser credentials; no permanent secret is embedded in HTML or URLs.
 *
 * 2026-10-23 — билет запоминает каталог сессий, в котором он выпущен. Сервер
 * WebSocket работает отдельным процессом и раньше искал sess_* только в
 * var/sessions: если PHP писал сессии в другой каталог, рукопожатие падало, а
 * выдача билета отвечала 500.
 */
final class Tickets
{
    /**
     * @param string $sessionsDirectory фактический каталог сессий; пусто —
     *        каталог из конфигурации (SESSION_SAVE_PATH или var/sessions).
     */
    public function __construct(private string $root, private string $sessionsDirectory = '') {}

    /**
     * @param string $host host, который браузер видел при получении билета:
     *        по нему сервер WS проверяет Origin, если прокси не передал Host.
     */
    public function issue(array $user, string $sessionId, int $created, ?int $now = null, string $host = ''): string
    {
        $now ??= time();
        $sessions = $this->locate($sessionId);
        if ($sessions === '') {
            // Обработчик сессий не файловый — проверять сессию из другого
            // процесса нечем; об этом стоит сказать прямо в ответе.
            $handler = (string)ini_get('session.save_handler');
            throw new \RuntimeException('Файл сессии администратора не найден'
                . ($handler !== '' && $handler !== 'files' ? ' (session.save_handler=' . $handler . ', нужны файловые сессии)' : '')
                . '. Проверьте SESSION_SAVE_PATH и каталог var/sessions.');
        }
        if ($created <= $now - 43200) throw new \RuntimeException('Сеанс администратора старше 12 часов. Войдите заново.');
        $directory = $this->root . '/var/security/websocket-tickets';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Не удалось создать хранилище билетов WebSocket.');
        foreach (new \DirectoryIterator($directory) as $file) {
            if ($file->isFile() && $file->getMTime() < $now - 60) unlink($file->getPathname());
        }
        $ticket = bin2hex(random_bytes(32));
        $payload = array('id' => $user['id'], 'stamp' => $user['stamp'], 'session' => $sessionId, 'sessions' => $sessions,
            'host' => $host, 'redeem_before' => $now + 30, 'expires' => min($now + 3600, $created + 43200));
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
        if (($identity['expires'] ?? 0) <= ($now ?? time())) return false;
        if (!$this->sessionExists((string)($identity['session'] ?? ''), $this->ticketDirectory($identity))) return false;
        $user = $adminState((int)($identity['id'] ?? 0));
        return $user !== null && hash_equals($user['stamp'], (string)($identity['stamp'] ?? ''));
    }

    /** Каталог из билета: только существующий абсолютный путь без обхода вверх. */
    private function ticketDirectory(array $identity): string
    {
        $directory = $identity['sessions'] ?? '';
        if (!is_string($directory) || $directory === '' || str_contains($directory, '..')) return '';
        $absolute = $directory[0] === '/' || $directory[0] === '\\' || preg_match('/^[a-zA-Z]:[\\\\\/]/', $directory) === 1;
        return $absolute && is_dir($directory) ? rtrim($directory, '/\\') : '';
    }

    /** Каталог, где реально лежит файл этой сессии; пусто — файла нет. */
    private function locate(string $id): string
    {
        if (preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $id) !== 1) return '';
        foreach ($this->directories() as $directory) {
            clearstatcache(true, $directory . '/sess_' . $id);
            if (is_file($directory . '/sess_' . $id)) return $directory;
        }
        return '';
    }

    private function sessionExists(string $id, string $directory = ''): bool
    {
        if ($directory !== '') {
            clearstatcache(true, $directory . '/sess_' . $id);
            return preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $id) === 1 && is_file($directory . '/sess_' . $id);
        }
        return $this->locate($id) !== '';
    }

    /**
     * Каталоги-кандидаты: явно переданный, затем настройка проекта и, наконец,
     * путь, который PHP сообщает в этом процессе.
     *
     * @return string[]
     */
    private function directories(): array
    {
        $result = array();
        foreach (array($this->sessionsDirectory, Session::directory($this->root), Session::runtimeDirectory()) as $directory) {
            if (!is_string($directory) || $directory === '') continue;
            $directory = rtrim(str_replace('\\', '/', $directory), '/');
            if (!in_array($directory, $result, true)) $result[] = $directory;
        }
        return $result;
    }
}
