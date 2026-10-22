<?php
namespace MajorDoMo\Core\Http;
use MajorDoMo\Core\Config;

/**
 * Native cookie-only sessions. Authentication is separate from UI state.
 * Изменения: 2026-10-22 — loopback-прокси всегда доверенный для HTTPS-признака.
 * Изменения: 2026-10-23 — каталог сессий определяется одним методом и не
 * предполагается равным var/sessions: хостинг может навязывать свой путь
 * через php_admin_value, и тогда одноразовый билет WebSocket не находил файл
 * сессии (500 на /api/v1/panel/websocket-ticket).
 */
final class Session
{
    public string $name;

    /** Каталог, в который PHP фактически пишет сессии в этом процессе. */
    private static string $effectiveDirectory = '';

    public function __construct(string $name = 'prj', ?string $root = null)
    {
        $this->name = (string)$name;
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/D', $this->name)) throw new \InvalidArgumentException('Недопустимое имя сессии.');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (headers_sent()) throw new \RuntimeException('Сессия должна запускаться до вывода ответа.');
            $configured = self::directory($root);
            session_save_path($configured);
            // Хостинг может фиксировать каталог через php_admin_value пула
            // php-fpm: тогда PHP игнорирует настройку проекта и пишет в свой
            // путь. Работаем по фактическому и не создаём var/sessions зря.
            $directory = self::runtimeDirectory() ?: $configured;
            $pinned = !self::samePath($directory, $configured);
            if (!$pinned && !is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Не удалось создать хранилище сессий.');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_cookies', '1');
            ini_set('session.use_trans_sid', '0');
            ini_set('session.gc_maxlifetime', '43200');
            session_name($this->name);
            session_set_cookie_params(array('lifetime' => 0, 'path' => self::cookiePath(), 'domain' => '', 'secure' => self::isSecure($_SERVER), 'httponly' => true, 'samesite' => 'Strict'));
            if (!session_start()) throw new \RuntimeException('Не удалось открыть сессию.');
            self::$effectiveDirectory = $directory;
        } elseif (session_name() !== $this->name) {
            throw new \RuntimeException('Уже открыта другая сессия.');
        }
        // Serialized DATA from the old implementation never establishes identity.
        unset($_SESSION['DATA'], $_SESSION['expire']);
        if (!is_array($_SESSION['md_state'] ?? null)) $_SESSION['md_state'] = array();
    }

    public function get(string $key, mixed $default = null): mixed { return $_SESSION['md_state'][$key] ?? $default; }
    public function set(string $key, mixed $value): void { $_SESSION['md_state'][$key] = $value; }
    public function has(string $key): bool { return isset($_SESSION['md_state'][$key]); }
    public function forget(string $key): void { unset($_SESSION['md_state'][$key]); }
    public function pull(string $key, mixed $default = null): mixed { $value = $this->get($key, $default); $this->forget($key); return $value; }
    public function user(): ?array
    {
        $auth = $_SESSION['md_admin'] ?? null;
        return is_array($auth) ? array('id' => $auth['id'], 'username' => $auth['username'], 'name' => $auth['name']) : null;
    }

    /**
     * Каталог сессий по конфигурации. Пустая настройка SESSION_SAVE_PATH
     * означает штатный var/sessions внутри проекта.
     * 2026-10-23 — единая точка и для билетов WebSocket.
     */
    public static function directory(?string $root = null): string
    {
        $configured = trim(Config::string('SESSION_SAVE_PATH', ''));
        if ($configured !== '') return rtrim(str_replace('\\', '/', $configured), '/');
        return rtrim(str_replace('\\', '/', $root ?? Config::string('ROOT')), '/\\') . '/var/sessions';
    }

    /**
     * Каталог, который PHP использует сейчас, по его же настройке.
     * session.save_path допускает префикс GC: "N;MODE;/path"; пустое значение
     * означает системный временный каталог. Нужен, когда хостинг фиксирует
     * путь через php_admin_value: ini_set его уже не меняет.
     * 2026-10-23 — источник истины для билета WebSocket.
     */
    public static function runtimeDirectory(): string
    {
        $path = (string)session_save_path();
        if (str_contains($path, ';')) $path = (string)substr($path, strrpos($path, ';') + 1);
        $path = trim($path);
        if ($path === '') $path = (string)sys_get_temp_dir();
        return $path === '' ? '' : rtrim(str_replace('\\', '/', $path), '/');
    }

    /** Фактический каталог сессий процесса; до старта сессии — из конфигурации. */
    public static function effectiveDirectory(): string
    {
        return self::$effectiveDirectory !== '' ? self::$effectiveDirectory : self::directory();
    }

    private static function samePath(string $left, string $right): bool
    {
        $resolvedLeft = realpath($left);
        $resolvedRight = realpath($right);
        if ($resolvedLeft !== false && $resolvedRight !== false) return $resolvedLeft === $resolvedRight;
        return rtrim($left, '/\\') === rtrim($right, '/\\');
    }

    public static function cookiePath(): string
    {
        $path = trim(Config::string('ROOTHTML'), '/');
        return $path === '' ? '/' : '/' . $path . '/';
    }
    public static function isSecure(array $server): bool
    {
        if (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off') return true;
        return (new \MajorDoMo\Core\NetworkAccess())->inNetworks(
            $server['REMOTE_ADDR'] ?? '',
            \MajorDoMo\Core\NetworkAccess::trustedProxies(Config::string('TRUSTED_PROXIES'))
        ) && strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
    public function csrfToken(): string
    {
        if (!is_string($_SESSION['md_csrf'] ?? null)) $_SESSION['md_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['md_csrf'];
    }
    public function checkCsrf(mixed $token): bool { return is_string($token) && hash_equals($this->csrfToken(), $token); }
    public function regenerate(): void
    {
        if (!session_regenerate_id(true)) throw new \RuntimeException('Не удалось обновить сессию.');
        unset($_SESSION['md_csrf']);
    }
    public function logout(): void
    {
        $_SESSION['md_state'] = array();
        unset($_SESSION['md_admin']);
        $this->regenerate();
    }
}
