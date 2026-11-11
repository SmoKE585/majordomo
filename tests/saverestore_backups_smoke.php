<?php

declare(strict_types=1);

// 2026-11-11 — каталог и скачивание копий на временных файлах, без БД и внешних сервисов.
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('USE_REDIS=false');

$project = dirname(__DIR__);
define('ROOT', str_replace('\\', '/', $project) . '/');
require_once $project . '/modules/saverestore/BackupCatalog.php';

#[AllowDynamicProperties]
class module
{
    public function checkInstalled(): void {}
}
require_once $project . '/modules/saverestore/saverestore.class.php';

/** Изолирует файловые операции модуля от настроек и рабочего каталога. */
final class BackupFixtureModule extends saverestore
{
    public function __construct(private string $fixture) {}
    public function getSaveRestoreDirectory() { return $this->fixture . '/local'; }
    public function getBackupStorageDirectory() { return $this->fixture . '/storage'; }
}
function gr($key) { return $_REQUEST[$key] ?? ''; }

if (($argv[1] ?? '') === '--download-fixture') {
    $fixturePath = realpath($argv[2] ?? '');
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($fixturePath === false || !str_starts_with($fixturePath, $temporaryRoot . DIRECTORY_SEPARATOR . 'mjd-backups-')) throw new RuntimeException('Недопустимая фикстура.');
    $_REQUEST = array('source' => $argv[3], 'file' => $argv[4]);
    (new BackupFixtureModule($fixturePath))->downloadBackup();
}

