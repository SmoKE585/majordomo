<?php

namespace MajorDoMo\Core\Api;

use MajorDoMo\Core\Config;

/**
 * Постоянный реестр публичных webhook-входов.
 *
 * Публичная ссылка содержит секрет на 256 бит. В БД хранится только его
 * SHA-256-хеш; полный секрет возвращается один раз из create()/rotate() и
 * никогда не попадает в запросы списка. Модуль и обработчик выбирает
 * администратор, и только они определяют назначение вызова: URL, хост или
 * путь из внешнего запроса для этого не используются.
 *
 * Изменения: 2026-10-22 — добавлен реестр входов для /api/hooks/<secret>.
 */
final class HookStore
{
    public const METHODS = array('GET', 'POST', 'PUT', 'PATCH', 'DELETE');
    public const SECRET_PATTERN = '/^[a-f0-9]{64}$/D';
    public const HEADER_DENYLIST = array(
        'authorization', 'proxy-authorization', 'cookie', 'host', 'content-length',
        'content-type', 'transfer-encoding', 'connection', 'x-api-key', 'x-csrf-token',
    );

    public function __construct(private \mysqli|\PDO $connection, private ?string $root = null) {}

    /** Нужен публичному входу до того, как легаси-загрузчик откроет своё соединение. */
    public static function connect(): self
    {
        try {
            if (!extension_loaded('mysqli') || Config::string('DB_HOST') === '' || Config::string('DB_NAME') === '') {
                throw new \RuntimeException('Database not configured');
            }
            $db = mysqli_init();
            $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            if (!$db->real_connect(
                Config::string('DB_HOST'),
                Config::string('DB_USER'),
                Config::string('DB_PASSWORD'),
                Config::string('DB_NAME'),
                Config::int('DB_PORT') ?: 3306
            ) || !$db->set_charset('utf8mb4')) {
                throw new \RuntimeException('Database unavailable');
            }
            return new self($db, Config::root());
        } catch (\Throwable $error) {
            throw new \RuntimeException('Не удалось подключиться к хранилищу webhook.');
        }
    }

    /** Публичный список. Хеши секретов намеренно не выбираются. */
    public function all(): array
    {
        return $this->rows(
            'SELECT ID,NAME,MODULE,HANDLER,METHODS,HEADER_NAME,ENABLED,CREATED_AT,UPDATED_AT,LAST_USED_AT,LAST_STATUS,LAST_METHOD '
            . 'FROM api_hooks ORDER BY ID DESC'
        );
    }

    public function find(int $id): array
    {
        $rows = $id > 0 ? $this->rows('SELECT * FROM api_hooks WHERE ID=?', array($id)) : array();
        if ($rows === array()) {
            throw new \InvalidArgumentException('Вход не найден.');
        }
        return $rows[0];
    }

    /** Поиск для публичного входа: сам секрет в SQL не передаётся. */
    public function findBySecret(string $secret): ?array
    {
        if (!preg_match(self::SECRET_PATTERN, $secret)) {
            return null;
        }
        $rows = $this->rows(
            'SELECT ID,NAME,MODULE,HANDLER,METHODS,HEADER_NAME,HEADER_SECRET_HASH,ENABLED,LAST_USED_AT,LAST_STATUS,LAST_METHOD '
            . 'FROM api_hooks WHERE SECRET_HASH=?',
            array(hash('sha256', $secret))
        );
        return $rows[0] ?? null;
    }

    /**
     * @param string[] $methods
     * @return array{id:int,secret:string,header_name:string,header_secret:?string}
     */
    public function create(string $name, string $module, string $handler, array $methods, string $headerName = ''): array
    {
        $name = self::name($name);
        $module = self::module($module);
        $handler = self::handler($handler);
        $methods = self::normalizeMethods($methods);
        $headerName = self::headerName($headerName);
        $this->assertDestination($module, $handler);
        $now = time();
        $secret = self::secret();
        $headerSecret = $headerName !== '' ? self::secret() : null;
        $this->execute(
            'INSERT INTO api_hooks (NAME,MODULE,HANDLER,SECRET_HASH,SECRET_PREFIX,METHODS,HEADER_NAME,HEADER_SECRET_HASH,ENABLED,CREATED_AT,UPDATED_AT) '
            . 'VALUES (?,?,?,?,?,?,?,?,1,?,?)',
            array(
                $name,
                $module,
                $handler,
                hash('sha256', $secret),
                substr($secret, 0, 8),
                implode(',', $methods),
                $headerName !== '' ? $headerName : null,
                $headerSecret !== null ? hash('sha256', $headerSecret) : null,
                $now,
                $now,
            )
        );
        return array(
            'id' => $this->lastInsertId(),
            'secret' => $secret,
            'header_name' => $headerName,
            'header_secret' => $headerSecret,
        );
    }

