<?php

// 2026-11-11 — очистка пустых каталогов не удаляет журналы, скрытые файлы и цели ссылок.
// Только временные файлы: без загрузки приложения, конфигурации, БД и сети.

if (PHP_SAPI !== 'cli') exit(2);
putenv('APP_ENV=testing');
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require dirname(__DIR__) . '/lib/core/Helpers/Files.php';

$fixture = sys_get_temp_dir() . '/mjd-empty-folders-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
$fixture = realpath($fixture);
if ($fixture === false || dirname($fixture) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Не подтверждена изоляция временного каталога');
}
$remove = static function ($path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
    }
    rmdir($path);
};
$checks = 0;
function check($condition, $message) {
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    $GLOBALS['checks']++;
}

try {
    mkdir($fixture . '/debmes/empty/deep', 0700, true);
    file_put_contents($fixture . '/debmes/current.log', 'keep log');
    check(removeEmptySubFolders($fixture . '/debmes') === false, 'Непустой каталог не удаляется и не вызывает предупреждение');
    check(file_get_contents($fixture . '/debmes/current.log') === 'keep log', 'Активный журнал сохранён');
    check(!is_dir($fixture . '/debmes/empty'), 'Пустые вложенные каталоги удалены даже рядом с файлами');

    mkdir($fixture . '/hidden/.empty', 0700, true);
    file_put_contents($fixture . '/hidden/.keep', 'hidden');
    check(removeEmptySubFolders($fixture . '/hidden') === false, 'Скрытый файл делает каталог непустым');
    check(file_get_contents($fixture . '/hidden/.keep') === 'hidden', 'Скрытый файл сохранён');
    check(!is_dir($fixture . '/hidden/.empty'), 'Пустой скрытый подкаталог удалён');

    mkdir($fixture . '/tree/occupied', 0700, true);
    file_put_contents($fixture . '/tree/occupied/log', 'keep');
    mkdir($fixture . '/tree/z-empty', 0700);
    check(removeEmptySubFolders($fixture . '/tree') === false && !is_dir($fixture . '/tree/z-empty'),
        'Непустой первый подкаталог не отменяет очистку остальных');

    mkdir($fixture . '/all-empty/nested/deep', 0700, true);
    check(removeEmptySubFolders($fixture . '/all-empty') === true && !is_dir($fixture . '/all-empty'),
        'Полностью пустое дерево удаляется вместе с корнем');
    check(removeEmptySubFolders($fixture . '/missing') === true, 'Уже отсутствующий каталог считается очищенным');

    if (PHP_OS_FAMILY !== 'Windows') {
        mkdir($fixture . '/outside/empty', 0700, true);
        mkdir($fixture . '/links', 0700);
        symlink($fixture . '/outside', $fixture . '/links/directory');
        symlink($fixture . '/missing', $fixture . '/links/broken');
        check(removeEmptySubFolders($fixture . '/links') === false, 'Каталог со ссылками не считается пустым');
        check(is_dir($fixture . '/outside/empty'), 'Целевой каталог ссылки не обходится');
        check(is_link($fixture . '/links/directory') && is_link($fixture . '/links/broken'), 'Обычная и битая ссылки сохранены');
        check(removeEmptySubFolders($fixture . '/links/directory') === false, 'Корневая ссылка не обходится');
        check(removeEmptySubFolders($fixture . '/links/broken') === false, 'Битая корневая ссылка не считается отсутствующим каталогом');
    }
    echo 'OK: ' . $checks . ' empty folder checks (' . PHP_OS_FAMILY . ")\n";
} finally {
    $remove($fixture);
}
