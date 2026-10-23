<?php

// 2026-10-23 — очистка X-Ray через DELETE: SQLite в памяти и временные файлы, без загрузки приложения.
namespace MajorDoMo\Core {
    final class Core
    {
        public static object $database;
        public static function database(): object { return self::$database; }
    }
}

namespace {
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
    putenv('USE_REDIS=false');
    error_reporting(E_ALL);
    set_error_handler(static function ($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        throw new RuntimeException('Не подтверждена изоляция теста.');
    }
    $pdo->exec('CREATE TABLE cached_cycle_logs (ID INTEGER PRIMARY KEY, CYCLE TEXT, ADDED INTEGER, MESSAGE TEXT)');
    \MajorDoMo\Core\Core::$database = new class($pdo) {
        public function __construct(private PDO $pdo) {}
        public function escape($value): string { return substr($this->pdo->quote($value), 1, -1); }
        public function requireExec($sql, $deferErrors = false) { return $this->pdo->exec($sql); }
    };
    function SQLSelect($sql) { return $GLOBALS['pdo']->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    function DBSafe($value) { return \MajorDoMo\Core\Core::database()->escape($value); }
    class module { public $view_mode = ''; public $mode = ''; }
    require dirname(__DIR__) . '/lib/core/Helpers/Request.php';
    require dirname(__DIR__) . '/modules/xray/xray.class.php';
    final class XrayLogFixture extends xray
    {
        public function __construct(private string $logDirectory) {}
        public function ensureCycleRuntimeTables() {}
        public function getCycleLogPath() { return $this->logDirectory; }
    }
    $checks = 0;
    function check($condition, $message): void {
        if (!$condition) throw new RuntimeException($message);
        $GLOBALS['checks']++;
    }
    $root = sys_get_temp_dir() . '/mjd-xray-log-' . bin2hex(random_bytes(6));
    mkdir($root);
    $saved = array($_GET, $_POST, $_REQUEST, $_SERVER);
    $writer = null;
    try {
        foreach (array('log_2026-10-22-cycle_mqtt.php.txt', 'log_2026-10-23-cycle_mqtt.php.txt',
            'log_2026-10-23-cycle_mqtt_runtime.php.txt', 'log_2026-10-23-cycle_main.php.txt',
            'log_other-cycle_mqtt.php.txt') as $file) {
            file_put_contents($root . '/' . $file, 'fixture output');
        }
        $pdo->exec("INSERT INTO cached_cycle_logs VALUES (1,'cycle_mqtt',1000,'mqtt'), (2,'cycle_main',1000,'main')");
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['REQUEST_URI'] = '/api/v1/panel/xray/services/cycle_mqtt/log';
        $_GET = array('original' => 'get');
        $_POST = array('original' => 'post');
        $_REQUEST = array('original' => 'request');
        $request = array($_GET, $_POST, $_REQUEST, $_SERVER);
        $xray = new XrayLogFixture($root);
        $writer = fopen($root . '/log_2026-10-23-cycle_mqtt.php.txt', 'ab');
        $response = $xray->panelClearCycleLog('mqtt');
        check($response === array('STATUS' => 'OK', 'CYCLE' => 'cycle_mqtt', 'CLEARED' => 1), 'DELETE должен вернуть результат очистки');
        check(array($_GET, $_POST, $_REQUEST, $_SERVER) === $request, 'Исходный запрос должен восстановиться');
        check($pdo->query('SELECT CYCLE FROM cached_cycle_logs')->fetchAll(PDO::FETCH_COLUMN) === array('cycle_main'), 'Удаляются только записи выбранного цикла');
        foreach (array('2026-10-22', '2026-10-23') as $day) {
            check(file_get_contents($root . '/log_' . $day . '-cycle_mqtt.php.txt') === '', 'Файлы цикла обнуляются за все даты');
        }
        foreach (array('log_2026-10-23-cycle_mqtt_runtime.php.txt', 'log_2026-10-23-cycle_main.php.txt', 'log_other-cycle_mqtt.php.txt') as $file) {
            check(file_get_contents($root . '/' . $file) === 'fixture output', 'Посторонние файлы остаются целыми');
        }
        check($xray->panelCycleLog('cycle_mqtt')['LINES'] === array(), 'После очистки журнал пуст');
        fwrite($writer, 'new output');
        fflush($writer);
        fclose($writer);
        $writer = null;
        check(file_get_contents($root . '/log_2026-10-23-cycle_mqtt.php.txt') === 'new output', 'Открытый процессом файл принимает новые записи после очистки');
        check($xray->panelClearCycleLog('cycle_mqtt')['STATUS'] === 'OK', 'Повторная очистка допустима');
        check((new XrayLogFixture($root . '/absent'))->panelClearCycleLog('cycle_mqtt')['STATUS'] === 'OK', 'Отсутствие файлового каталога не мешает очистке БД');

        $pdo->exec('DROP TABLE cached_cycle_logs');
        try {
            $xray->panelClearCycleLog('cycle_mqtt');
            throw new RuntimeException('Ошибка БД не должна выдавать успешный результат');
        } catch (PDOException $expected) {
            check(array($_GET, $_POST, $_REQUEST, $_SERVER) === $request, 'Запрос восстанавливается и при ошибке БД');
        }
        echo 'OK: ' . $checks . ' X-Ray cycle log checks' . PHP_EOL;
    } finally {
        if (is_resource($writer)) fclose($writer);
        [$_GET, $_POST, $_REQUEST, $_SERVER] = $saved;
        foreach (new DirectoryIterator($root) as $file) {
            if (!$file->isDot()) unlink($file->getPathname());
        }
        rmdir($root);
    }
}
