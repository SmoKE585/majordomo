<?php

/** 2026-11-11 — расписание и реальные службы обслуживания на временных файлах и SQLite, без рабочих сервисов. */
namespace MajorDoMo\Core {
    final class Core {
        public static array $pruned = array();
        public static function audit() {
            return new class {
                public function prune($days, $limit = 5000, $seconds = 30, $maxRows = 0) {
                    Core::$pruned[] = array($days, $seconds, $maxRows);
                    return 3;
                }
            };
        }
    }
}
namespace {
    putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
    putenv('DB_HOST='); putenv('DB_NAME=fixture'); putenv('USE_REDIS=false'); putenv('LOG_DIRECTORY=');
    require dirname(__DIR__) . '/lib/core.class.php';
    require dirname(__DIR__) . '/lib/core/Helpers/Files.php';
    use MajorDoMo\Core\{Config, Core, Database};
    use MajorDoMo\Core\Maintenance\{AutomaticMaintenance, RoutineMaintenance};
    use MajorDoMo\Core\Cycles\CycleReadiness;
    use MajorDoMo\Core\Console\{Application, Commands\MaintenanceRunCommand};

    error_reporting(E_ALL);
    set_error_handler(static function ($severity, $message, $file, $line) { throw new \ErrorException($message, 0, $severity, $file, $line); });
    $root = sys_get_temp_dir() . '/mjd-auto-maintenance-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $root = realpath($root);
    if ($root === false || dirname($root) !== realpath(sys_get_temp_dir())) throw new RuntimeException('Isolation failed');
    Config::boot($root);
    date_default_timezone_set('UTC');
    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Isolation failed');
    $checks = 0;
    function check($condition, $message) { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
    function failure(callable $call, $fragment) {
        try { $call(); } catch (RuntimeException $error) { check(str_contains($error->getMessage(), $fragment), $error->getMessage()); return; }
        throw new RuntimeException('Ожидался отказ: ' . $fragment);
    }
    $remove = static function ($path) use (&$remove): void {
        if (is_link($path) || !is_dir($path)) { unlink($path); return; }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) $remove($item->getPathname());
        rmdir($path);
    };
    try {
        $calls = array();
        $execute = static function ($task) use (&$calls) {
            $calls[] = $task;
            return array('code' => 0, 'stdout' => json_encode(array('ok' => true, 'data' => array('task' => $task))), 'stderr' => '');
        };
        $automatic = new AutomaticMaintenance($root, $execute);
        $three = strtotime('2026-11-11 03:00:00 UTC');
        check(array_keys($automatic->runDue(now: $three)['tasks']) === array('cleanup'), 'До 04:00 выполняется только лёгкая очистка');
        $four = $three + 3600;
        check(array_keys($automatic->runDue(now: $four)['tasks']) === AutomaticMaintenance::TASKS, 'После 04:00 выполняются все этапы');
        check($automatic->runDue(now: $four + 1)['tasks'] === array(), 'Перезапуск в тот же момент не повторяет завершённые этапы');
        check(array_keys($automatic->runDue(now: $four + 901)['tasks']) === array('cleanup'), 'Лёгкая очистка повторяется через 15 минут');
        $lock = AutomaticMaintenance::lock($automatic->directory(), 'schedule.lock');
        check($automatic->runDue(force: true)['busy'], 'Второй планировщик не запускает параллельную копию');
        flock($lock, LOCK_UN); fclose($lock);

        $failed = new AutomaticMaintenance($root . '/retry', static function ($task) use ($execute) {
            if ($task === 'tables') throw new RuntimeException('fixture table failure');
            return $execute($task);
        });
        check($failed->runDue(now: $four)['tasks']['tables']['attempts'] === 1, 'Ошибка сохраняет первую попытку');
        check($failed->runDue(now: $four + 10)['tasks'] === array(), 'Повтор не запускается раньше паузы');
        check($failed->runDue(now: $four + 300)['tasks']['tables']['attempts'] === 2, 'После паузы выполняется вторая попытка');
        check($failed->runDue(now: $four + 900)['tasks']['tables']['attempts'] === 3, 'Число повторов ограничено');
        check(!isset($failed->runDue(now: $four + 1800)['tasks']['tables']), 'Четвёртая попытка не зацикливает обслуживание');
        check($failed->runDue(now: $four + 86400)['tasks']['tables']['attempts'] === 1, 'На следующий день этап снова получает попытку');

        $ready = new CycleReadiness($root);
        check(!$ready->ready(getmypid()), 'Без маркера зависимые циклы ожидают');
        $ready->markReady();
        check($ready->ready(getmypid()) && !$ready->ready(getmypid() + 1), 'Готовность относится только к конкретному PID main');
        $ready->reset();
        check(!$ready->ready(getmypid()), 'Перед новым запуском готовность сбрасывается');

        $adapter = new class($pdo) {
            public array $queries = array();
            public bool $damaged = false;
            public function __construct(public PDO $pdo) {}
            public function DbSafe1($value) { return str_replace("'", "''", (string)$value); }
            public function Select($sql) {
                $this->queries[] = $sql;
                if (str_contains($sql, 'information_schema.tables')) {
                    if (!str_contains($sql, "AND ENGINE='MyISAM'")) throw new RuntimeException('Полная проверка таблиц запрещена');
                    return array(array('TABLE_NAME' => 'legacy', 'ENGINE' => 'MyISAM'));
                }
                if (str_starts_with($sql, 'CHECK TABLE')) return array(array('Msg_type' => $this->damaged ? 'error' : 'status', 'Msg_text' => $this->damaged ? 'corrupt' : 'OK'));
                if (str_starts_with($sql, 'REPAIR TABLE')) { $this->damaged = false; return array(array('Msg_type' => 'status', 'Msg_text' => 'OK')); }
                return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            }
            public function SelectOne($sql) {
                if (str_contains($sql, 'information_schema.TABLES')) {
                    preg_match("/TABLE_NAME='([^']+)'/", $sql, $match);
                    $row = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $match[1] . "'")->fetch(PDO::FETCH_ASSOC);
                    return $row ?: array();
                }
                return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: array();
            }
            public function Exec($sql, $ignore = false) {
                $this->queries[] = $sql;
                if (str_starts_with($sql, 'SET SESSION')) return true;
                $this->affected = $this->pdo->exec($sql);
                return true;
            }
            public int $affected = 0;
            public function AffectedRows() { return $this->affected; }
        };
        $db = new Database($adapter);
        $service = new RoutineMaintenance($db, $root, static fn($file) => file_put_contents($file, 'isolated database dump'));
        $operation = AutomaticMaintenance::lock($automatic->directory(), 'operation.lock');
        failure(fn() => $service->run('files'), 'уже выполняется');
        flock($operation, LOCK_UN); fclose($operation);
        $pdo->exec('CREATE TABLE system_events (ID INTEGER PRIMARY KEY, CREATED TEXT)');
        for ($id = 1; $id <= 1005; $id++) $pdo->exec("INSERT INTO system_events VALUES ($id, '2000-01-01 00:00:00')");
        $cleaned = $service->run('cleanup');
        check($cleaned['audit_rows'] === 3 && Core::$pruned[0][1] === 4, 'Аудит чистится с ограничением времени');
        check((int)$pdo->query('SELECT COUNT(*) FROM system_events')->fetchColumn() === 1000, 'Очистка событий сохраняет не менее тысячи последних записей');
        $pdo->exec("INSERT INTO system_events VALUES (1000000, '2000-01-01 00:00:00')");
        $service->run('cleanup');
        check((int)$pdo->query('SELECT COUNT(*) FROM system_events')->fetchColumn() === 1000, 'Пропуски в ID не уменьшают сохраняемый минимум');

