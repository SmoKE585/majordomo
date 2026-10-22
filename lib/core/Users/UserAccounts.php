<?php

namespace MajorDoMo\Core\Users;

use MajorDoMo\Core\Config;

/** User administration without the legacy loader. No password/hash in returned rows. */
final class UserAccounts
{
    /** PDO supports isolated SQLite tests; production uses the existing mysqli extension. */
    public function __construct(private \mysqli|\PDO $connection) {}

    public static function connect(): self
    {
        if (!extension_loaded('mysqli') || Config::string('DB_HOST') === '' || Config::string('DB_NAME') === '') {
            throw new \RuntimeException('Для работы с пользователями настройте DB_* и расширение mysqli.');
        }
        try {
            $db = mysqli_init();
            $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            if (!$db->real_connect(Config::string('DB_HOST'), Config::string('DB_USER'), Config::string('DB_PASSWORD'), Config::string('DB_NAME'), Config::int('DB_PORT') ?: 3306)) {
                throw new \RuntimeException('Connection failed');
            }
            if (!$db->set_charset('utf8mb4')) throw new \RuntimeException('Charset failed');
            return new self($db);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Не удалось подключиться к БД пользователей. Проверьте DB_* и доступность MySQL.');
        }
    }

    public function page(string $search, int $limit, int $offset): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0) throw new \InvalidArgumentException('Недопустимые параметры страницы пользователей');
        $where = $search === '' ? '' : ' WHERE (USERNAME LIKE ? OR NAME LIKE ?)';
        $parameters = $search === '' ? array() : array('%' . $search . '%', '%' . $search . '%');
        $total = (int)$this->rows('SELECT COUNT(*) AS total FROM users' . $where, $parameters)[0]['total'];
        // Integers are validated above; never interpolate arbitrary input into LIMIT/OFFSET.
        $users = $this->rows('SELECT ID, USERNAME, NAME, IS_ADMIN, IS_DEFAULT FROM users' . $where . ' ORDER BY ID LIMIT ' . $limit . ' OFFSET ' . $offset, $parameters);
        return array('users' => $users, 'total' => $total, 'limit' => $limit, 'offset' => $offset);
    }

    public function find(int $id): array
    {
        $users = $this->rows('SELECT ID, USERNAME, NAME, IS_ADMIN, IS_DEFAULT FROM users WHERE ID = ?', array($id));
        if ($users === array()) throw new \InvalidArgumentException('Пользователь с ID ' . $id . ' не найден. Список: php majordomo users:list');
        return $users[0];
    }

    public function changePassword(int $id, string $password): array
    {
        Passwords::validate($password);
        $user = $this->find($id);
        $hash = Passwords::hash($password);
        $statement = $this->statement('UPDATE users SET PASSWORD = ? WHERE ID = ?', array($hash, $id));
        $affected = $statement instanceof \PDOStatement ? $statement->rowCount() : $statement->affected_rows;
        if ($statement instanceof \mysqli_stmt) $statement->close();
        // MySQL reports 0 if the same password was already stored. Confirm the
        // selected account still exists without ever returning its hash.
        if ($affected === 0) $this->find($id);
        return $user;
    }

    /** Authentication-only state; never expose the fingerprint in HTTP responses. */
    public function adminState(int $id): ?array
    {
        $rows = $this->rows('SELECT ID,USERNAME,NAME,PASSWORD,IS_ADMIN FROM users WHERE ID=?', array($id));
        return count($rows) === 1 ? self::state($rows[0]) : null;
    }

    public function verifyAdmin(string $username, string $password): ?array
    {
        if ($username === '' || strlen($username) > 1020 || strlen($password) > 1024) return null;
        $rows = $this->rows('SELECT ID,USERNAME,NAME,PASSWORD,IS_ADMIN FROM users WHERE USERNAME=? LIMIT 2', array($username));
        if (count($rows) !== 1 || !Passwords::verify($password, (string)$rows[0]['PASSWORD']) || (int)$rows[0]['IS_ADMIN'] !== 1) return null;
        $user = $rows[0];
        if (Passwords::needsRehash($user['PASSWORD'])) {
            $hash = Passwords::hash($password);
            $statement = $this->statement('UPDATE users SET PASSWORD=? WHERE ID=? AND PASSWORD=? AND IS_ADMIN=1', array($hash, (int)$user['ID'], $user['PASSWORD']));
            $affected = $statement instanceof \PDOStatement ? $statement->rowCount() : $statement->affected_rows;
            if ($statement instanceof \mysqli_stmt) $statement->close();
            if ($affected !== 1) return null;
            $user['PASSWORD'] = $hash;
        }
        return self::state($user);
    }

    private static function state(array $user): ?array
    {
        if ((int)$user['IS_ADMIN'] !== 1 || $user['PASSWORD'] === '' || hash_equals(hash('sha512', ''), $user['PASSWORD'])) return null;
        return array('id' => (int)$user['ID'], 'username' => $user['USERNAME'], 'name' => $user['NAME'], 'stamp' => hash('sha256', $user['PASSWORD'] . "\0" . $user['USERNAME']));
    }

    private function rows(string $sql, array $parameters): array
    {
        $statement = $this->statement($sql, $parameters);
        try {
            if ($statement instanceof \PDOStatement) return $statement->fetchAll(\PDO::FETCH_ASSOC);
            $result = $statement->get_result();
            if ($result === false) throw new \RuntimeException('Result unavailable');
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Не удалось прочитать пользователей из таблицы users.');
        } finally {
            if ($statement instanceof \mysqli_stmt) $statement->close();
        }
    }

    private function statement(string $sql, array $parameters): \PDOStatement|\mysqli_stmt
    {
        try {
            $statement = $this->connection->prepare($sql);
            if ($statement === false) throw new \RuntimeException('Prepare failed');
            if ($statement instanceof \PDOStatement) {
                if (!$statement->execute($parameters)) throw new \RuntimeException('Execute failed');
            } else {
                if ($parameters !== array()) {
                    $types = implode('', array_map(static fn($value) => is_int($value) ? 'i' : 's', $parameters));
                    if (!$statement->bind_param($types, ...$parameters)) throw new \RuntimeException('Bind failed');
                }
                if (!$statement->execute()) throw new \RuntimeException('Execute failed');
            }
            return $statement;
        } catch (\Throwable $error) {
            // SQL errors may contain bound data; never pass them to CLI output.
            throw new \RuntimeException('Операция с таблицей users не выполнена. Проверьте схему и права доступа к БД.');
        }
    }
}
