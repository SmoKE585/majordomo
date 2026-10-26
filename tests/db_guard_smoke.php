<?php
/**
 * Проверка защиты базы от DROP DATABASE в отладочном окружении.
 *
 * Запуск: php tests/db_guard_smoke.php
 */

putenv('DB_PROTECT_DROP=1');
require_once dirname(__DIR__) . '/bootstrap.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\DatabaseGuard;

Config::boot(dirname(__DIR__));

$failures = array();
$check = static function (string $name, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
        echo 'FAIL: ' . $name . PHP_EOL;
    } else {
        echo 'ok: ' . $name . PHP_EOL;
    }
};

$destructive = array(
    'DROP DATABASE db',
    'drop database if exists `db`',
    'DROP SCHEMA db',
    "  \n\tDROP\nDATABASE db",
    '/* leading */ DROP DATABASE db',
    '-- comment' . "\n" . 'DROP DATABASE db',
    '/*!50000 DROP DATABASE db */',
    'DROP /* between */ DATABASE db',
    "SELECT 1;\nDROP DATABASE db;",
);

$safe = array(
    'DROP TABLE IF EXISTS cyclesRun',
    "SELECT 'DROP DATABASE db' AS sample",
    'INSERT INTO settings (NAME, VALUE) VALUES (\'x\', \'DROP DATABASE db\')',
    'UPDATE scripts SET CODE = "DROP SCHEMA db" WHERE ID = 1',
    'SELECT * FROM `DROP DATABASE`',
    'ALTER TABLE users ADD COLUMN X int',
    'CREATE TABLE `debug` (ID int)',
    'DELETE FROM mqtt WHERE PATH = \'#\'',
);

foreach ($destructive as $sql) {
    $check('destructive: ' . str_replace("\n", ' ', $sql), DatabaseGuard::isDestructive($sql));
}
foreach ($safe as $sql) {
    $check('safe: ' . str_replace("\n", ' ', $sql), !DatabaseGuard::isDestructive($sql));
}

$check('guard enabled from env', DatabaseGuard::enabled());
$thrown = false;
try {
    DatabaseGuard::assertAllowed('DROP DATABASE db');
} catch (RuntimeException $error) {
    $thrown = str_contains($error->getMessage(), 'DB_PROTECT_DROP');
}
$check('assertAllowed throws with clear message', $thrown);

$allowed = true;
try {
    DatabaseGuard::assertAllowed('DROP TABLE IF EXISTS cyclesRun');
} catch (RuntimeException $error) {
    $allowed = false;
}
$check('DROP TABLE is still allowed', $allowed);

if ($failures) {
    echo PHP_EOL . 'FAILED: ' . count($failures) . PHP_EOL;
    exit(1);
}
echo PHP_EOL . 'All database guard checks passed.' . PHP_EOL;
exit(0);
