<?php

namespace MajorDoMo\Core\Api;

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Users\UserAccounts;

/**
 * Временные пользовательские сеансы API, отдельно от ключей интеграций.
 * Изменения: 2026-10-23 — час без HTTP-запросов, максимум 12 часов, отзыв по паролю.
 */
final class UserTokenStore
{
    public const PATTERN = '/^mdmu_[a-f0-9]{64}$/D';
    public const IDLE = 3600;
    public const LIFETIME = 43200;

    public function __construct(private \mysqli|\PDO $connection) {}

    public static function connect(): self
    {
        try {
            if (!extension_loaded('mysqli') || Config::string('DB_HOST') === '' || Config::string('DB_NAME') === '') throw new \RuntimeException('Database not configured');
            $db = mysqli_init();
            $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            if (!$db->real_connect(Config::string('DB_HOST'), Config::string('DB_USER'), Config::string('DB_PASSWORD'), Config::string('DB_NAME'), Config::int('DB_PORT') ?: 3306) || !$db->set_charset('utf8mb4')) throw new \RuntimeException('Database unavailable');
            return new self($db);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Хранилище пользовательских токенов недоступно. Проверьте БД и миграции.');
        }
    }

    public function issue(array $user, ?int $now = null): array
    {
        $now ??= time();
        $token = 'mdmu_' . bin2hex(random_bytes(32));
        // Сутки сохраняем истёкшие записи для явного token_expired, затем
        // удаляем их при следующем входе, чтобы сеансы не накапливались вечно.
        $this->query('DELETE FROM api_user_sessions WHERE EXPIRES_AT<?', array($now - 86400));
        $this->query('INSERT INTO api_user_sessions (TOKEN_HASH,USER_ID,USER_STAMP,CREATED_AT,LAST_USED_AT,EXPIRES_AT) VALUES (?,?,?,?,?,?)',
            array(hash('sha256', $token), $user['id'], $user['stamp'], $now, $now, $now + self::LIFETIME));
        return array('access_token' => $token, 'token_type' => 'Bearer') + self::expiry($now, $now + self::LIFETIME);
    }

    /** WebSocket проверяет срок без продления: фоновый ping не означает активность. */
    public function authenticate(string $token, bool $touch = true, ?int $now = null): array
    {
        $now ??= time();
        if (!preg_match(self::PATTERN, $token)) self::denied('invalid_token', 'Недействительный пользовательский токен.');
        $rows = $this->query('SELECT ID,USER_ID,USER_STAMP,LAST_USED_AT,EXPIRES_AT,REVOKED_AT FROM api_user_sessions WHERE TOKEN_HASH=?', array(hash('sha256', $token)));
        if ($rows === array()) self::denied('invalid_token', 'Недействительный пользовательский токен.');
        $row = $rows[0];
        if ($row['REVOKED_AT'] !== null) self::denied('token_revoked', 'Сеанс отозван. Войдите снова.');
        if ((int)$row['LAST_USED_AT'] <= $now - self::IDLE || (int)$row['EXPIRES_AT'] <= $now) self::denied('token_expired', 'Срок сеанса истёк. Войдите снова.');
        $user = (new UserAccounts($this->connection))->userState((int)$row['USER_ID']);
        if ($user === null || !hash_equals($row['USER_STAMP'], $user['stamp'])) self::denied('token_revoked', 'Учётная запись изменена. Войдите снова.');
        if ($touch) {
            // Условие не позволяет параллельному старому запросу откатить время
            // активности или восстановить сеанс, уже отозванный другим запросом.
            $this->query('UPDATE api_user_sessions SET LAST_USED_AT=? WHERE ID=? AND LAST_USED_AT<? AND LAST_USED_AT>? AND EXPIRES_AT>? AND REVOKED_AT IS NULL',
                array($now, (int)$row['ID'], $now, $now - self::IDLE, $now));
            return $this->authenticate($token, false, $now);
        }
        return array('id' => (int)$row['ID'], 'user_id' => $user['id']) + self::expiry((int)$row['LAST_USED_AT'], (int)$row['EXPIRES_AT']);
    }

    public function revoke(int $id): void
    {
        $this->query('UPDATE api_user_sessions SET REVOKED_AT=? WHERE ID=? AND REVOKED_AT IS NULL', array(time(), $id));
    }

    private static function expiry(int $last, int $absolute): array
    {
        return array('expires_at' => min($last + self::IDLE, $absolute), 'absolute_expires_at' => $absolute, 'idle_timeout' => self::IDLE);
    }

    private static function denied(string $code, string $message): never
    {
        throw new ApiException(401, $code, $message, array('reauthenticate' => true), array('WWW-Authenticate' => 'Bearer error="invalid_token"'));
    }

    private function query(string $sql, array $parameters): array
    {
        $statement = null;
        try {
            $statement = $this->connection->prepare($sql);
            if ($statement === false) throw new \RuntimeException('Prepare failed');
            if ($statement instanceof \PDOStatement) {
                if (!$statement->execute($parameters)) throw new \RuntimeException('Execute failed');
                return str_starts_with($sql, 'SELECT') ? $statement->fetchAll(\PDO::FETCH_ASSOC) : array();
            }
            $types = implode('', array_map(static fn($value) => is_int($value) ? 'i' : 's', $parameters));
            if (!$statement->bind_param($types, ...$parameters) || !$statement->execute()) throw new \RuntimeException('Execute failed');
            if (!str_starts_with($sql, 'SELECT')) return array();
            $result = $statement->get_result();
            if ($result === false) throw new \RuntimeException('Result unavailable');
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (\Throwable $error) {
            // Ошибки драйвера могут содержать параметры: наружу только общий текст.
            throw new \RuntimeException('Хранилище пользовательских токенов недоступно. Проверьте БД и миграции.');
        } finally {
            if ($statement instanceof \mysqli_stmt) $statement->close();
        }
    }
}
