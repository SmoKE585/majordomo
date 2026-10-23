<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use InvalidArgumentException;

/**
 * Проверяет явно заданные адреса ИИ-сервисов, включая локальные серверы.
 *
 * Изменения:
 * 2026-10-23 — только HTTP(S), без токенов в URL, фрагментов и управляющих символов.
 */
final class Endpoint
{
    public static function normalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)
            || $parts === false || !in_array($parts['scheme'] ?? '', array('http', 'https'), true)
            || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Укажите адрес HTTP(S) API без логина, пароля, query-параметров и фрагмента.');
        }
        return rtrim($url, '/');
    }
}
