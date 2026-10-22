<?php

/** База знаний не должна разрезать UTF-8 во front matter на байте 0x85. */
require dirname(__DIR__) . '/lib/knowledge_base.php';

$checks = 0;
function check($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

$tmp = rtrim(sys_get_temp_dir(), '/\\') . '/mjd-kb-utf8-' . bin2hex(random_bytes(5));
$docs = $tmp . '/docs';
mkdir($docs . '/agents', 0777, true);

try {
    // «х» — это U+0445 = d1 85. Без /u PCRE-шный \R считает 0x85 переводом
    // строки NEL и режет строку посередине — отсюда исходный баг
    // «Сборка админски�».
    $title = 'Сборка админских ассетов';
    $description = 'Как устроена сборка: характеристики, успехи и ошибки.';
    file_put_contents(
        $docs . '/agents/utf8.md',
        "---\ntitle: {$title}\naudience: agent\ncategory: ui\ndescription: {$description}\n---\n\n# {$title}\n"
    );

    // Деплой или редактор может оставить в файле битый байт; он не должен
    // попасть в карточку как символ-заменитель.
    file_put_contents(
        $docs . '/agents/broken.md',
        "---\ntitle: Тест\x85заголовок\naudience: agent\ncategory: ui\ndescription: Описание\n---\n\n# Тест\n"
    );

    $kb = new MDKnowledgeBase($docs, '/');
    $document = $kb->document('agents/utf8');
    check($document !== null, 'UTF-8 document is indexed');
    check($document['title'] === $title, 'Title with "х" is parsed intact');
    check($document['description'] === $description, 'Description with "х" is parsed intact');
    check(mb_check_encoding($document['title'], 'UTF-8'), 'Parsed title is valid UTF-8');

    $broken = $kb->document('agents/broken');
    check($broken !== null, 'Document with a broken byte is still readable');
    check(mb_check_encoding($broken['title'], 'UTF-8'), 'Broken byte is removed from the title');
    check(!str_contains($broken['title'], "\xEF\xBF\xBD"), 'Title never contains the replacement character');

    echo 'Knowledge base UTF-8 smoke tests passed: ' . $checks . ' checks.' . PHP_EOL;
} finally {
    $remove = static function ($path) use (&$remove) {
        if (!is_dir($path)) { @unlink($path); return; }
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
        }
        @rmdir($path);
    };
    $remove($tmp);
}