    /**
     * Меняет название, назначение и разрешённые методы. При смене имени
     * заголовка создаётся новый секрет; прежний заголовок продолжает работать,
     * пока имя не изменилось.
     *
     * @param string[] $methods
     * @return array{header_name:string,header_secret:?string}
     */
    public function update(int $id, string $name, string $module, string $handler, array $methods, string $headerName = ''): array
    {
        $record = $this->find($id);
        $name = self::name($name);
        $module = self::module($module);
        $handler = self::handler($handler);
        $methods = self::normalizeMethods($methods);
        $headerName = self::headerName($headerName);
        $this->assertDestination($module, $handler);

        $headerSecret = null;
        $headerHash = null;
        if ($headerName !== '') {
            if ($record['HEADER_NAME'] === $headerName && $record['HEADER_SECRET_HASH'] !== null) {
                $headerHash = $record['HEADER_SECRET_HASH'];
            } else {
                $headerSecret = self::secret();
                $headerHash = hash('sha256', $headerSecret);
            }
        }

        $this->execute(
            'UPDATE api_hooks SET NAME=?,MODULE=?,HANDLER=?,METHODS=?,HEADER_NAME=?,HEADER_SECRET_HASH=?,UPDATED_AT=? WHERE ID=?',
            array($name, $module, $handler, implode(',', $methods), $headerName !== '' ? $headerName : null, $headerHash, time(), $id)
        );
        return array('header_name' => $headerName, 'header_secret' => $headerSecret);
    }

    /** Перевыпускает секрет ссылки. Compare-and-swap не даёт двум админам перезаписать друг друга. */
    public function rotate(int $id): array
    {
        $record = $this->find($id);
        $secret = self::secret();
        $affected = $this->execute(
            'UPDATE api_hooks SET SECRET_HASH=?,SECRET_PREFIX=?,UPDATED_AT=?,LAST_USED_AT=NULL,LAST_STATUS=NULL,LAST_METHOD=NULL '
            . 'WHERE ID=? AND SECRET_HASH=?',
            array(hash('sha256', $secret), substr($secret, 0, 8), time(), $id, $record['SECRET_HASH'])
        );
        if ($affected !== 1) {
            throw new \RuntimeException('Вход уже изменён. Обновите страницу.');
        }
        return array('id' => $id, 'secret' => $secret);
    }

    /** Перевыпускает только дополнительный секрет заголовка; ссылка остаётся прежней. */
    public function rotateHeader(int $id): array
    {
        $record = $this->find($id);
        if ((string)($record['HEADER_NAME'] ?? '') === '') {
            throw new \InvalidArgumentException('Сначала укажите заголовок дополнительного секрета.');
        }
        $secret = self::secret();
        $hash = hash('sha256', $secret);
        if ($record['HEADER_SECRET_HASH'] === null) {
            $affected = $this->execute(
                'UPDATE api_hooks SET HEADER_SECRET_HASH=?,UPDATED_AT=? WHERE ID=? AND HEADER_SECRET_HASH IS NULL',
                array($hash, time(), $id)
            );
        } else {
            $affected = $this->execute(
                'UPDATE api_hooks SET HEADER_SECRET_HASH=?,UPDATED_AT=? WHERE ID=? AND HEADER_SECRET_HASH=?',
                array($hash, time(), $id, $record['HEADER_SECRET_HASH'])
            );
        }
        if ($affected !== 1) {
            throw new \RuntimeException('Вход уже изменён. Обновите страницу.');
        }
        return array('id' => $id, 'header_name' => (string)$record['HEADER_NAME'], 'header_secret' => $secret);
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $this->find($id);
        $this->execute('UPDATE api_hooks SET ENABLED=?,UPDATED_AT=? WHERE ID=?', array($enabled ? 1 : 0, time(), $id));
    }

    public function delete(int $id): void
    {
        $this->find($id);
        $this->execute('DELETE FROM api_hooks WHERE ID=?', array($id));
    }

    /** Диагностика: только время, статус и метод; без тела, заголовков и URL. */
    public function touch(int $id, int $status, string $method = ''): void
    {
        $method = strtoupper($method);
        if (!in_array($method, self::METHODS, true)) {
            $method = '';
        }
        $this->execute(
            'UPDATE api_hooks SET LAST_USED_AT=?,LAST_STATUS=?,LAST_METHOD=? WHERE ID=?',
            array(time(), $status, $method !== '' ? $method : null, $id)
        );
    }

