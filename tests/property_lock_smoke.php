<?php

/**
 * 2026-11-11 — блокировка принадлежит SQL-соединению и не переживает его обрыв.
 * По умолчанию SQLite :memory: с моделью advisory locks; режим MySQL предназначен
 * только для отдельного контейнера без сети и рабочих томов.
 */
namespace MajorDoMo\Core {
    final class Config {
        public static bool $redis = true;
        public static function get($key) { return $key === 'USE_REDIS' && self::$redis; }
    }
    final class RedisConnection {
        public string $prefix = 'fixture:';
        public function key($suffix) { return $this->prefix . $suffix; }
        public function requireConnection() { throw new \RuntimeException('Блокировка не должна обращаться к Redis'); }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') exit(2);
    putenv('APP_ENV=testing');
    error_reporting(E_ALL);
    set_error_handler(static function ($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    require dirname(__DIR__) . '/lib/core/Database.php';
    require dirname(__DIR__) . '/lib/core/PropertyLock.php';

    use MajorDoMo\Core\{Config, Database, PropertyLock, RedisConnection};

    $mysql = getenv('MJD_TEST_MYSQL_DSN');
    if ($mysql !== false && $mysql !== 'mysql:host=127.0.0.1;port=3306') {
        throw new RuntimeException('Разрешён только изолированный MySQL на loopback контейнера');
    }
    if (!$mysql) {
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
    }
    $owners = array();

    /** Адаптер тестового соединения; рабочая конфигурация не загружается. */
    final class PropertyLockTestConnection {
        private ?PDO $pdo;
        private string $id;
        public function __construct() {
            global $mysql, $owners;
            $this->id = bin2hex(random_bytes(8));
            $this->pdo = new PDO($mysql ?: 'sqlite::memory:', $mysql ? 'root' : null, null,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            if ($mysql) return;
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Isolation failed');
            $id = $this->id;
            $this->pdo->sqliteCreateFunction('CONNECTION_ID', static fn() => $id);
            $this->pdo->sqliteCreateFunction('IS_USED_LOCK', static function ($key) use (&$owners) {
                return $owners[$key]['owner'] ?? null;
            });
            $this->pdo->sqliteCreateFunction('GET_LOCK', static function ($key, $timeout) use (&$owners, $id) {
                if (isset($owners[$key]) && $owners[$key]['owner'] !== $id) return 0;
                $owners[$key] = array('owner' => $id, 'depth' => ($owners[$key]['depth'] ?? 0) + 1);
                return 1;
            });
            $this->pdo->sqliteCreateFunction('RELEASE_LOCK', static function ($key) use (&$owners, $id) {
                if (($owners[$key]['owner'] ?? null) !== $id) return 0;
                if (--$owners[$key]['depth'] === 0) unset($owners[$key]);
                return 1;
            });
        }
        public function SelectOne($sql) { return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); }
        public function DbSafe1($value) { return substr($this->pdo->quote((string)$value), 1, -1); }
        public function close(): void {
            global $owners;
            $this->pdo = null;
            foreach ($owners as $key => $owner) if ($owner['owner'] === $this->id) unset($owners[$key]);
        }
    }

    $connection = new PropertyLockTestConnection();
    $redis = new RedisConnection();
    $first = new PropertyLock(new Database($connection), $redis);
    if (($argv[1] ?? '') === '--hold') {
        if (!$mysql) throw new RuntimeException('Дочерний процесс требует изолированную MariaDB');
        $first->acquire(999, 'crash');
        echo "LOCKED\n";
        fflush(STDOUT);
        sleep(30);
        exit(3);
    }
    $otherConnection = new PropertyLockTestConnection();
    $second = new PropertyLock(new Database($otherConnection), $redis);
    $checks = 0;
    function check($condition, $message) {
        if (!$condition) throw new RuntimeException('FAILED: ' . $message);
        $GLOBALS['checks']++;
    }
    function denied(callable $action, string $fragment): void {
        try { $action(); } catch (RuntimeException $e) {
            check(str_contains($e->getMessage(), $fragment), 'Причина отказа: ' . $e->getMessage());
            return;
        }
        throw new RuntimeException('Ожидался отказ блокировки');
    }

    $lock = $first->acquire(1, 'status');
    check($lock[2] === 'sql', 'Даже при включённом Redis используется SQL-блокировка');
    $first->check($lock);
    denied(fn() => $second->acquire(1, 'STATUS'), 'STATUS');
    $independent = $second->acquire(2, 'status');
    $second->check($independent);
    $second->release($independent);
    check(true, 'Другой объект не блокируется');
    $nested = $first->acquire(1, 'status');
    $first->release($nested);
    $first->check($lock);
    denied(fn() => $second->acquire(1, 'status'), 'status');
    $first->release($lock);
    $next = $second->acquire(1, 'status');
    denied(fn() => $first->check($lock), 'блокировк');
    $first->release($lock);
    $second->check($next);
    $second->release($next);
    check(true, 'Бывший владелец не снимает чужую блокировку');

    $first->acquire(3, 'connection');
    $connection->close();
    $recovered = $second->acquire(3, 'connection');
    $second->check($recovered);
    $second->release($recovered);
    check(true, 'Закрытие соединения освобождает блокировку без ожидания TTL');

    Config::$redis = false;
    $withoutRedis = $second->acquire(4, 'status');
    $second->check($withoutRedis);
    $second->release($withoutRedis);
    check(true, 'Одинаковая защита с Redis и без него');
    $redis->prefix = str_repeat('long-prefix:', 15);
    $long = $second->acquire(5, 'status');
    $different = $second->acquire(5, 'temperature');
    check(strlen($long[0]) <= 64 && $long[0] !== $different[0], 'Длинный префикс не обрезает идентификатор свойства');
    $second->release($long);
    $second->release($different);

    if ($mysql) {
        $process = proc_open(array(PHP_BINARY, __FILE__, '--hold'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Не удалось запустить дочерний процесс');
        try {
            check(trim((string)fgets($pipes[1])) === 'LOCKED', 'Дочерний процесс захватил блокировку');
            proc_terminate($process, 9);
            foreach ($pipes as $pipe) fclose($pipe);
            proc_close($process);
            $process = null;
            $redis->prefix = 'fixture:';
            $afterCrash = $second->acquire(999, 'crash');
            $second->check($afterCrash);
            $second->release($afterCrash);
            check(true, 'SIGKILL владельца не оставляет блокировку на две минуты');
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                proc_close($process);
            }
        }
    }
    echo 'OK: ' . $checks . ' property lock checks (' . ($mysql ? 'MariaDB' : 'SQLite :memory:') . ")\n";
}