$checks = 0;
function check(bool $value, string $message): void
{
    $GLOBALS['checks']++;
    if (!$value) throw new RuntimeException($message);
}
$fixture = sys_get_temp_dir() . '/mjd-backups-' . bin2hex(random_bytes(6));
mkdir($fixture . '/local', 0700, true);
mkdir($fixture . '/storage', 0700, true);
try {
    $body = "SQL fixture\n\0\x01\xff";
    file_put_contents($fixture . '/local/db_before_update_2026-11-10__12-30-45.sql', $body);
    touch($fixture . '/local/db_before_update_2026-11-10__12-30-45.sql', 1);
    file_put_contents($fixture . '/local/backup_design_code_data_files_2026-11-09__10-00-00.tgz', 'archive');
    file_put_contents($fixture . '/local/master.tgz', 'distribution');
    file_put_contents($fixture . '/local/update.pending.json', '{}');
    file_put_contents($fixture . '/local/system_update_manifest.json', '{}');
    file_put_contents($fixture . '/local/.hidden.sql', 'hidden');
    mkdir($fixture . '/local/temp');
    file_put_contents($fixture . '/storage/custom.tar.gz', $body);
    file_put_contents($fixture . '/storage/database.sql.gz', 'gzip');
    file_put_contents($fixture . '/storage/db_before_update_2026-99-99__25-99-99.sql', 'invalid date');
    mkdir($fixture . '/storage/old_copy');
    for ($i = 1; $i <= 25; $i++) file_put_contents($fixture . '/storage/manual_' . $i . '.sql', 'sql');
    $catalog = new MajorDoMo\Modules\SaveRestore\BackupCatalog($fixture . '/local', $fixture . '/storage');
    $rows = $catalog->all();
    check(count($rows) === 31, 'В каталоге только копии, без дистрибутива, JSON и временной папки.');
    $byName = array_column($rows, null, 'NAME');
    $database = $byName['db_before_update_2026-11-10__12-30-45.sql'];
    check($database['KIND'] === 'before_update' && $database['TITLE'] === 'База данных перед обновлением', 'Назначение автоматического дампа понятно.');
    check($database['DATE'] === '10.11.2026 12:30:45' && $database['DATE_LABEL'] === 'Дата из имени', 'Копирование файла не меняет показанную дату самой копии.');
    check($byName['db_before_update_2026-99-99__25-99-99.sql']['DATE_LABEL'] === 'Изменён файл', 'Некорректная дата имени не нормализуется молча.');
    check(str_contains($byName['backup_design_code_data_files_2026-11-09__10-00-00.tgz']['CONTENTS'], 'база данных, код и модули, оформление, файлы данных'), 'Состав штатного архива расшифрован по имени.');
    check($byName['custom.tar.gz']['CONTENTS'] === 'Состав не указан в имени', 'Состав произвольного архива не выдумывается.');
    check(!$byName['database.sql.gz']['CAN_RESTORE'] && $byName['database.sql.gz']['CAN_DOWNLOAD'], 'Сжатый дамп можно скачать, для восстановления требуется распаковка.');
    check(!$byName['old_copy']['CAN_DOWNLOAD'] && $byName['old_copy']['CAN_RESTORE'] && $byName['old_copy']['NOTE'] !== '', 'Папки сохранены с понятным объяснением ограничения скачивания.');
    check($database['SOURCE_TITLE'] === 'Каталог обновления' && $byName['custom.tar.gz']['SOURCE_TITLE'] === 'Каталог резервных копий', 'Обычный каталог не назван внешним хранилищем.');
    $times = array_column($rows, 'TIMESTAMP'); $sorted = $times; rsort($sorted);
    check($times === $sorted, 'Новые копии расположены первыми.');
    check(count((new MajorDoMo\Modules\SaveRestore\BackupCatalog($fixture . '/local', $fixture . '/local'))->all()) === 2, 'Один физический каталог не показывается дважды.');
    check($catalog->download('storage', 'custom.tar.gz')['PATH'] === realpath($fixture . '/storage/custom.tar.gz'), 'Скачивание доступно из отдельного каталога.');
    foreach (array(array('local', '../storage/custom.tar.gz'), array('storage', '/etc/passwd'), array('local', 'master.tgz'),
        array('local', 'update.pending.json'), array('storage', 'old_copy'), array('unknown', 'custom.tar.gz'), array('local', "a\r\n.sql")) as [$source, $name]) {
        check($catalog->download($source, $name) === null, 'Скачивание отвергает служебные файлы, папки и недопустимые пути.');
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        file_put_contents($fixture . '/outside.sql', 'outside');
        symlink($fixture . '/outside.sql', $fixture . '/storage/escape.sql');
        check($catalog->download('storage', 'escape.sql') === null, 'Символическая ссылка за границы хранилища не скачивается.');
    }
    $data = array('FILES' => array(), 'CLEAR_FIRST' => 0);
    foreach ($rows as $index => $row) {
        $row['ID'] = $index;
        $row['FILENAME_URL'] = rawurlencode($row['PATH']);
        $row['DOWNLOAD_URL'] = '?mode=download_backup&source=' . $row['SOURCE'] . '&file=' . rawurlencode($row['NAME']);
        $data['FILES'][] = $row;
    }
    ob_start(); require $project . '/templates/saverestore/_backups.php'; $html = ob_get_clean();
    check(str_contains($html, 'data-md-table-page-size="20"') && str_contains($html, 'data-md-table-pagination'), 'Таблица использует штатную пагинацию по 20 записей.');
    check(str_contains($html, 'data-md-table-filter="source"') && str_contains($html, 'data-md-table-filter="kind"'), 'Доступны фильтры назначения и места хранения.');
    check(!str_contains($html, 'master.tgz') && !str_contains($html, 'cms/saverestore/custom'), 'Служебные архивы и прямые публичные ссылки отсутствуют.');
    $data['FILES'][0]['NAME'] = '"><script>alert(1)</script>';
    ob_start(); require $project . '/templates/saverestore/_backups.php'; $escaped = ob_get_clean();
    check(!str_contains($escaped, '<script>alert(1)</script>'), 'Имена файлов экранируются в предпросмотре и атрибутах.');
    foreach (array(array('local', 'db_before_update_2026-11-10__12-30-45.sql'), array('storage', 'custom.tar.gz')) as [$source, $name]) {
        $pipes = array();
        $process = proc_open(array(PHP_BINARY, __FILE__, '--download-fixture', $fixture, $source, $name), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Не удалось запустить проверку скачивания.');
        fclose($pipes[0]); $download = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        check(proc_close($process) === 0 && $errors === '' && $download === $body, 'Обработчик отдаёт точные байты копии без HTML из обоих каталогов.');
    }
    if (($argv[1] ?? '') === '--export-ui') {
        $export = sys_get_temp_dir() . '/mjd-backups-ui-' . bin2hex(random_bytes(6)) . '.html';
        file_put_contents($export, $html);
        echo 'UI_FIXTURE=' . $export . PHP_EOL;
    }
    echo 'Резервные копии: ' . $checks . ' проверок пройдено (временные файлы, без БД и сети).' . PHP_EOL;
} finally {
    $resolved = realpath($fixture); $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved === false || !str_starts_with($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'mjd-backups-')) throw new RuntimeException('Небезопасный путь очистки.');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($resolved);
}
