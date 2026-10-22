<?php

/**
 * Изолированные проверки файловых миграций.
 *
 * Рабочая БД не нужна: Database оборачивает минимальный fake-connection,
 * который отвечает только на SQL репозитория миграций.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

require dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Database;
use MajorDoMo\Core\Migrations;

final class MigrationFakeConnection
{
    public array $rows = array();
    public bool $table = false;
    public array $sql = array();

    public function Select(string $sql): array
    {
        $this->sql[] = $sql;
        if (preg_match('/SHOW COLUMNS FROM core_migrations/i', $sql)) {
            return $this->table
                ? array(array('Field' => 'VERSION'), array('Field' => 'APPLIED'), array('Field' => 'BATCH'), array('Field' => 'DURATION_MS'))
                : array();
        }
        if (preg_match('/SELECT VERSION,BATCH,APPLIED,DURATION_MS FROM core_migrations/i', $sql)) {
            return array_values($this->rows);
        }
        return array();
    }

    public function SelectOne(string $sql): ?array
    {
        $this->sql[] = $sql;
        if (preg_match('/GET_LOCK/i', $sql)) return array('OK' => 1);
        if (preg_match('/RELEASE_LOCK/i', $sql)) return array('OK' => 1);
        if (preg_match('/COALESCE\(MAX\(BATCH\),0\)\+1/i', $sql)) {
            $batch = 0;
            foreach ($this->rows as $row) $batch = max($batch, (int)$row['BATCH']);
            return array('NEXT_BATCH' => $batch + 1);
        }
        return null;
    }

    public function Exec(string $sql, bool $ignoreErrors = false): bool
    {
        $this->sql[] = $sql;
        if (preg_match('/CREATE TABLE IF NOT EXISTS core_migrations/i', $sql)) {
            $this->table = true;
            return true;
        }
        if (preg_match('/ALTER TABLE core_migrations/i', $sql)) return true;
        if (preg_match("/INSERT INTO core_migrations \(VERSION,APPLIED,BATCH,DURATION_MS\) VALUES \('([^']+)',NOW\(\),(\d+),(\d+)\)/i", $sql, $matches)) {
            $this->rows[$matches[1]] = array(
                'VERSION' => $matches[1],
                'BATCH' => (int)$matches[2],
                'APPLIED' => date('Y-m-d H:i:s'),
                'DURATION_MS' => (int)$matches[3],
            );
            return true;
        }
        return true;
    }

    public function DbSafe1(mixed $value): string { return str_replace("'", "''", (string)$value); }
    public function Insert(string $table, array &$record): int { return 0; }
    public function Update(string $table, array $record, string $key = 'ID'): bool { return true; }
    public function AffectedRows(): int { return 0; }
    public function getLastError(): array { return array('code' => 0, 'message' => ''); }
}

function migrationStub(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

function migrationFile(string $directory, string $file, string $version, string $body = 'return true;'): void
{
    $content = "<?php\n\nuse MajorDoMo\\Core\\Migrations;\nuse MajorDoMo\\Core\\Migrations\\Migration;\n\n"
        . "return new class extends Migration {\n"
        . "    public function version(): string { return " . var_export($version, true) . "; }\n"
        . "    public function description(): string { return " . var_export('test ' . $version, true) . "; }\n"
        . "    public function up(Migrations \$migrations): void { " . $body . " }\n"
        . "};\n";
    file_put_contents($directory . '/' . $file, $content);
}

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    $checks++;
    echo 'PASS ' . $message . PHP_EOL;
}

$directory = sys_get_temp_dir() . '/mjd-migrations-' . bin2hex(random_bytes(6));
mkdir($directory, 0777, true);
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: array() as $item) {
            if ($item === '.' || $item === '..') continue;
            $remove($path . '/' . $item);
        }
        rmdir($path);
    } elseif (is_file($path) || is_link($path)) {
        unlink($path);
    }
};

try {
    $connection = new MigrationFakeConnection();
    $migrations = new Migrations(
        new Database($connection),
        migrationStub(\MajorDoMo\Core\Cache::class),
        migrationStub(\MajorDoMo\Core\OperationsQueue::class),
        migrationStub(\MajorDoMo\Core\HistoryStorage::class),
        migrationStub(\MajorDoMo\Core\WebSocketQueue::class),
        $directory
    );

    $GLOBALS['migration_order'] = array();
    migrationFile($directory, '2026_10_01_000001_first.php', '20261001_first', '$GLOBALS["migration_order"][] = "first";');
    migrationFile($directory, '2026_10_03_000001_third.php', '20261003_third', '$GLOBALS["migration_order"][] = "third";');
    $result = $migrations->run();
    check($result['applied'] === array('20261001_first', '20261003_third'), 'обнаруженные миграции применяются по версии');
    check($GLOBALS['migration_order'] === array('first', 'third'), 'порядок выполнения совпадает с версиями');
    check((int)$connection->rows['20261001_first']['BATCH'] === 1, 'первый запуск получает батч 1');

    // Пропущенная версия: более новая уже применена, старая добавляется позже.
    migrationFile($directory, '2026_10_02_000001_second.php', '20261002_second', '$GLOBALS["migration_order"][] = "second";');
    $result = $migrations->run();
    check($result['applied'] === array('20261002_second'), 'пропущенная версия применяется отдельно');
    check(isset($connection->rows['20261002_second']), 'пропущенная версия записана в репозиторий');

    $before = count($connection->rows);
    $result = $migrations->run();
    check($result['applied'] === array() && count($connection->rows) === $before, 'повторный запуск ничего не выполняет');

    // Ошибка не должна оставлять запись, а повторный запуск должен её повторить.
    migrationFile($directory, '2026_10_04_000001_fail.php', '20261004_fail', 'throw new RuntimeException("boom");');
    $failed = false;
    try {
        $migrations->run();
    } catch (RuntimeException $error) {
        $failed = str_contains($error->getMessage(), '20261004_fail');
    }
    check($failed, 'ошибка миграции содержит её версию');
    check(!isset($connection->rows['20261004_fail']), 'неуспешная миграция не записывается как применённая');
    unlink($directory . '/2026_10_04_000001_fail.php');
    migrationFile($directory, '2026_10_04_000001_fail_fixed.php', '20261004_fail', '$GLOBALS["migration_order"][] = "fixed";');
    $result = $migrations->run();
    check($result['applied'] === array('20261004_fail'), 'миграция повторяется после исправления');
    check($GLOBALS['migration_order'] === array('first', 'third', 'second', 'fixed'), 'после ошибки порядок остаётся предсказуемым');

    $status = $migrations->status();
    check(count($status) === 4, 'status показывает все файлы');
    check(array_reduce($status, static fn(bool $ok, array $row) => $ok && $row['applied'], true), 'status отмечает применённые версии');
    check($status[0]['batch'] === 1 && $status[0]['duration_ms'] >= 0, 'status отдаёт батч и длительность');

    // Запись без файла видна как missing_file, а не исчезает из отчёта.
    $connection->rows['20261005_orphan'] = array('VERSION' => '20261005_orphan', 'BATCH' => 1, 'APPLIED' => date('Y-m-d H:i:s'), 'DURATION_MS' => 1);
    $status = $migrations->status();
    $orphan = array_values(array_filter($status, static fn(array $row) => $row['version'] === '20261005_orphan'));
    check(count($orphan) === 1 && $orphan[0]['missing_file'] === true, 'запись без файла помечается missing_file');

    echo 'Migration smoke tests passed: ' . $checks . ' checks.' . PHP_EOL;
} finally {
    $remove($directory);
}
