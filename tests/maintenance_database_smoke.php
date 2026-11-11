<?php

/** 2026-11-11 — реальный дамп и восстановление только внутри изолированного контейнера MariaDB. */
if (PHP_SAPI !== 'cli' || getenv('MJD_MAINTENANCE_DATABASE_TEST') !== 'isolated-container') {
    fwrite(STDERR, "Запускайте только в отдельном контейнере без внешней сети и рабочих томов.\n");
    exit(2);
}
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=mysql'); putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=3306'); putenv('DB_NAME=mjd_maintenance_test'); putenv('DB_USER=root'); putenv('DB_PASSWORD='); putenv('USE_REDIS=false');
require dirname(__DIR__) . '/lib/core.class.php';
require dirname(__DIR__) . '/lib/core/Helpers/Database.php';
use MajorDoMo\Core\{Config, Database};
use MajorDoMo\Core\Maintenance\RoutineMaintenance;
function IsWindowsOS() { return false; }
$root = sys_get_temp_dir() . '/mjd-maintenance-db-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
if (dirname(realpath($root)) !== realpath(sys_get_temp_dir())) throw new RuntimeException('Isolation failed');
Config::boot($root);
$pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec('CREATE DATABASE mjd_maintenance_test');
$pdo->exec('CREATE DATABASE mjd_maintenance_restore');
$pdo->exec('USE mjd_maintenance_test');
$pdo->exec('CREATE TABLE property_audit_events (ID INT PRIMARY KEY, VALUE TEXT) ENGINE=InnoDB');
$pdo->exec("INSERT INTO property_audit_events VALUES (1,'snapshot value')");
$pdo->exec('CREATE TABLE legacy (ID INT PRIMARY KEY, VALUE TEXT) ENGINE=MyISAM');
$pdo->exec("INSERT INTO legacy VALUES (1,'legacy value')");
$adapter = new class($pdo) {
    public array $queries = array();
    public function __construct(public PDO $pdo) {}
    public function Select($sql) { $this->queries[] = $sql; return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function SelectOne($sql) { $this->queries[] = $sql; return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: array(); }
    public function Exec($sql, $ignore = false) { $this->queries[] = $sql; $this->pdo->exec($sql); return true; }
    public function DbSafe1($value) { return substr($this->pdo->quote((string)$value), 1, -1); }
};
$checks = 0;
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
$remove = static function ($path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) $remove($item->getPathname());
    rmdir($path);
};
try {
    mkdir($root . '/cms/images/1', 0700, true);
    file_put_contents($root . '/cms/images/1/current.jpg', 'snapshot image');
    $routine = new RoutineMaintenance(new Database($adapter), $root);
    $backup = $routine->run('backup');
    $dump = $backup['directory'] . '/database.sql';
    check(is_file($dump) && filesize($dump) > 0, 'mysqldump завершился и создал непустой SQL');
    check((fileperms($dump) & 0777) === 0600, 'Дамп доступен только владельцу');
    check(is_file($backup['directory'] . '/complete.json'), 'Копия помечена готовой');
    $pdo->exec('USE mjd_maintenance_restore');
    $pdo->exec(file_get_contents($dump));
    check($pdo->query('SELECT VALUE FROM property_audit_events WHERE ID=1')->fetchColumn() === 'snapshot value', 'InnoDB восстановлена из настоящего дампа');
    check($pdo->query('SELECT VALUE FROM legacy WHERE ID=1')->fetchColumn() === 'legacy value', 'MyISAM восстановлена из настоящего дампа');
    check(file_get_contents($backup['directory'] . '/cms/images/1/current.jpg') === 'snapshot image', 'Файлы CMS входят в копию');
    $pdo->exec('USE mjd_maintenance_test');
    $tables = $routine->run('tables');
    check($tables['checked_myisam'] === 1 && $tables['repaired'] === 0, 'QUICK проверяет только MyISAM');
    check(!array_filter($adapter->queries, static fn($sql) => str_starts_with($sql, 'CHECK TABLE') && str_contains($sql, 'property_audit_events')), 'Аудит InnoDB не сканируется через CHECK TABLE');
    echo 'OK: ' . $checks . " maintenance database checks (MariaDB, dump and restore)\n";
} finally {
    $pdo->exec('DROP DATABASE mjd_maintenance_restore');
    $pdo->exec('DROP DATABASE mjd_maintenance_test');
    $remove($root);
}
