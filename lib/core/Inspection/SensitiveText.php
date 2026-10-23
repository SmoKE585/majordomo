<?php

declare(strict_types=1);

namespace MajorDoMo\Core\Inspection;

/**
 * Убирает распознаваемые секреты из справочных данных до передачи модели.
 *
 * Изменения:
 * 2026-10-23 — тот же набор правил, что в ИИ-помощнике, без зависимости ядра от модуля.
 */
final class SensitiveText
{
    public static function clean(string $text): string
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
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }
        return $text;
    }
}
