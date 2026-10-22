<?php

/** Изолированные проверки безопасного удаления файлов при деплое. */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

require dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Deploy\FileRemovals;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    $checks++;
    echo 'PASS ' . $message . PHP_EOL;
}

$root = sys_get_temp_dir() . '/mjd-removals-' . bin2hex(random_bytes(6));
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: array() as $item) {
        if ($item === '.' || $item === '..') continue;
        $remove($path . '/' . $item);
    }
    rmdir($path);
};
$write = static function (string $path, string $contents = 'x'): void {
    $directory = dirname($path);
    if (!is_dir($directory)) mkdir($directory, 0777, true);
    file_put_contents($path, $contents);
};

try {
    mkdir($root, 0777, true);
    mkdir($root . '/deploy', 0777, true);

    $write($root . '/old.php');
    $write($root . '/custom/keep.txt');
    $write($root . '/modules/old/old.php');
    $write($root . '/modules/old/nested/file.php');
    $write($root . '/modules/custom/keep.php');
    $write($root . '/templates/classes/views/S1.html');
    $write($root . '/templates/classes/views/S2.html');
    $write($root . '/templates/classes/views/keep.html');
    $write($root . '/cms/data/keep.txt');
    $write($root . '/old-empty/keep.txt');

    file_put_contents($root . '/deploy/removals.json', json_encode(array(
        'version' => 1,
        'files' => array('old.php', 'missing.php'),
        'directories' => array(
            array('path' => 'modules/old', 'recursive' => true),
            array('path' => 'old-empty', 'recursive' => false),
            array('path' => 'custom-missing', 'recursive' => true),
        ),
        'patterns' => array('templates/classes/views/S*.html'),
    ), JSON_PRETTY_PRINT));

    $removals = new FileRemovals($root);
    $result = $removals->apply();
    check(!is_file($root . '/old.php'), 'точный файл удалён');
    check(!is_dir($root . '/modules/old'), 'recursive-каталог удалён целиком');
    check(is_dir($root . '/modules/custom') && is_file($root . '/modules/custom/keep.php'), 'пользовательский модуль не тронут');
    check(is_file($root . '/custom/keep.txt'), 'пользовательский каталог не тронут');
    check(is_dir($root . '/cms/data') && is_file($root . '/cms/data/keep.txt'), 'cms защищён от удаления');
    check(!is_file($root . '/templates/classes/views/S1.html') && !is_file($root . '/templates/classes/views/S2.html'), 'pattern удаляет старые views');
    check(is_file($root . '/templates/classes/views/keep.html'), 'pattern не трогает другие views');
    check(is_dir($root . '/old-empty') && is_file($root . '/old-empty/keep.txt'), 'непустой не-recursive каталог остаётся');
    check($result['files'] === 1 && $result['patterns'] === 2 && $result['directories'] === 1, 'apply возвращает счётчики');
    check($removals->apply()['skipped'] >= 1, 'повторный запуск идемпотентен');

    $failed = false;
    try {
        $removals->removePath('cms/data/keep.txt');
    } catch (RuntimeException $error) {
        $failed = str_contains($error->getMessage(), 'защищённый');
    }
    check($failed, 'защищённый путь нельзя удалить напрямую');

    $failed = false;
    try {
        $removals->normalize('../outside');
    } catch (RuntimeException $error) {
        $failed = true;
    }
    check($failed, 'выход через .. отклоняется');

    $failed = false;
    try {
        $removals->normalize('/etc/passwd');
    } catch (RuntimeException $error) {
        $failed = true;
    }
    check($failed, 'абсолютный путь отклоняется');

    // Symlink удаляется как ссылка, цель за пределами проекта остаётся.
    $outside = sys_get_temp_dir() . '/mjd-removals-outside-' . bin2hex(random_bytes(6)) . '.txt';
    file_put_contents($outside, 'outside');
    $link = $root . '/link.txt';
    $symlinkCreated = @symlink($outside, $link);
    if ($symlinkCreated) {
        $removals->removePath('link.txt');
        check(!is_link($link) && is_file($outside), 'symlink удалён, цель не тронута');
        unlink($outside);
    } else {
        check(true, 'symlink-проверка пропущена: ОС не разрешила создать ссылку');
    }

    echo 'File removals smoke tests passed: ' . $checks . ' checks.' . PHP_EOL;
} finally {
    $remove($root);
}
