<?php

namespace MajorDoMo\Core\Install;

/**
 * Minimal, dependency-free MySQL/MariaDB access for the setup wizard.
 *
 * The wizard intentionally does not boot the legacy loader: the database may
 * not exist yet, credentials may be wrong, and a fatal MySQL error must become
 * a readable validation message instead of a blank page.
 *
 * All writes are prepared statements; table names come from a fixed whitelist.
 */
final class InstallDatabase
{
    private ?\mysqli $db = null;
    private string $error = '';
    private string $serverInfo = '';

    /**
     * @param array{host?:string,port?:string|int,name?:string,user?:string,password?:string} $config
     */
    public function __construct(private array $config)
    {
    }

    public function connect(): bool
    {
        $this->error = '';
        $this->serverInfo = '';
        $this->db = null;

        if (!function_exists('mysqli_connect')) {
            $this->error = 'Расширение PHP mysqli не установлено. Без него MajorDoMo не сможет работать с базой данных.';
            return false;
        }

        $host = trim((string)($this->config['host'] ?? ''));
        $name = trim((string)($this->config['name'] ?? ''));
        $user = (string)($this->config['user'] ?? '');
        $password = (string)($this->config['password'] ?? '');
        $port = (int)($this->config['port'] ?? 0);
        if ($port <= 0) {
            $port = 3306;
        }

        if ($host === '') {
            $this->error = 'Не указан сервер базы данных.';
            return false;
        }
        if ($name === '') {
            $this->error = 'Не указано имя базы данных.';
            return false;
        }

        // PHP 8.1+ reports mysqli failures as exceptions by default. The wizard
        // wants to print a friendly message, so switch to the legacy mode.
        @mysqli_report(MYSQLI_REPORT_OFF);
        // A wrong host on the first step must not leave the browser hanging for
        // the default 60 seconds. Five seconds is enough for a local/DB network.
        @ini_set('mysqli.connect_timeout', '5');

        try {
            $db = @new \mysqli($host, $user, $password, $name, $port);
        } catch (\Throwable $e) {
            $this->error = 'Не удалось подключиться к MySQL: ' . $e->getMessage();
            return false;
        }

        if ($db->connect_errno) {
            $this->error = 'Не удалось подключиться к MySQL: ' . $this->friendlyError((int)$db->connect_errno, (string)$db->connect_error);
            return false;
        }

        if (!@$db->set_charset('utf8mb4')) {
            @$db->set_charset('utf8');
        }
        $this->db = $db;
        $this->serverInfo = (string)$db->server_info;
        return true;
    }

    public function isConnected(): bool
    {
        return $this->db instanceof \mysqli;
    }

    public function error(): string
    {
        return $this->error;
    }

    public function serverInfo(): string
    {
        return $this->serverInfo;
    }

    public function close(): void
    {
        if ($this->db instanceof \mysqli) {
            @$this->db->close();
        }
        $this->db = null;
    }

    /**
     * @return string[]
     */
    public function tables(): array
    {
        $result = $this->query('SHOW TABLES');
        if (!is_array($result)) {
            return array();
        }
        $tables = array();
        foreach ($result as $row) {
            $value = array_values($row);
            if (isset($value[0])) {
                $tables[] = (string)$value[0];
            }
        }
        sort($tables);
        return $tables;
    }