        mkdir($root . '/cms/debmes', 0700, true);
        file_put_contents($root . '/cms/debmes/old.log', 'old'); touch($root . '/cms/debmes/old.log', time() - 100 * 86400);
        file_put_contents($root . '/cms/debmes/current.log', 'active');
        mkdir($root . '/cms/images/1', 0700, true);
        file_put_contents($root . '/cms/images/1/current.jpg', 'current image without history');
        $service->run('files');
        check(!file_exists($root . '/cms/debmes/old.log') && file_exists($root . '/cms/debmes/current.log'), 'Удаляются старые журналы, текущие сохраняются');
        check(is_dir($root . '/cms/debmes'), 'Корневой каталог журналов сохраняется');
        check(file_exists($root . '/cms/images/1/current.jpg'), 'Картинка без истории не удаляется');

        $adapter->damaged = true;
        $unprotected = new RoutineMaintenance($db, $root . '/no-backup');
        failure(fn() => $unprotected->run('tables'), 'нет завершённой копии');
        check(!array_filter($adapter->queries, static fn($sql) => str_starts_with($sql, 'REPAIR TABLE')), 'Без копии автоматический ремонт не выполняется');
        $backup = $service->run('backup');
        check(is_file($backup['directory'] . '/complete.json'), 'Завершённая копия отмечается только после дампа и файлов');
        check(file_get_contents($backup['directory'] . '/cms/images/1/current.jpg') === 'current image without history', 'В копию входят пользовательские файлы CMS');
        check(!is_dir($backup['directory'] . '/cms/debmes') && !is_dir($backup['directory'] . '/cms/cycles'), 'Журналы и состояние процессов не копируются');
        check($service->run('backup')['already_complete'], 'Готовая дневная копия не перезаписывается');
        $expired = $root . '/backup/automatic/20000101';
        mkdir($expired, 0700);
        file_put_contents($expired . '/database.sql.part', 'interrupted old copy');
        mkdir($root . '/backup/user-copy', 0700);
        file_put_contents($root . '/backup/user-copy/important', 'user backup');
        $service->run('files');
        check(!is_dir($expired), 'Просроченная неполная копия не накапливается бесконечно');
        check(is_file($root . '/backup/user-copy/important'), 'Старые пользовательские копии вне automatic сохраняются');
        $tableResult = $service->run('tables');
        check($tableResult['repaired'] === 1 && !$tableResult['innodb_full_check'], 'MyISAM восстанавливается после копирования без CHECK InnoDB');
        check(!array_filter($adapter->queries, static fn($sql) => str_contains($sql, 'USE_FRM') || str_contains($sql, 'EXTENDED')), 'Агрессивный ремонт не применяется автоматически');

