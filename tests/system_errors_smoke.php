<?php
/** 2026-10-25 — реальная запись ошибок, транзакции, события и совместимость истории на SQLite. */
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('USE_REDIS=false');
require dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\{Config, Core, Database, ErrorDetails};
use MajorDoMo\Core\Cycles\{CycleStateStore, CycleSupervisor, CycleWatchdog};
use MajorDoMo\Core\Events\{EventBus, SystemEvents};

Config::boot(sys_get_temp_dir() . '/mjd-errors-config-' . bin2hex(random_bytes(6)));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Окружение не изолировано');
$pdo->exec('CREATE TABLE system_errors (ID INTEGER PRIMARY KEY, CODE TEXT, TITLE TEXT DEFAULT "", DETAILS TEXT DEFAULT "", ACTIVE INTEGER DEFAULT 0, LATEST_UPDATE TEXT, KEEP_HISTORY INTEGER DEFAULT 1)');
$pdo->exec('CREATE TABLE system_errors_data (ID INTEGER PRIMARY KEY, ERROR_ID INTEGER, COMMENTS TEXT, ADDED TEXT)');
$pdo->exec('CREATE TABLE system_events (ID INTEGER PRIMARY KEY, NAME TEXT, PAYLOAD TEXT, SOURCE TEXT, CREATED TEXT, PROCESSED INTEGER)');
$db = new class($pdo) {
    public function __construct(private PDO $pdo) {}
    public function Select($sql) { return $this->pdo->query(str_replace(' FOR UPDATE', '', $sql))->fetchAll(PDO::FETCH_ASSOC); }
    public function SelectOne($sql) { return $this->Select($sql)[0] ?? null; }
    public function Exec($sql, $ignored = false) { $this->pdo->exec($sql === 'START TRANSACTION' ? 'BEGIN' : $sql); return true; }
    public function DbSafe1($value) { return substr($this->pdo->quote((string)$value), 1, -1); }
    public function Insert($table, &$record) {
        $keys = array_keys($record);
        $statement = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', $keys) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')');
        $statement->execute(array_values($record));
        return (int)$this->pdo->lastInsertId();
    }
    public function Update($table, $record, $key) {
        $id = $record[$key]; unset($record[$key]);
        $statement = $this->pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(static fn($name) => $name . '=?', array_keys($record))) . ' WHERE ' . $key . '=?');
        return $statement->execute(array_merge(array_values($record), array($id)));
    }
};
$GLOBALS['db'] = $db;
function DebMes($message, $source = '', $severity = '') { $GLOBALS['logs'][] = $message; }
function registerEvent($name, $payload) { $GLOBALS['mirrored'][] = array($name, $payload); }
require dirname(__DIR__) . '/lib/core/Helpers/ActionLog.php';
require dirname(__DIR__) . '/modules/system_errors/system_errors_helpers.inc.php';
EventBus::useDatabase(new Database($db));
$events = array();
EventBus::on(SystemEvents::SYSTEM_ERROR, static function ($name, $payload) use (&$events): void { $events[] = $payload; });
$checks = 0;
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
$_SERVER['REQUEST_URI'] = '/panel/test?token=private-token&password=private-password';
registerError('custom', 'Отказ устройства token=private-token');
$record = $db->SelectOne('SELECT * FROM system_errors');
$latest = $db->SelectOne('SELECT * FROM system_errors_data ORDER BY ID DESC LIMIT 1');
$data = ErrorDetails::decode($latest['COMMENTS']);
check($record['ACTIVE'] === 1 && $events[0]['status'] === 'new', 'Первый сбой создаёт новую ошибку и системное событие');
check($events[0]['error_id'] === $record['ID'] && $events[0]['event_id'] === $latest['ID'], 'Событие связано с записью и историей');
check(str_contains($data['file'], 'system_errors_smoke.php'), 'Источник — вызывающий файл, а не регистратор');
check(!str_contains($latest['COMMENTS'], 'private-token') && !str_contains($latest['COMMENTS'], 'private-password'), 'Секреты сообщения и URL скрыты');
check(count($GLOBALS['mirrored']) === 1 && $GLOBALS['mirrored'][0][0] === 'SYSTEM_ERROR', 'Событие доступно старым подписчикам');
registerError('custom', 'Повторный отказ');
check($events[1]['status'] === 'repeated', 'Следующий сбой считается повторным');
$pdo->exec('UPDATE system_errors SET ACTIVE=0,KEEP_HISTORY=0');
registerError('custom', 'Сбой после закрытия');
$data = ErrorDetails::decode($db->SelectOne('SELECT COMMENTS FROM system_errors_data ORDER BY ID DESC LIMIT 1')['COMMENTS']);
check($events[2]['status'] === 'repeated' && $data['occurrences'] === 3, 'После закрытия ошибка открывается повторно');
check(count($db->Select('SELECT * FROM system_errors_data')) === 1, 'Счётчик повторов не зависит от хранения истории');
$before = count($events);
$unit = Core::database()->beginUnit();
registerError('rollback', 'Ошибка в откатываемой операции');
check(count($events) === $before, 'Событие ждёт завершения внешней транзакции');
Core::database()->rollbackUnit($unit);
check(!$db->SelectOne("SELECT * FROM system_errors WHERE CODE='rollback'") && count($events) === $before, 'Откат не оставляет ложное событие');
registerError('sql', 'Недоступна БД');
registerError('property_chain', 'Значение сохранено, сработала защита');
check(count($events) === $before, 'Диагностика и защитное предупреждение не создают критичных ошибок');
$originalScript = $argv[0]; $argv[0] = 'scripts/core_worker.php';
registerError('method', 'Исполнитель ещё может повторить задание');
$argv[0] = $originalScript;
check(count($events) === $before, 'Промежуточный отказ задания не тревожит администратора');
$details = ErrorDetails::encode('Отказ <script>alert(1)</script>', array('source' => 'tuya', 'file' => 'modules/tuya/tuya.class.php', 'line' => 42, 'entity' => 'Lamp.on'));
$frames = md_system_errors_frames($details);
check(md_system_errors_origin($frames)['FILE'] === 'modules/tuya/tuya.class.php' && $frames[0]['LINE'] === 42, 'Интерфейс показывает сохранённое место сбоя');
check(md_system_errors_context($details)['URL'] !== '' && md_system_errors_diagnostic($details)['SOURCE'] === 'tuya', 'Контекст и сводка доступны без разбора стека');
$legacy = "Старое сообщение\nBacktrace:\n#0 lib/core/ErrorReporter.php(45): registerError()\n#1 modules/tuya/tuya.class.php(12): report()\nContext:\nURL: /old\nMethod: POST";
check(md_system_errors_message($legacy) === 'Старое сообщение' && md_system_errors_origin(md_system_errors_frames($legacy))['LINE'] === 12, 'Старая история читается и пропускает кадр регистратора');
check(SystemEvents::severity('SYSTEM_ERROR') === 'error' && in_array('SYSTEM_ERROR', SystemEvents::names(), true), 'Системное событие зарегистрировано как ошибка');
// Ошибка синхронного слушателя не должна породить бесконечную цепочку событий.
EventBus::on(SystemEvents::SYSTEM_ERROR, static function (): void { registerError('recursive', 'Ошибка в подписчике'); });
registerError('listener', 'Проверка защиты события');
check(count($events) === $before + 1 && !$db->SelectOne("SELECT * FROM system_errors WHERE CODE='recursive'"), 'Повторный вход из слушателя остаётся в логе');
echo 'System errors smoke: ' . $checks . ' passed; SQLite :memory:, без внешних сервисов.' . PHP_EOL;
