<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

/**
 * Удаляет распознаваемые секреты из текста документа до поиска и передачи модели.
 *
 * Изменения:
 * 2026-10-23 — рекурсивная очистка служебных данных перед записью в историю.
 * 2026-10-23 — ключи, пароли, Bearer, приватные ключи и пароль внутри URL.
 */
final class SensitiveData
{
    /** Ограниченная рекурсия: метаданные инструментов не должны расти бесконтрольно. */
    public static function deep(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) return '[слишком глубоко]';
        if (is_string($value)) return mb_substr(self::text($value), 0, 20000);
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) return $value;
        if (!is_array($value)) return '';
        $result = array();
        $count = 0;
        foreach ($value as $key => $item) {
            if (++$count > 200) break;
            $result[is_string($key) ? mb_substr($key, 0, 120) : $key] = self::deep($item, $depth + 1);
        }
        return $result;
    }

    public static function text(string $text): string
    {
        $patterns = array(
            '~-----BEGIN (?:[A-Z ]*PRIVATE KEY)-----.*?-----END (?:[A-Z ]*PRIVATE KEY)-----~s',
            '~\bBearer\s+[A-Za-z0-9._\~+/=-]+~i',
            '~(https?://)[^\s/@:]+:[^\s/@]+@~i',
            '~(["\']?(?:[\w.-]*(?:password|passwd|secret|token|api[_-]?key|authorization|credential|пароль|токен|секрет)[\w.-]*)["\']?\s*(?:=>|:|=)\s*)(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s,;\r\n]+)~iu',
        );
        foreach ($patterns as $index => $pattern) {
            $replacement = match ($index) {
                2 => '$1[скрыто]@',
                3 => '$1[скрыто]',
                default => '[скрыто]',
            };
            $text = preg_replace($pattern, $replacement, $text);
        }
        return $text;
    }
}