    public function tableExists(string $table): bool
    {
        if (!$this->db instanceof \mysqli) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
            return false;
        }
        // MariaDB does not accept a placeholder in SHOW TABLES LIKE; the name
        // is whitelisted above, and real_escape_string keeps the literal safe.
        $escaped = $this->db->real_escape_string($table);
        $result = @$this->db->query("SHOW TABLES LIKE '" . $escaped . "'");
        $exists = $result instanceof \mysqli_result && $result->num_rows > 0;
        if ($result instanceof \mysqli_result) {
            $result->free();
        }
        return $exists;
    }

    /**
     * @param string[] $names
     * @return array<string,string>
     */
    public function fetchSettings(array $names): array
    {
        if (!$this->db instanceof \mysqli || $names === array() || !$this->tableExists('settings')) {
            return array();
        }
        $names = array_values(array_filter($names, static function (string $name): bool {
            return $name !== '';
        }));
        if ($names === array()) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $statement = @$this->db->prepare('SELECT `NAME`, `VALUE` FROM settings WHERE `NAME` IN (' . $placeholders . ')');
        if (!$statement) {
            return array();
        }
        $types = str_repeat('s', count($names));
        $statement->bind_param($types, ...$names);
        $statement->execute();
        $statement->bind_result($settingName, $settingValue);
        $values = array();
        while ($statement->fetch()) {
            $values[(string)$settingName] = (string)$settingValue;
        }
        $statement->close();
        return $values;
    }

    /**
     * Import the clean installation schema into an empty database.
     *
     * The file contains only CREATE TABLE / ALTER TABLE statements: no demo
     * data, no users and no seed counters. It is used only when the wizard
     * proved that the selected database is empty.
     *
     * @return array{ok:bool,error:string,statements:int}
     */
    public function importSchema(string $path): array
    {
        $result = array('ok' => false, 'error' => '', 'statements' => 0);
        if (!$this->db instanceof \mysqli) {
            $result['error'] = 'Нет соединения с базой данных.';
            return $result;
        }
        if (!is_file($path) || !is_readable($path)) {
            $result['error'] = 'Файл чистой схемы не найден: ' . $path;
            return $result;
        }
        $sql = @file_get_contents($path);
        if (!is_string($sql) || trim($sql) === '') {
            $result['error'] = 'Файл чистой схемы пуст или недоступен для чтения.';
            return $result;
        }

        // Защита отладочного стенда: схема не должна удалять базу.
        if (class_exists('\\MajorDoMo\\Core\\DatabaseGuard')) {
            \MajorDoMo\Core\DatabaseGuard::assertAllowed($sql);
        }

        $imported = @$this->db->multi_query($sql);
        if ($imported) {
            do {
                $res = @$this->db->store_result();
                if ($res instanceof \mysqli_result) {
                    $res->free();
                }
            } while (@$this->db->more_results() && @$this->db->next_result());
        }

        $error = (string)$this->db->error;
        if (!$imported || $error !== '') {
            $result['error'] = 'Не удалось создать таблицы: ' . ($error !== '' ? $error : 'неизвестная ошибка MySQL');
            return $result;
        }

        $result['ok'] = true;
        $result['statements'] = substr_count($sql, ';');
        return $result;
    }

    /**
     * Insert or update settings rows. No unique index exists on NAME, so the
     * wizard performs an explicit SELECT + UPDATE/INSERT.
     *
     * @param array<string,string> $values
     * @param array<string,array{title?:string,type?:string,default?:string,notes?:string,priority?:int}> $meta
     * @return array{ok:bool,error:string,written:int}
     */
    public function upsertSettings(array $values, array $meta = array()): array
    {
        $result = array('ok' => false, 'error' => '', 'written' => 0);
        if (!$this->db instanceof \mysqli) {
            $result['error'] = 'Нет соединения с базой данных.';
            return $result;
        }
        if (!$this->tableExists('settings')) {
            $result['error'] = 'В базе нет таблицы settings. Похоже, схема MajorDoMo не импортирована.';
            return $result;
        }

        foreach ($values as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', (string)$name)) {
                continue;
            }
            $name = (string)$name;
            $value = (string)$value;
            $info = $meta[$name] ?? array();
            $title = isset($info['title']) ? (string)$info['title'] : $name;
            $type = isset($info['type']) ? (string)$info['type'] : 'text';
            $default = isset($info['default']) ? (string)$info['default'] : '';
            $notes = isset($info['notes']) ? (string)$info['notes'] : '';
            $priority = isset($info['priority']) ? (int)$info['priority'] : 0;

            $id = $this->settingId($name);
            if ($id > 0) {
                $statement = @$this->db->prepare('UPDATE settings SET `VALUE`=? WHERE ID=?');
                if (!$statement) {
                    $result['error'] = 'Не удалось подготовить обновление настройки ' . $name . '.';
                    return $result;
                }
                $statement->bind_param('si', $value, $id);
                $ok = $statement->execute();
                $error = $statement->error;
                $statement->close();
            } else {
                $statement = @$this->db->prepare(
                    "INSERT INTO settings (`NAME`, `VALUE`, `TITLE`, `TYPE`, `DEFAULTVALUE`, `NOTES`, `DATA`, `PRIORITY`) VALUES (?,?,?,?,?,?,'',?)"
                );
                if (!$statement) {
                    $result['error'] = 'Не удалось подготовить добавление настройки ' . $name . '.';
                    return $result;
                }
                $statement->bind_param('ssssssi', $name, $value, $title, $type, $default, $notes, $priority);
                $ok = $statement->execute();
                $error = $statement->error;
                $statement->close();
            }
            if (!$ok) {
                $result['error'] = 'Не удалось сохранить настройку ' . $name . ': ' . ($error !== '' ? $error : $this->lastError());
                return $result;
            }
            $result['written']++;
        }

        $result['ok'] = true;
        return $result;
    }

    public function adminExists(): bool
    {
        if (!$this->db instanceof \mysqli) {
            return false;
        }
        if ($this->tableExists('users')) {
            $row = $this->one('SELECT COUNT(*) AS C FROM users WHERE IS_ADMIN=1');
            if ($row !== null && (int)$row > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create the site/panel user the future authentication layer will use.
     *
     * @return array{ok:bool,error:string,user_id:int}
     */
    public function createAdmin(string $login, string $password, string $name): array
    {
        $result = array('ok' => false, 'error' => '', 'user_id' => 0);
        if (!$this->db instanceof \mysqli) {
            $result['error'] = 'Нет соединения с базой данных.';
            return $result;
        }
        if (!$this->tableExists('users')) {
            $result['error'] = 'В базе нет таблицы users. Импортируйте схему MajorDoMo.';
            return $result;
        }

        $hash = \MajorDoMo\Core\Users\Passwords::hash($password);
        $statement = @$this->db->prepare(
            "INSERT INTO users (USERNAME, NAME, PASSWORD, IS_ADMIN, IS_DEFAULT, LINKED_OBJECT, HOST, COLOR) VALUES (?,?,?,1,1,'','','')"
        );
        if (!$statement) {
            $result['error'] = 'Не удалось подготовить создание пользователя.';
            return $result;
        }
        $statement->bind_param('sss', $login, $name, $hash);
        if (!$statement->execute()) {
            $result['error'] = 'Не удалось создать пользователя: ' . $this->lastError();
            $statement->close();
            return $result;
        }
        $result['user_id'] = (int)$this->db->insert_id;
        $statement->close();

        $result['ok'] = true;
        return $result;
    }

    /**
     * @return string
     */
    private function lastError(): string
    {
        return $this->db instanceof \mysqli ? (string)$this->db->error : '';
    }

    private function settingId(string $name): int
    {
        if (!$this->db instanceof \mysqli) {
            return 0;
        }
        $statement = @$this->db->prepare('SELECT ID FROM settings WHERE `NAME`=? LIMIT 1');
        if (!$statement) {
            return 0;
        }
        $statement->bind_param('s', $name);
        $statement->execute();
        $statement->bind_result($settingId);
        $id = 0;
        if ($statement->fetch()) {
            $id = (int)$settingId;
        }
        $statement->close();
        return $id;
    }

    /**
     * @return array<int,array<string,mixed>>|null
     */
    private function query(string $sql): ?array
    {
        if (!$this->db instanceof \mysqli) {
            return null;
        }
        $result = @$this->db->query($sql);
        if (!$result instanceof \mysqli_result) {
            return null;
        }
        $rows = array();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private function one(string $sql): ?int
    {
        if (!$this->db instanceof \mysqli) {
            return null;
        }
        $result = @$this->db->query($sql);
        if (!$result instanceof \mysqli_result) {
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();
        if (!is_array($row)) {
            return null;
        }
        $value = array_values($row);
        return isset($value[0]) ? (int)$value[0] : null;
    }

    private function friendlyError(int $errno, string $message): string
    {
        $hints = array(
            1045 => 'Неверный пользователь или пароль. Проверьте DB_USER и DB_PASSWORD.',
            1049 => 'База с таким именем не найдена. Создайте её в MySQL или исправьте DB_NAME.',
            2002 => 'Сервер MySQL недоступен. Проверьте DB_HOST, DB_PORT и запущен ли MySQL/MariaDB.',
            2003 => 'Не удалось подключиться к серверу MySQL. Проверьте адрес и порт.',
            2005 => 'Не удалось разрешить имя сервера MySQL. Проверьте DB_HOST.',
            2006 => 'Соединение с MySQL закрыто сервером.',
        );
        $hint = $hints[$errno] ?? '';
        $text = $message !== '' ? $message : 'код ' . $errno;
        return $hint !== '' ? $hint . ' (' . $text . ')' : $text;
    }
}