    /** @return string[] */
    public static function methods(array $record): array
    {
        $methods = array();
        foreach (explode(',', (string)($record['METHODS'] ?? '')) as $method) {
            $method = strtoupper(trim($method));
            if (in_array($method, self::METHODS, true) && !in_array($method, $methods, true)) {
                $methods[] = $method;
            }
        }
        return $methods;
    }

    /** @param string[] $methods @return string[] */
    public static function normalizeMethods(array $methods): array
    {
        $normalized = array();
        foreach ($methods as $method) {
            $method = strtoupper(trim((string)$method));
            if (!in_array($method, self::METHODS, true)) {
                throw new \InvalidArgumentException('Недопустимый HTTP-метод.');
            }
            if (!in_array($method, $normalized, true)) {
                $normalized[] = $method;
            }
        }
        if ($normalized === array()) {
            throw new \InvalidArgumentException('Выберите хотя бы один HTTP-метод.');
        }
        return $normalized;
    }

    /** Проверяет установку модуля и реальный путь обработчика ещё при сохранении. */
    private function assertDestination(string $module, string $handler): void
    {
        if ($this->root === null || $this->root === '') {
            return;
        }
        try {
            HookRegistry::resolve($this->root, $module, $handler);
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('Выберите установленный модуль и существующий PHP-файл обработчика.');
        }
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 190 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new \InvalidArgumentException('Введите название входа: до 190 символов.');
        }
        return $name;
    }

    private static function module(string $module): string
    {
        $module = trim($module);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/D', $module)) {
            throw new \InvalidArgumentException('Выберите установленный модуль.');
        }
        return $module;
    }

    private static function handler(string $handler): string
    {
        $handler = trim($handler);
        if ($handler === '' || strlen($handler) > 255 || preg_match('/[\x00-\x1f\x7f\\\\:]/', $handler)) {
            throw new \InvalidArgumentException('Укажите PHP-файл обработчика внутри модуля.');
        }
        if (!preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*\.php$#D', $handler)) {
            throw new \InvalidArgumentException('Обработчик должен быть относительным путём к .php-файлу.');
        }
        foreach (explode('/', $handler) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Путь обработчика выходит за пределы модуля.');
            }
        }
        return $handler;
    }

    private static function headerName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,99}$/D', $name)) {
            throw new \InvalidArgumentException('Имя заголовка: латинские буквы, цифры и дефис, до 100 символов.');
        }
        if (in_array(strtolower($name), self::HEADER_DENYLIST, true)) {
            throw new \InvalidArgumentException('Этот заголовок нельзя использовать для дополнительного секрета.');
        }
        return $name;
    }

    private static function secret(): string
    {
        // 32 случайных байта = 256 бит. Hex не требует экранирования в URL.
        return bin2hex(random_bytes(32));
    }

    private function lastInsertId(): int
    {
        return $this->connection instanceof \PDO
            ? (int)$this->connection->lastInsertId()
            : (int)$this->connection->insert_id;
    }

    private function rows(string $sql, array $params = array()): array
    {
        $statement = $this->statement($sql, $params);
        try {
            if ($statement instanceof \PDOStatement) {
                return $statement->fetchAll(\PDO::FETCH_ASSOC);
            }
            $result = $statement->get_result();
            if ($result === false) {
                throw new \RuntimeException('Result unavailable');
            }
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            if ($statement instanceof \mysqli_stmt) {
                $statement->close();
            }
        }
    }

    private function execute(string $sql, array $params): int
    {
        $statement = $this->statement($sql, $params);
        try {
            return $statement instanceof \PDOStatement ? $statement->rowCount() : $statement->affected_rows;
        } finally {
            if ($statement instanceof \mysqli_stmt) {
                $statement->close();
            }
        }
    }

    private function statement(string $sql, array $params): \PDOStatement|\mysqli_stmt
    {
        try {
            $statement = $this->connection->prepare($sql);
            if ($statement === false) {
                throw new \RuntimeException('Prepare failed');
            }
            if ($statement instanceof \PDOStatement) {
                if (!$statement->execute($params)) {
                    throw new \RuntimeException('Execute failed');
                }
            } else {
                if ($params !== array()) {
                    $types = implode('', array_map(static fn($value) => is_int($value) ? 'i' : 's', $params));
                    if (!$statement->bind_param($types, ...$params)) {
                        throw new \RuntimeException('Bind failed');
                    }
                }
                if (!$statement->execute()) {
                    throw new \RuntimeException('Execute failed');
                }
            }
            return $statement;
        } catch (\Throwable $error) {
            throw new \RuntimeException('Хранилище webhook недоступно. Проверьте установку раздела API и схему БД.');
        }
    }
}
