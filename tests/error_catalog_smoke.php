<?php

/** Контракт каталога ошибок: каждый объявленный код объясняет себя. */
require dirname(__DIR__) . '/lib/core/ErrorCatalog.php';

use MajorDoMo\Core\ErrorCatalog;

$checks = 0;
function check($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

$required = array('property_chain', 'property_delivery', 'property_audit', 'method', 'geturl', 'api_exception', 'api_unavailable');
$codes = ErrorCatalog::codes();
foreach ($required as $code) {
    check(in_array($code, $codes, true), 'Catalogue must describe the core code ' . $code);
}

foreach ($codes as $code) {
    $entry = ErrorCatalog::get($code);
    check(trim($entry['TITLE']) !== '', $code . ': TITLE is required');
    check(mb_strlen($entry['TITLE'], 'UTF-8') <= 255, $code . ': TITLE must fit system_errors.TITLE');

    $details = $entry['DETAILS'];
    foreach (array('Что это:', 'Что произошло:', 'Почему:', 'Чем грозит:', 'Что делать:') as $block) {
        check(str_contains($details, $block), $code . ': DETAILS must explain "' . $block . '"');
    }
    check(!str_contains($details, 'TODO'), $code . ': DETAILS must not be a placeholder');
}

$unknown = ErrorCatalog::get('unknown_code');
check($unknown['TITLE'] === '' && $unknown['DETAILS'] === '', 'Unknown codes must stay untouched for the administrator');

echo 'Error catalogue smoke tests passed: ' . $checks . ' checks.' . PHP_EOL;
