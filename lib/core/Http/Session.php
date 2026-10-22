<?php
namespace MajorDoMo\Core\Http;
use MajorDoMo\Core\Config;

/** Native cookie-only sessions. Authentication is separate from UI state. */
final class Session
{
    public string $name;

    public function __construct(string $name = 'prj', ?string $root = null)
    {
        $this->name = (string)$name;
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/D', $this->name)) throw new \InvalidArgumentException('Недопустимое имя сессии.');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (headers_sent()) throw new \RuntimeException('Сессия должна запускаться до вывода ответа.');
            $directory = rtrim($root ?? Config::string('ROOT'), '/\\') . '/var/sessions';
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Не удалось создать хранилище сессий.');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_cookies', '1');
            ini_set('session.use_trans_sid', '0');
            ini_set('session.gc_maxlifetime', '43200');
            session_save_path($directory);
            session_name($this->name);
            session_set_cookie_params(array('lifetime' => 0, 'path' => self::cookiePath(), 'domain' => '', 'secure' => self::isSecure($_SERVER), 'httponly' => true, 'samesite' => 'Strict'));
            if (!session_start()) throw new \RuntimeException('Не удалось открыть сессию.');
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

    public static function cookiePath(): string
    {
        $path = trim(Config::string('ROOTHTML'), '/');
        return $path === '' ? '/' : '/' . $path . '/';
    }
    public static function isSecure(array $server): bool
    {
        if (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off') return true;
        return (new \MajorDoMo\Core\NetworkAccess())->inNetworks($server['REMOTE_ADDR'] ?? '', Config::string('TRUSTED_PROXIES'))
            && strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
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
