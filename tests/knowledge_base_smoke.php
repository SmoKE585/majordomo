<?php

declare(strict_types=1);

/**
 * Knowledge base smoke test (no database, no web server).
 *
 * Covers the contract that is easy to break silently:
 *   - scanning docs/ and deriving ids;
 *   - front matter (audience/tags/category/title);
 *   - safe Markdown rendering (raw HTML is escaped);
 *   - table of contents and relative Markdown links;
 *   - path traversal is rejected.
 *
 * Usage:
 *   php tests/knowledge_base_smoke.php
 */

require_once dirname(__DIR__) . '/lib/knowledge_base.php';

$failures = 0;

function kbCheck(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo 'FAIL: ' . $message . PHP_EOL;
        return;
    }
    echo 'ok: ' . $message . PHP_EOL;
}

function kbRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($directory);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mjd-kb-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
mkdir($root . '/user', 0777, true);
mkdir($root . '/agents', 0777, true);

file_put_contents($root . '/README.md', "# Документация\n\nОбщий указатель по всем материалам.\n");

file_put_contents($root . '/user/commands.md', <<<'MD'
---
title: Команды
audience: user, agent
category: user
tags: cli, setup
description: Короткое описание для карточки.
---

# Команды

Короткое описание.

## Запуск

```bash
php tools/install.php reset
echo "<script>alert(1)</script>"
```

| Команда | Действие |
| --- | --- |
| `reset` | Сброс |
MD
);

file_put_contents($root . '/agents/AGENTS.md', "# Правила\n\nСм. [команды](../user/commands.md).\n");

try {
    $kb = new MDKnowledgeBase($root, '/');
    $documents = $kb->documents();
    kbCheck(count($documents) === 3, 'docs scan finds three Markdown files');

    $stats = $kb->stats();
    kbCheck($stats['total'] === 3, 'stats total is 3');
    kbCheck($stats['user'] === 2, 'root README and commands are visible to users');
    kbCheck($stats['agent'] === 3, 'all three documents are visible to agents');
    kbCheck($stats['categories'] === 3, 'three categories are detected');

    $commands = $kb->document('user/commands');
    kbCheck(is_array($commands), 'document() renders user/commands');
    if (is_array($commands)) {
        kbCheck($commands['title'] === 'Команды', 'front matter title wins over the heading');
        kbCheck($commands['description'] === 'Короткое описание для карточки.', 'front matter description is used');
        kbCheck($commands['category_key'] === 'user', 'front matter category is used');
        kbCheck($commands['audience'] === ['user', 'agent'], 'audience list is normalised');
        kbCheck($commands['tags'] === ['cli', 'setup'], 'tags are parsed');
        kbCheck(strpos($commands['html'], '<h1') === false, 'the first h1 is not duplicated in the article body');
        kbCheck(strpos($commands['html'], '<h2 id="запуск">') !== false, 'headings receive stable ids');
        kbCheck(strpos($commands['html'], 'md-prose__table') !== false, 'GFM tables are rendered');
        kbCheck(strpos($commands['html'], '<script>') === false, 'raw HTML in code blocks is escaped');
        kbCheck(strpos($commands['html'], '&lt;script&gt;') !== false, 'escaped HTML is visible as text');
        kbCheck(count($commands['toc']) === 1 && $commands['toc'][0]['level'] === 2, 'toc contains the h2 section');
    }

    $agents = $kb->document('agents/AGENTS');
    kbCheck(is_array($agents), 'document() renders agents/AGENTS');
    if (is_array($agents)) {
        kbCheck(
            strpos($agents['html'], '/panel/knowledge_base/user/commands.html') !== false,
            'relative Markdown links are rewritten to knowledge-base URLs'
        );
    }

    kbCheck($kb->document('../etc/passwd') === null, 'path traversal is rejected');
    kbCheck($kb->document('missing/document') === null, 'unknown document returns null');
    kbCheck(
        $kb->sourceUrl('user/commands.md') === '/docs/user/commands.md',
        'source URL points to the original Markdown file'
    );
} finally {
    kbRemoveDirectory($root);
}

if ($failures > 0) {
    fwrite(STDERR, 'Knowledge base smoke tests FAILED (' . $failures . ')' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Knowledge base smoke tests passed' . PHP_EOL);
exit(0);
