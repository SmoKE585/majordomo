<?php
/**
 * 2026-10-25 — уведомления проверяются в SQLite :memory:, без loader и .env.
 * MySQL advisory lock заменён функциями SQLite; рабочие сервисы недоступны.
 */
if (PHP_SAPI !== 'cli') exit(2);
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
require dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\{Core, Database, Migrations, ModuleNotifications};
use MajorDoMo\Core\Api\{ApiException, Dispatcher, Panel, Request};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Tests must be isolated.');
$pdo->exec("CREATE TABLE module_notifications (ID INTEGER PRIMARY KEY AUTOINCREMENT, MODULE_NAME TEXT, MESSAGE TEXT, TYPE TEXT, IS_READ INTEGER DEFAULT 0, ADDED TEXT, NOTIFICATION_KEY TEXT DEFAULT '', PAYLOAD TEXT)");
$pdo->exec('CREATE TABLE project_modules (NAME TEXT, TITLE TEXT)');
$pdo->exec("INSERT INTO project_modules VALUES ('tuya', 'Устройства Tuya')");

final class NotificationTestConnection
{
    public int $writes = 0;
    public int $locked = 0;
    public bool $allowLock = true;
    private int $affected = 0;
    public function __construct(public PDO $pdo)
    {
        $pdo->sqliteCreateFunction('DATABASE', static fn() => 'isolated');
        $pdo->sqliteCreateFunction('SHA1', static fn($text) => sha1($text));
        $pdo->sqliteCreateFunction('CONCAT', static fn(...$parts) => implode('', $parts));
        $pdo->sqliteCreateFunction('GET_LOCK', function () { if (!$this->allowLock) return 0; $this->locked++; return 1; });
        $pdo->sqliteCreateFunction('RELEASE_LOCK', function () { $this->locked--; return 1; });
    }
    public function Select(string $sql): array
    {
        if ($sql === 'SHOW COLUMNS FROM module_notifications') {
            return array_map(static fn($row) => array('Field' => $row['name']), $this->pdo->query('PRAGMA table_info(module_notifications)')->fetchAll(PDO::FETCH_ASSOC));
        }
        if ($sql === 'SHOW INDEX FROM `module_notifications`') {
            $indexes = array();
            foreach ($this->pdo->query('PRAGMA index_list(module_notifications)')->fetchAll(PDO::FETCH_ASSOC) as $index) {
                foreach ($this->pdo->query('PRAGMA index_info(' . $index['name'] . ')')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    $indexes[] = array('Key_name' => $index['name'], 'Seq_in_index' => $column['seqno'] + 1, 'Column_name' => $column['name'], 'Non_unique' => !$index['unique']);
                }
            }
            return $indexes;
        }
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    public function SelectOne(string $sql): ?array
    {
        if (str_contains($sql, 'information_schema.TABLES')) {
            return $this->pdo->query("SELECT name AS TABLE_NAME FROM sqlite_master WHERE type='table' AND name='module_notifications'")->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return $this->Select($sql)[0] ?? null;
    }
    public function Exec(string $sql, bool $ignoreErrors = false): bool
    {
        $this->writes++;
        if (str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS module_notifications')) return true;
        $sql = str_replace('ALTER TABLE `module_notifications` ADD INDEX `idx_notifications_unread` (IS_READ,MODULE_NAME)', 'CREATE INDEX idx_notifications_unread ON module_notifications (IS_READ,MODULE_NAME)', $sql);
        $this->affected = $this->pdo->exec($sql);
        return true;
    }
    public function Insert(string $table, array &$record): int
    {
        if ($this->locked !== 1) throw new RuntimeException('Insert must be protected by the module lock.');
        $this->writes++;
        $statement = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($record)) . ') VALUES (' . implode(',', array_fill(0, count($record), '?')) . ')');
        $statement->execute(array_values($record));
        return (int)$this->pdo->lastInsertId();
    }
    public function DbSafe1(mixed $value): string { return str_replace("'", "''", (string)$value); }
    public function AffectedRows(): int { return $this->affected; }
}
$logs = array();
function DebMes($message, $file = '', $type = '') { $GLOBALS['logs'][] = array($message, $file, $type); }
$checks = 0;
function check($condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
    echo 'ok: ' . $message . PHP_EOL;
}

$connection = new NotificationTestConnection($pdo);
$db = new Database($connection);
$service = new ModuleNotifications($db);
$first = $service->send('tuya', 'Нет связи с "лампой" & шлюзом', 'warning');
check($first['status'] === true && $first['id'] > 0, 'Первое уведомление сохраняется');
$before = $pdo->query('SELECT * FROM module_notifications')->fetchAll(PDO::FETCH_ASSOC);
$writes = $connection->writes;
$logCount = count($logs);
for ($i = 0; $i < 1000; $i++) $duplicate = $service->send('tuya', 'Нет связи с "лампой" & шлюзом', 'warning');
check(!$duplicate['status'] && $duplicate['id'] === $first['id'], 'Повтор сохраняет старый JSON-контракт и ID');
check($connection->writes === $writes && count($logs) === $logCount && $pdo->query('SELECT * FROM module_notifications')->fetchAll(PDO::FETCH_ASSOC) === $before, '1000 повторов не меняют БД, дату, данные или лог');
check($connection->locked === 0, 'Замок освобождён после повторов');
$readSnapshot = $pdo->query('SELECT * FROM module_notifications')->fetchAll(PDO::FETCH_ASSOC);
$list = $service->listing();
check($list['total'] === 1 && $list['items'][0]['message'] === 'Нет связи с "лампой" & шлюзом' && $list['items'][0]['module_title'] === 'Устройства Tuya', 'Список возвращает исходный текст и название модуля');
check($pdo->query('SELECT * FROM module_notifications')->fetchAll(PDO::FETCH_ASSOC) === $readSnapshot && count($logs) === $logCount, 'Просмотр списка ничего не отмечает прочитанным');
check(!$service->readLegacy('another', $first['id'])['status'], 'Модуль не может прочитать чужое уведомление по ID');
check($service->readLegacy('tuya', $first['id'])['status'], 'Явное прочтение уведомления');
$again = $service->send('tuya', 'Нет связи с "лампой" & шлюзом', 'warning');
check($again['status'] && $again['id'] !== $first['id'], 'После прочтения то же уведомление можно отправить снова');

$rich = $service->send('tuya', 'Сбой авторизации', 'danger', array('key' => 'auth', 'title' => 'Нужен вход', 'details' => '<script>alert(1)</script>\nОткройте настройки.', 'url' => '/panel/tuya.html', 'label' => 'Настроить'));
$writes = $connection->writes;
$logCount = count($logs);
$repeatRich = $service->send('tuya', 'Другой текст и время 12:34', 'warning', array('key' => 'auth', 'title' => 'Другой заголовок'));
check(!$repeatRich['status'] && $repeatRich['id'] === $rich['id'] && $connection->writes === $writes && count($logs) === $logCount, 'Ключ отбрасывает повтор даже при другом тексте и уровне');
$list = $service->listing();
check($list['items'][0]['id'] === $rich['id'] && $list['severity'] === 'danger' && $list['levels']['tuya'] === 'danger', 'Ошибки идут первыми и определяют важность счётчика');
check($list['items'][0]['url'] === '/panel/tuya.html' && $list['items'][0]['title'] === 'Нужен вход' && str_starts_with($list['items'][0]['details'], '<script>'), 'Подробности возвращаются текстом для безопасного DOM-рендера');
foreach (array('https://evil.test', '//evil.test', '/%2fevil.test', '/\\evil.test', '/%0aevil', 'javascript:alert(1)') as $url) {
    check(!$service->send('tuya', 'Опасная ссылка', 'info', array('url' => $url))['status'], 'Внешняя или опасная ссылка отклонена: ' . $url);
}
check($service->send("another'", 'Другое событие', 'info')['status'], 'Имя модуля безопасно экранируется');
$connection->allowLock = false;
$writes = $connection->writes;
try { $service->send('tuya', 'Гонка'); check(false, 'Заблокированная отправка должна завершиться ошибкой'); }
catch (RuntimeException $error) { check($connection->writes === $writes, 'Отказ блокировки не приводит к INSERT без защиты'); }
$connection->allowLock = true;

$panel = new Panel($db);
$dispatcher = new Dispatcher($db);
foreach (array('local', 'token', 'user_token') as $actor) {
    try { $dispatcher->dispatch(new Request('GET', '/api/v1/panel/notifications', actor: $actor)); check(false, 'Требуется сеанс администратора'); }
    catch (ApiException $error) { check($error->status === 401, 'Список закрыт для actor=' . $actor); }
}
$api = $dispatcher->dispatch(new Request('GET', '/api/v1/panel/notifications', actor: 'session'));
check($api['total'] === 3, 'Список доступен в сеансе администратора');
foreach (array(array('offset' => -1), array('offset' => array()), array('module' => array())) as $params) {
    try { $panel->notifications($params); check(false, 'Неверные параметры отклоняются'); }
    catch (ApiException $error) { check($error->status === 422, 'Валидация параметров списка'); }
}
check($panel->dismiss(array('id' => $rich['id']))['dismissed'] === 1, 'API явно отмечает одно уведомление');
check($panel->dismiss(array('module' => 'tuya'))['dismissed'] === 1, 'Старый API прочтения модуля сохранён');

// Сигнатуры module не расширяются: сторонние классы могут переопределять их.
require dirname(__DIR__) . '/lib/module.class.php';
$GLOBALS['db'] = $connection;
$legacy = new module();
$legacy->name = 'legacy';
check((new ReflectionMethod(module::class, 'sendNotification'))->getNumberOfParameters() === 2, 'Сигнатура sendNotification сохранена');
$legacySend = json_decode($legacy->sendNotification('Legacy', 'info'), true);
check($legacySend['status'] && json_decode($legacy->readNotification((int)$legacySend['id']), true)['status'], 'Старые методы возвращают JSON и используют общий сервис');

// База до миграции должна обслуживать старые модули, не теряя новые options.
$pdo->exec('ALTER TABLE module_notifications DROP COLUMN PAYLOAD');
$pdo->exec('ALTER TABLE module_notifications DROP COLUMN NOTIFICATION_KEY');
$old = new ModuleNotifications(new Database($connection));
check($old->send('old_schema', 'Старая схема')['status'] && $old->listing('old_schema')['total'] === 1, 'Старая схема продолжает принимать и показывать уведомления');
check(!$old->send('old_schema', 'Подробности', options: array('title' => 'Заголовок'))['status'], 'Расширенные options требуют миграции и не теряются молча');
check(!$old->send('old_schema', 'Нулевой ключ', options: array('key' => '0'))['status'], 'Ключ "0" также требует расширенной схемы');
$migrations = (new ReflectionClass(Migrations::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Migrations::class, 'db'))->setValue($migrations, new Database($connection));
$migration = require dirname(__DIR__) . '/lib/core/Migrations/2026_10_25_000002_module_notifications.php';
$migration->up($migrations);
$schema = $pdo->query("SELECT sql FROM sqlite_master WHERE name IN ('module_notifications', 'idx_notifications_unread') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$migration->up($migrations);
check($schema === $pdo->query("SELECT sql FROM sqlite_master WHERE name IN ('module_notifications', 'idx_notifications_unread') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN), 'Повторная миграция не меняет уже обновлённую схему');
$upgraded = new ModuleNotifications(new Database($connection));
check($upgraded->send('old_schema', 'Схема обновлена', options: array('title' => 'Заголовок'))['status'], 'Расширенные сообщения работают после миграции старой схемы');
$zero = $upgraded->send('old_schema', 'Нулевой ключ', options: array('key' => '0', 'title' => '0'));
$zeroDuplicate = $upgraded->send('old_schema', 'Другой текст нулевого ключа', options: array('key' => '0'));
check($zero['status'] && !$zeroDuplicate['status'] && $zeroDuplicate['id'] === $zero['id'], 'Ключ "0" сохраняется и отбрасывает повторы');
check($connection->locked === 0, 'Все advisory locks освобождены');
echo 'Module notifications: ' . $checks . ' checks passed.' . PHP_EOL;