        $partial = new RoutineMaintenance($db, $root . '/partial', static function () { throw new RuntimeException('dump interrupted'); });
        failure(fn() => $partial->run('backup'), 'dump interrupted');
        check(!is_file($root . '/partial/backup/automatic/' . date('Ymd') . '/complete.json'), 'Сбой не помечает неполную копию готовой');

        $application = new Application($root);
        $out = fopen('php://temp', 'w+'); $err = fopen('php://temp', 'w+');
        check($application->run(array('maintenance:run', '--status', '--json'), $out, $err) === 0, 'CLI читает отчёт без запуска обслуживания');
        rewind($out); $report = json_decode(stream_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
        check(isset($report['data']['tasks']['backup']), 'JSON содержит состояние этапов');
        fclose($out); fclose($err);
        foreach (array(array('--task=unknown'), array('--status', '--task=files'), array('--task=files', '--force')) as $options) {
            $out = fopen('php://temp', 'w+'); $err = fopen('php://temp', 'w+');
            check($application->run(array_merge(array('maintenance:run', '--json'), $options), $out, $err) === 2, 'CLI отклоняет неизвестный этап и противоречивые опции');
            fclose($out); fclose($err);
        }
        check(!str_contains(file_get_contents(dirname(__DIR__) . '/scripts/cycle_main.php'), 'startup_maintenance.php'), 'Main не вызывает тяжёлое обслуживание');
        check(!str_contains(file_get_contents(dirname(__DIR__) . '/scripts/startup_maintenance.php'), 'DELETE FROM'), 'Старый вход не удаляет историю и данные по состоянию часов');
        echo 'OK: ' . $checks . " automatic maintenance checks; SQLite and temporary files\n";
    } finally { $remove($root); }
}
