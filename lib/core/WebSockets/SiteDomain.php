<?php

namespace MajorDoMo\Core\WebSockets;

/**
 * Граница сайта для Origin: домен владельца, а не общая зона или хостинг.
 *
 * 2026-10-23 — локальный Public Suffix List учитывает co.uk, частные зоны,
 * wildcard-правила и исключения без сетевых запросов из WebSocket-процесса.
 */
final class SiteDomain
{
    private static ?array $rules = null;

    /** Нормализует DNS-имя или IP; международные имена сравниваются в ASCII. */
    public static function normalize(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) return (string)inet_ntop(inet_pton($ip));
        if (preg_match('/[^\x00-\x7f]/', $host)) {
            if (!function_exists('idn_to_ascii')) return '';
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($host === false) return '';
        }
        if (strlen($host) > 253 || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $host)) return '';
        return $host;
    }

    /** Возвращает домен владельца; IP, одиночные имена и сами зоны его не имеют. */
    public static function registered(string $host): ?string
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || !str_contains($host, '.')) return null;
        if (self::$rules === null) {
            $lines = file(__DIR__ . '/public_suffix_list.dat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) throw new \RuntimeException('Не удалось прочитать список доменных зон для WebSocket.');
            $rules = array();
            foreach ($lines as $line) if (!str_starts_with($line, '//')) $rules[trim($line)] = true;
            if (!isset($rules['com'], $rules['co.uk'], $rules['github.io'])) throw new \RuntimeException('Список доменных зон для WebSocket повреждён.');
            self::$rules = $rules;
        }
        $labels = explode('.', $host);
        $count = count($labels);
        $suffixLength = 1;
        for ($index = 0; $index < $count; ++$index) {
            $suffix = implode('.', array_slice($labels, $index));
            if (isset(self::$rules['!' . $suffix])) {
                $suffixLength = $count - $index - 1;
                break;
            }
            if (isset(self::$rules[$suffix])) $suffixLength = max($suffixLength, $count - $index);
            if ($index > 0 && isset(self::$rules['*.' . $suffix])) $suffixLength = max($suffixLength, $count - $index + 1);
        }
        return $count > $suffixLength ? implode('.', array_slice($labels, -$suffixLength - 1)) : null;
    }
}
