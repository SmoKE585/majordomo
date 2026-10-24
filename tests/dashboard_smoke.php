<?php
// 2026-10-24 — события за сутки на SQLite и диагностика Redis на подменном клиенте.
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('USE_REDIS=false');
require_once dirname(__DIR__) . '/lib/core.class.php';
define('ROOT', dirname(__DIR__) . '/');

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Core;
use MajorDoMo\Core\RedisConnection;

$fixture = sys_get_temp_dir() . '/mjd-dashboard-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700, true);
Config::boot($fixture);
register_shutdown_function(static function () use ($fixture): void {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path)) {
            foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
            rmdir($path);
        } else unlink($path);
    };
    $remove($fixture);
});
date_default_timezone_set('Europe/Moscow');
require_once dirname(__DIR__) . '/lib/dashboard_data.php';
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
check(getenv('APP_ENV') === 'testing' && getenv('DB_CONNECTION') === 'sqlite', 'Только тестовое окружение');
$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec('CREATE TABLE property_audit_events (ID INTEGER PRIMARY KEY, ADDED TEXT, TYPE TEXT, STATUS TEXT)');
$pdo->exec('CREATE INDEX idx_type_added ON property_audit_events (TYPE, ADDED)');
$GLOBALS['db'] = new class($pdo) {
    public function __construct(private PDO $pdo) {}
    public function DbSafe1($value) { return str_replace("'", "''", (string)$value); }
    public function Select($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function SelectOne($sql) {
        if (str_contains($sql, 'information_schema.TABLES')) {
            return $this->pdo->query("SELECT name AS TABLE_NAME FROM sqlite_master WHERE name='property_audit_events'")->fetch(PDO::FETCH_ASSOC) ?: array();
        }
        throw new RuntimeException('Неожиданный запрос');
    }
};
$now = strtotime('2026-10-24 00:05:30');
$from = $now - 86400;
$insert = $pdo->prepare('INSERT INTO property_audit_events (ADDED, TYPE, STATUS) VALUES (?, ?, ?)');
foreach (array(
    array($from - 1, 'property', 'done'),
    array($from, 'property', 'done'), array($from + 1, 'property', 'done'),
    array($now - 331, 'method', 'done'), array($now - 330, 'method', 'error'),
    array($now, 'method', 'running'), array($now, 'property', 'done'),
    array($now, 'property', 'done'), array($now, 'script', 'done'),
    array($now, 'trigger', 'done'), array($now + 1, 'script', 'done'),
) as [$at, $type, $status]) $insert->execute(array(date('Y-m-d H:i:s', $at), $type, $status));
$activity = mdj_dashboard_activity($now);
check($activity['available'] === true && count($activity['labels']) === 145, 'Полные сутки с двумя крайними частичными интервалами');
check($activity['properties_total'] === 4, 'Повторные изменения считаются, события до суток исключены');
check($activity['methods_total'] === 3 && $activity['scripts_total'] === 1, 'Считаются фактические вызовы, включая ошибки; trigger и будущее исключены');
check($activity['methods'][143] === 1 && $activity['methods'][144] === 2, 'Граница 23:59:59 / 00:00:00 разделена правильно');
check($activity['properties'][0] === 2 && $activity['properties'][144] === 2, 'Первая и текущая корзины сохраняют точные события');
check(str_starts_with($activity['ranges'][0], '23.10 00:05:30') && str_ends_with($activity['ranges'][144], '00:05:30'), 'Подсказки содержат дату и границы частичных интервалов');
$pdo->exec('DELETE FROM property_audit_events');
$empty = mdj_dashboard_activity($now);
check($empty['available'] && array_sum($empty['properties']) === 0, 'Пустой журнал отличается от недоступного');
$pdo->exec('DROP TABLE property_audit_events');
check(mdj_dashboard_activity($now)['available'] === false, 'Отсутствующая схема не выдаётся за отсутствие активности');

$disabled = (new RedisConnection())->diagnostics();
check(!$disabled['enabled'] && !$disabled['available'] && $disabled['ops'] === null, 'Отключённый Redis не опрашивается');
// Конфигурация и клиент подменяются в памяти: никаких соединений с реальным Redis.
$valuesProperty = new ReflectionProperty(Config::class, 'values');
$values = $valuesProperty->getValue();
$values['USE_REDIS'] = 'fixture.invalid';
$valuesProperty->setValue(null, $values);
$fake = new class {
    public mixed $pong = '+PONG';
    public bool $failPing = false;
    public bool $failInfo = false;
    public array $stats = array('redis_version' => '7.0', 'used_memory' => 900, 'maxmemory' => 1000,
        'connected_clients' => 3, 'instantaneous_ops_per_sec' => 12, 'uptime_in_seconds' => 100,
        'evicted_keys' => 7, 'db0' => 'keys=42,expires=0,avg_ttl=0');
    public function ping() { if ($this->failPing) throw new RuntimeException('fixture'); return $this->pong; }
    public function info() { if ($this->failInfo) throw new RuntimeException('NOPERM'); return $this->stats; }
};
$connection = new RedisConnection();
$clientProperty = new ReflectionProperty(RedisConnection::class, 'connection');
$clientProperty->setValue($connection, $fake);
$redis = $connection->diagnostics();
check($redis['available'] && $redis['latency_ms'] !== null && $redis['keys'] === 42 && $redis['ops'] === 12, 'PING и INFO дают показатели Redis');
check($redis['memory_percent'] === 90.0 && $redis['level'] === 'danger', 'Заполнение памяти 90% — опасное состояние');
$fake->stats['used_memory'] = 850;
check($connection->diagnostics()['level'] === 'warning', 'Заполнение памяти 85% — предупреждение');
$fake->stats['maxmemory'] = 0;
unset($fake->stats['db0']);
$redis = $connection->diagnostics();
check($redis['memory_percent'] === null && $redis['level'] === 'ok' && $redis['keys'] === 0, 'Безлимитная память и пустая выбранная БД');
$fake->failInfo = true;
$redis = $connection->diagnostics();
check($redis['available'] && $redis['level'] === 'warning' && $redis['ops'] === null, 'Отказ INFO сохраняет доступность PING и очищает показатели');
$fake->failPing = true;
$redis = $connection->diagnostics();
check(!$redis['available'] && $redis['level'] === 'danger' && $clientProperty->getValue($connection) === null, 'Обрыв PING сбрасывает сокет и показывает недоступность');
check(!$connection->diagnostics()['available'], 'Повтор во время отсрочки подключения не обращается к сети');
echo "Dashboard: $checks checks passed (SQLite :memory:, fake Redis).\n";
if (in_array('--export-ui', $argv, true)) {
    ob_start();
    require ROOT . 'templates/panel_redis.php';
    $html = str_replace(array('[#inc panel_redis.php#]', '<#ROOTHTML#>', '<#LANG_HOME#>'),
        array(ob_get_clean(), '/', 'Главная'), file_get_contents(ROOT . 'templates/panel_default.html'));
    $path = tempnam(sys_get_temp_dir(), 'mjd-dashboard-ui-');
    file_put_contents($path, json_encode(array('html' => $html, 'activity' => $activity), JSON_UNESCAPED_UNICODE));
    echo 'UI_FIXTURE=' . $path . PHP_EOL;
}
