<?php
namespace MajorDoMo\Core\Auth;
use MajorDoMo\Core\Config;

/** One locked, bounded file makes the limit independent of cookies and workers. */
final class LoginAttempts
{
    public function __construct(private string $root) {}
    public function attempt(string $ip, string $username, callable $verify, ?int $now = null): array
    {
        $now ??= time();
        return $this->locked(function (array &$state) use ($ip, $username, $verify, $now): array {
            foreach ($state as $key => $entry) if (($entry['until'] ?? 0) <= $now && ($entry['since'] ?? 0) <= $now - 900) unset($state[$key]);
            $key = hash('sha256', $ip);
            $entry = $state[$key] ?? array('since' => $now, 'count' => 0, 'until' => 0);
            if ($entry['until'] > $now) {
                $this->record('blocked', $ip, $username);
                return array('ok' => false, 'retry' => $entry['until'] - $now);
            }
            if (count($state) >= 10000 && !isset($state[$key])) {
                $this->record('capacity_blocked', $ip, $username);
                return array('ok' => false, 'retry' => 900);
            }
            if ($verify()) {
                unset($state[$key]);
                $this->record('success', $ip, $username);
                return array('ok' => true, 'retry' => 0);
            }
            if ($entry['since'] <= $now - 900) $entry = array('since' => $now, 'count' => 0, 'until' => 0);
            if (++$entry['count'] >= 3) $entry['until'] = $now + 900;
            $state[$key] = $entry;
            $this->record($entry['until'] > $now ? 'banned' : 'failure', $ip, $username);
            if ($entry['until'] > $now) {
                \MajorDoMo\Core\Events\SystemEvents::authBruteForce($this->root, $ip, $username, $entry['count'], $entry['until']);
            }
            return array('ok' => false, 'retry' => max(0, $entry['until'] - $now));
        });
    }
    public function unblock(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) throw new \InvalidArgumentException('Укажите IP-адрес.');
        $this->locked(static function (array &$state) use ($ip) { unset($state[hash('sha256', $ip)]); });
        $this->record('unblocked_cli', $ip, '');
    }
    public function record(string $event, string $ip, string $username): void
    {
        $directory = (Config::string('LOG_DIRECTORY') ?: $this->root . '/cms/debmes') . '/' . date('Y-m-d');
        self::directory($directory);
        $line = json_encode(array('time' => gmdate('c'), 'event' => $event, 'ip' => $ip, 'login' => mb_substr($username, 0, 255)), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($directory . '/auth.log', date('H:i:s') . ' ' . explode(' ', microtime())[0] . ' ' . $line, FILE_APPEND | LOCK_EX) === false) throw new \RuntimeException('Не удалось записать журнал авторизации.');
    }
    private function locked(callable $operation): mixed
    {
        $directory = $this->root . '/var/security';
        self::directory($directory);
        $file = fopen($directory . '/login-attempts.json', 'c+');
        if ($file === false) throw new \RuntimeException('Не удалось открыть защиту входа.');
        try {
            if (!flock($file, LOCK_EX)) throw new \RuntimeException('Не удалось заблокировать защиту входа.');
            $raw = stream_get_contents($file);
            if ($raw === false) throw new \RuntimeException('Не удалось прочитать защиту входа.');
            $state = $raw === '' ? array() : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($state)) throw new \RuntimeException('Повреждено хранилище защиты входа.');
            $result = $operation($state);
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) throw new \RuntimeException('Не удалось сохранить защиту входа.');
            return $result;
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
    private static function directory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new \RuntimeException('Не удалось создать каталог авторизации.');
    }
}
