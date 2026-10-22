<?php

namespace MajorDoMo\Core;

/**
 * Пагинация SQL-выборок: нормализует страницу и строит ограниченное окно ссылок.
 * Изменения: 2026-10-22 — заменяет внешний Paginator без загрузки всех строк.
 */
final class Pagination
{
    public readonly int $page;
    public readonly int $pages;
    public readonly int $offset;

    public function __construct(public readonly int $total, public readonly int $perPage, int $page = 1)
    {
        if ($total < 0 || $perPage < 1) throw new \InvalidArgumentException('Некорректные параметры пагинации.');
        $this->pages = intdiv($total, $perPage) + ($total % $perPage > 0 ? 1 : 0);
        $this->page = max(1, min($page, max(1, $this->pages)));
        $this->offset = ($this->page - 1) * $perPage;
    }

    public function template(array $query): array
    {
        $url = static fn(int $page): string => htmlspecialchars(
            '?' . http_build_query(array_replace($query, array('page' => $page)), '', '&', PHP_QUERY_RFC3986),
            ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
        );
        // Две крайние страницы и окно из пяти номеров; размер не зависит от total.
        $start = max(1, min($this->page - 2, $this->pages - 4));
        $end = min($this->pages, $start + 4);
        $numbers = $this->pages > 0 ? array_unique(array_merge(array(1), range($start, $end), array($this->pages))) : array();
        $links = array();
        $previous = 0;
        foreach ($numbers as $number) {
            if ($number > $previous + 1) $links[] = array('NUM' => '…', 'URL' => '', 'SELECTED' => 0, 'IS_ELLIPSIS' => 1);
            $links[] = array('NUM' => $number, 'URL' => $url($number), 'SELECTED' => (int)($number === $this->page), 'IS_ELLIPSIS' => 0);
            $previous = $number;
        }
        return array(
            'PAGES' => $links, 'PAGE' => $this->page, 'PAGE_CURRENT' => $this->page, 'PAGE_TOTAL' => $this->pages,
            'PAGE_FROM' => $this->total > 0 ? $this->offset + 1 : 0,
            'PAGE_TO' => $this->offset + min($this->perPage, $this->total - $this->offset),
            'PREV_URL' => $this->page > 1 ? $url($this->page - 1) : '',
            'NEXT_URL' => $this->page < $this->pages ? $url($this->page + 1) : '',
        );
    }
}
