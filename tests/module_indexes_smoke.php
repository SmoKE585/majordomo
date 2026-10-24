<?php

// 2026-10-24 — повторная установка не дублирует индексы с произвольными именами.
// Все SQL-функции подменены; загрузчик, рабочая конфигурация и БД не используются.

declare(strict_types=1);

define('DIR_MODULES', sys_get_temp_dir() . '/mjd-module-index-' . bin2hex(random_bytes(8)) . '/');
require_once dirname(__DIR__) . '/lib/module.class.php';

$index_rows = array();
$added_rows = array();
$queries = array();
$add_succeeds = true;

function SQLExec($query)
{
    global $index_rows, $added_rows, $queries, $add_succeeds;
    $queries[] = $query;
    if (str_starts_with($query, 'ALTER TABLE mqtt ADD ')) {
        if ($add_succeeds) $index_rows = array_merge($index_rows, $added_rows);
        return $add_succeeds;
    }
    if (str_starts_with($query, 'CREATE TABLE IF NOT EXISTS mqtt ')
        || $query === 'OPTIMIZE TABLE mqtt;') return true;
    throw new RuntimeException('Неожиданный SQL в изолированном тесте: ' . $query);
}

function SQLGetFields($table)
{
    if ($table !== 'mqtt') throw new RuntimeException('Неожиданная таблица');
    return array(array('Field' => 'ID'));
}

function SQLGetIndexes($table)
{
    global $index_rows;
    if ($table !== 'mqtt') throw new RuntimeException('Неожиданная таблица');
    return $index_rows;
}

function indexRows(string $name, array $columns, string $type = 'BTREE'): array
{
    $rows = array();
    foreach ($columns as $column) {
        $rows[] = array('Key_name' => $name, 'Column_name' => $column,
            'Seq_in_index' => count($rows) + 1, 'Sub_part' => null,
            'Collation' => $type === 'FULLTEXT' ? null : 'A', 'Index_type' => $type);
    }
    return $rows;
}

$linked = indexRows('mqtt_linked_property', array('LINKED_OBJECT', 'LINKED_PROPERTY'));
$prefix = indexRows('topic_prefix', array('PATH'));
$prefix[0]['Sub_part'] = 191;
$legacy_prefix = $prefix;
$legacy_prefix[0]['Key_name'] = 'PATH';
$descending = indexRows('updated_desc', array('UPDATED'));
$descending[0]['Collation'] = 'D';
$fulltext = indexRows('search_title', array('TITLE'), 'FULLTEXT');

$cases = array(
    array('Составной индекс с произвольным именем', $linked, 'INDEX (LINKED_OBJECT, LINKED_PROPERTY)', 0),
    array('Строки SHOW INDEX приходят не по порядку', array_reverse($linked), 'INDEX (LINKED_OBJECT, LINKED_PROPERTY)', 0),
    array('Регистр, пробелы, кавычки и явный ASC', $linked, 'KEY `binding` (`linked_object` ASC, `linked_property`)', 0),
    array('Индекс MQTT history', indexRows('mqtt_history_topic', array('MQTT_ID', 'ID')), 'INDEX (MQTT_ID, ID)', 0),
    array('Одиночный индекс с другим именем', indexRows('mqtt_updated', array('UPDATED')), 'INDEX (UPDATED)', 0),
    array('Обратный порядок колонок требует другой индекс', $linked, 'INDEX (LINKED_PROPERTY, LINKED_OBJECT)', 1),
    array('Два одиночных индекса не заменяют составной', array_merge(indexRows('first', array('LINKED_OBJECT')), indexRows('second', array('LINKED_PROPERTY'))), 'INDEX (LINKED_OBJECT, LINKED_PROPERTY)', 1),
    array('Более длинный индекс не считается точным совпадением', $linked, 'INDEX (LINKED_OBJECT)', 1),
    array('Длина префикса совпадает', $prefix, 'INDEX (`PATH`(191))', 0),
    array('Другая длина префикса', $prefix, 'INDEX (PATH(100))', 1),
    array('Префикс не заменяет полную колонку', $prefix, 'INDEX (PATH)', 1),
    array('Старый префиксный PATH сохраняет совместимость', $legacy_prefix, 'INDEX (PATH)', 0),
    array('Явная новая длина префикса PATH не игнорируется', $legacy_prefix, 'INDEX (PATH(100))', 1),
    array('Направление DESC совпадает', $descending, 'INDEX (UPDATED DESC)', 0),
    array('DESC не заменяет ASC', $descending, 'INDEX (UPDATED)', 1),
    array('FULLTEXT распознаётся по составу', $fulltext, 'FULLTEXT KEY `another_name` (TITLE)', 0),
    array('FULLTEXT не заменяет обычный индекс', $fulltext, 'INDEX (TITLE)', 1),
    array('Обычный индекс не заменяет FULLTEXT', indexRows('title', array('TITLE')), 'FULLTEXT (TITLE)', 1),
    array('Повторное объявление создаёт индекс один раз', array(), "INDEX (LINKED_OBJECT, LINKED_PROPERTY)\nmqtt: INDEX (LINKED_OBJECT, LINKED_PROPERTY)", 1),
    array('Повторная установка не добавляет индекс заново', array(), 'INDEX (LINKED_OBJECT, LINKED_PROPERTY)', 1, $linked, true, 2),
    array('Неудачное добавление не отмечается успешным', array(), "INDEX (LINKED_OBJECT, LINKED_PROPERTY)\nmqtt: INDEX (LINKED_OBJECT, LINKED_PROPERTY)", 2, array(), false),
);

$failures = 0;
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

foreach ($cases as $case) {
    [$label, $index_rows, $definition, $expected] = $case;
    $added_rows = $case[4] ?? array();
    $add_succeeds = $case[5] ?? true;
    $queries = array();
    $subject = (new ReflectionClass(module::class))->newInstanceWithoutConstructor();
    $subject->name = 'mqtt';
    try {
        for ($pass = 0; $pass < ($case[6] ?? 1); $pass++) {
            $subject->dbInstall("mqtt: ID int NOT NULL auto_increment\nmqtt: " . $definition);
        }
        $adds = array_filter($queries, static fn ($query) => str_starts_with($query, 'ALTER TABLE mqtt ADD '));
        if (count($adds) !== $expected) {
            throw new RuntimeException('Ожидалось ADD INDEX: ' . $expected . ', получено: ' . count($adds));
        }
        echo 'ok: ' . $label . PHP_EOL;
    } catch (Throwable $error) {
        $failures++;
        echo 'FAIL: ' . $label . ': ' . $error->getMessage() . PHP_EOL;
    }
}

restore_error_handler();
echo count($cases) . ' проверок, ошибок: ' . $failures . PHP_EOL;
exit($failures === 0 ? 0 : 1);
