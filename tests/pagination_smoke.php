<?php

/** 2026-10-22 — границы SQL-пагинации без загрузки приложения и БД. */
require dirname(__DIR__) . '/lib/core/Pagination.php';
use MajorDoMo\Core\Pagination;
$checks = 0;
function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['checks']++;
}
foreach (array(array(0, 50, 1, 1, 0, 0, 0), array(51, 50, 2, 2, 50, 51, 51),
    array(100, 50, -5, 1, 0, 1, 50), array(100, 50, PHP_INT_MAX, 2, 50, 51, 100)) as [$total, $size, $requested, $page, $offset, $first, $last]) {
    $pagination = new Pagination($total, $size, $requested);
    $data = $pagination->template(array('search' => 'a&b + "тест"'));
    check($pagination->page === $page && $pagination->offset === $offset, 'Page and offset normalized');
    check($data['PAGE_FROM'] === $first && $data['PAGE_TO'] === $last, 'Record interval normalized');
}
$pagination = new Pagination(PHP_INT_MAX, 50, 123456);
$data = $pagination->template(array('search' => 'a&b + "тест"'));
check(count($data['PAGES']) <= 9, 'Window is bounded even for huge totals');
check($data['PAGES'][0]['NUM'] === 1 && end($data['PAGES'])['NUM'] === $pagination->pages, 'First and last pages retained');
parse_str((string)parse_url(htmlspecialchars_decode($data['NEXT_URL'], ENT_QUOTES), PHP_URL_QUERY), $query);
check($query === array('search' => 'a&b + "тест"', 'page' => '123457'), 'Filters round trip through links');
check(!str_contains($data['NEXT_URL'], '"') && str_contains($data['NEXT_URL'], '&amp;page='), 'URL safe for a quoted HTML attribute');
foreach (array(array(-1, 50), array(1, 0)) as [$total, $size]) {
    try { new Pagination($total, $size); throw new RuntimeException('Expected invalid pagination'); }
    catch (InvalidArgumentException $error) { $checks++; }
}
echo 'Pagination smoke: ' . $checks . " passed\n";
