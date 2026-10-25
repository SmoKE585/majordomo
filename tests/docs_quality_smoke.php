<?php

/**
 * Контракт документации.
 * 2026-10-25 — разрешена категория TODO для заметок будущих задач.
 *
 * Docs — одновременно справочник для агентов и «База знаний» в админке,
 * поэтому каждый материал должен быть навигабельным: front matter с известной
 * категорией, рабочие относительные ссылки и русский текст.
 */

$root = dirname(__DIR__);
$docsRoot = str_replace('\\', '/', $root . '/docs');

$allowedCategories = array(
    'general', 'core', 'data', 'automation', 'ui', 'api',
    'integration', 'migration', 'user', 'deploy', 'dev', 'archive', 'agents', 'todo',
);

$checks = 0;
function check($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

function docsFiles($directory)
{
    $result = array();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
            $result[] = str_replace('\\', '/', $file->getPathname());
        }
    }
    sort($result);
    return $result;
}

function frontMatter($content)
{
    if (!preg_match('/^---\r?\n(.*?)\r?\n---\r?\n/s', $content, $matches)) {
        return null;
    }
    $meta = array();
    foreach (preg_split('/\r?\n/', $matches[1]) as $line) {
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$/', trim($line), $item)) {
            continue;
        }
        $meta[$item[1]] = trim($item[2], " \t\"'");
    }
    return $meta;
}

$categories = array();
$files = docsFiles($docsRoot);
check(count($files) >= 30, 'Docs scan must find the documentation tree');

foreach ($files as $file) {
    $relative = ltrim(substr($file, strlen($docsRoot)), '/');
    if (str_starts_with($relative, 'archive/')) {
        continue;
    }
    $content = file_get_contents($file);
    check(mb_check_encoding($content, 'UTF-8'), $relative . ': file must be valid UTF-8');
    check(!str_contains($content, "\xEF\xBF\xBD"), $relative . ': replacement character U+FFFD found');

    $meta = frontMatter($content);
    check($meta !== null, $relative . ': front matter is required');
    foreach (array('title', 'audience', 'category', 'description') as $key) {
        check(isset($meta[$key]) && $meta[$key] !== '', $relative . ': front matter key "' . $key . '" is required');
    }
    check(in_array($meta['category'], $allowedCategories, true), $relative . ': unknown category "' . $meta['category'] . '"');
    foreach (array_map('trim', explode(',', $meta['audience'])) as $audience) {
        check(in_array($audience, array('user', 'agent'), true), $relative . ': audience must be user and/or agent');
    }
    $categories[$meta['category']] = ($categories[$meta['category']] ?? 0) + 1;

    // Относительные Markdown-ссылки должны вести на существующий файл.
    if (preg_match_all('/\]\(([^)\s]+)/', $content, $matches)) {
        foreach ($matches[1] as $target) {
            if (preg_match('~^(https?://|mailto:|#|/)~i', $target) || !str_ends_with(strtok($target, '#'), '.md')) {
                continue;
            }
            $path = strtok($target, '#');
            $resolved = str_replace('\\', '/', realpath(dirname($file) . '/' . $path));
            check($resolved !== false && str_starts_with($resolved, $docsRoot), $relative . ': broken link ' . $target);
        }
    }

    // Документация намеренно на русском; исключение — имена кода и файлов.
    $prose = preg_replace('/```.*?```/s', '', $content);
    $cyrillic = preg_match_all('/[А-Яа-яЁё]/u', $prose);
    $latin = preg_match_all('/[A-Za-z]/', $prose);
    check($cyrillic >= 50, $relative . ': documentation must be written in Russian');
    check($cyrillic >= $latin * 0.5, $relative . ': Russian prose must dominate over English');
}

foreach (array('general', 'core', 'data', 'automation', 'ui', 'api', 'integration', 'migration', 'user') as $category) {
    check(isset($categories[$category]), 'Category "' . $category . '" must contain at least one document');
}

echo 'Docs quality smoke tests passed: ' . $checks . ' checks; '
    . count($files) . ' files; categories: ' . json_encode($categories, JSON_UNESCAPED_UNICODE) . PHP_EOL;
