<?php
/**
 * Folder picker boundary smoke test (no database required).
 *
 * Run: php tests/folder_picker_smoke.php
 */

require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Filesystem\FolderBrowser;

$failures = 0;
function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo 'FAIL: ' . $message . PHP_EOL;
        return;
    }
    echo 'ok: ' . $message . PHP_EOL;
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mjd-folder-picker-' . bin2hex(random_bytes(6));
$root = $base . DIRECTORY_SEPARATOR . 'project';
$outside = $base . DIRECTORY_SEPARATOR . 'outside';
foreach (array($root, $root . '/backup', $root . '/cms/saverestore', $root . '/.hidden', $root . '/node_modules/pkg', $root . '/vendor/lib', $outside) as $dir) {
    mkdir($dir, 0777, true);
}
file_put_contents($root . '/README.md', 'not a folder');
file_put_contents($outside . '/secret.txt', 'outside');

try {
    $browser = new FolderBrowser($root);
    check($browser->root() === realpath($root), 'root is resolved to a real path');

    $listing = $browser->listing('');
    $names = array_column($listing['dirs'], 'name');
    check($listing['ok'] === true, 'root directory is listed');
    check($listing['path'] === '', 'root path is empty');
    check($listing['parent'] === null, 'root has no parent inside the project');
    check(in_array('backup', $names, true) && in_array('cms', $names, true), 'project folders are visible');
    check(!in_array('.hidden', $names, true), 'hidden folders are not exposed');
    check(!in_array('node_modules', $names, true) && !in_array('vendor', $names, true), 'dependency folders are skipped');
    check(!in_array('README.md', $names, true), 'files are not exposed');

    $cms = $browser->listing('cms');
    check($cms['path'] === 'cms', 'relative path is listed');
    check($cms['parent'] === '', 'nested folder has a project-relative parent');
    check(array_column($cms['dirs'], 'name') === array('saverestore'), 'nested folder lists its child directory');

    $backup = $browser->listing('cms/../backup');
    check($backup['path'] === 'backup', 'normalised relative path stays inside the project');
    check($browser->resolve('backup') === realpath($root . '/backup'), 'valid directory resolves');

    check($browser->resolve('../outside') === null, 'relative traversal outside the project is rejected');
    check($browser->resolve($outside) === null, 'absolute path outside the project is rejected');
    check($browser->resolve($root . '/.hidden') === null, 'direct access to a hidden folder is rejected');
    check($browser->resolve($root . '/node_modules') === null, 'direct access to a dependency folder is rejected');
    check($browser->resolve($root . '/README.md') === null, 'files are rejected');
    check($browser->resolve('missing') === null, 'missing directory is rejected');

    $fallback = $browser->listing($outside);
    check($fallback['ok'] === true && $fallback['path'] === '' && $fallback['fallback'] === true, 'outside path falls back to the project root');

    $absolute = $browser->listing(realpath($root . '/cms'));
    check($absolute['path'] === 'cms', 'absolute path inside the project is accepted');
} finally {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    @rmdir($base);
}

if ($failures > 0) {
    echo 'RESULT ' . $failures . ' failure(s)' . PHP_EOL;
    exit(1);
}
echo 'RESULT folder picker smoke tests passed' . PHP_EOL;
