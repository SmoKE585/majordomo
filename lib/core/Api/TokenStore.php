<?php

namespace MajorDoMo\Core\Api;

use MajorDoMo\Core\Config;

/** Plaintext tokens are returned only by issue/rotate. The database stores SHA-256. */
final class TokenStore
{
    public function __construct(private \mysqli|\PDO $connection) {}

    public static function connect(): self
    {
        try {
            if (!extension_loaded('mysqli') || Config::string('DB_HOST') === '' || Config::string('DB_NAME') === '') throw new \RuntimeException('Database not configured');
            $db = mysqli_init();
            $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            if (!$db->real_connect(Config::string('DB_HOST'), Config::string('DB_USER'), Config::string('DB_PASSWORD'), Config::string('DB_NAME'), Config::int('DB_PORT') ?: 3306) || !$db->set_charset('utf8mb4')) throw new \RuntimeException('Database unavailable');
            return new self($db);
        } catch (\Throwable $error) { throw new \RuntimeException('Не удалось подключиться к хранилищу API-токенов.'); }
    }

    public function all(): array
    {
        return $this->rows('SELECT ID,NAME,PREFIX,CREATED_AT,EXPIRES_AT,REVOKED_AT,LAST_USED_AT FROM api_tokens ORDER BY ID DESC');
    }

    public function issue(string $name, ?int $expires = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 190 || preg_match('/[\x00-\x1f\x7f]/', $name)) throw new \InvalidArgumentException('Введите название токена: до 190 символов.');
        if ($expires !== null && $expires <= time()) throw new \InvalidArgumentException('Срок действия должен быть в будущем.');
        $token = 'mdm_' . bin2hex(random_bytes(32));
        $this->execute('INSERT INTO api_tokens (NAME,TOKEN_HASH,PREFIX,CREATED_AT,EXPIRES_AT) VALUES (?,?,?,?,?)', array($name, hash('sha256', $token), substr($token, 0, 12), time(), $expires));
        $id = $this->connection instanceof \PDO ? (int)$this->connection->lastInsertId() : (int)$this->connection->insert_id;
        return array('id' => $id, 'token' => $token);
    }

    public function revoke(int $id): void
    {
        $this->find($id);
        $this->execute('UPDATE api_tokens SET REVOKED_AT=? WHERE ID=? AND REVOKED_AT IS NULL', array(time(), $id));
    }

    public function rotate(int $id): array
    {
        $record = $this->find($id);
        if ($record['REVOKED_AT'] !== null) throw new \InvalidArgumentException('Отозванный токен нельзя перевыпустить. Создайте новый.');
        if ($record['EXPIRES_AT'] !== null && (int)$record['EXPIRES_AT'] <= time()) throw new \InvalidArgumentException('Срок действия истёк. Создайте новый токен.');
        $token = 'mdm_' . bin2hex(random_bytes(32));
        $affected = $this->execute('UPDATE api_tokens SET TOKEN_HASH=?,PREFIX=?,CREATED_AT=?,LAST_USED_AT=NULL WHERE ID=? AND TOKEN_HASH=? AND REVOKED_AT IS NULL', array(hash('sha256', $token), substr($token, 0, 12), time(), $id, $record['TOKEN_HASH']));
        if ($affected !== 1) throw new \RuntimeException('Токен уже изменён. Обновите страницу.');
        return array('id' => $id, 'token' => $token);
    }

    public function verify(string $token): bool
    {
        if (!preg_match('/^mdm_[a-f0-9]{64}$/D', $token)) return false;
        $now = time();
        $rows = $this->rows('SELECT ID,LAST_USED_AT FROM api_tokens WHERE TOKEN_HASH=? AND REVOKED_AT IS NULL AND (EXPIRES_AT IS NULL OR EXPIRES_AT>?)', array(hash('sha256', $token), $now));
        if ($rows === array()) return false;
        if ((int)$rows[0]['LAST_USED_AT'] < $now - 60) $this->execute('UPDATE api_tokens SET LAST_USED_AT=? WHERE ID=? AND REVOKED_AT IS NULL', array($now, (int)$rows[0]['ID']));
        return true;
    }

    private function find(int $id): array
    {
        $rows = $id > 0 ? $this->rows('SELECT ID,TOKEN_HASH,EXPIRES_AT,REVOKED_AT FROM api_tokens WHERE ID=?', array($id)) : array();
        if ($rows === array()) throw new \InvalidArgumentException('Токен не найден.');
        return $rows[0];
    }

    private function rows(string $sql, array $params = array()): array
    {
        $statement = $this->statement($sql, $params);
        try {
            if ($statement instanceof \PDOStatement) return $statement->fetchAll(\PDO::FETCH_ASSOC);
            $result = $statement->get_result();
            if ($result === false) throw new \RuntimeException('Result unavailable');
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally { if ($statement instanceof \mysqli_stmt) $statement->close(); }
    }

    private function execute(string $sql, array $params): int
    {
        $statement = $this->statement($sql, $params);
        try { return $statement instanceof \PDOStatement ? $statement->rowCount() : $statement->affected_rows; }
        finally { if ($statement instanceof \mysqli_stmt) $statement->close(); }
    }

    private function statement(string $sql, array $params): \PDOStatement|\mysqli_stmt
    {
        try {
            $statement = $this->connection->prepare($sql);
            if ($statement === false) throw new \RuntimeException('Prepare failed');
            if ($statement instanceof \PDOStatement) {
                if (!$statement->execute($params)) throw new \RuntimeException('Execute failed');
            } else {
                if ($params !== array()) {
                    $types = implode('', array_map(static fn($value) => is_int($value) ? 'i' : 's', $params));
                    if (!$statement->bind_param($types, ...$params)) throw new \RuntimeException('Bind failed');
                }
                if (!$statement->execute()) throw new \RuntimeException('Execute failed');
            }
            return $statement;
        } catch (\Throwable $error) { throw new \RuntimeException('Хранилище API-токенов недоступно. Проверьте установку раздела API и схему БД.'); }
    }
}
