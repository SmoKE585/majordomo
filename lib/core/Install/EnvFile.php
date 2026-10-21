<?php

namespace MajorDoMo\Core\Install;

use MajorDoMo\Core\Env;

/**
 * Safe, comment-preserving writer for the project `.env`.
 *
 * The wizard must not destroy the documented structure of `.env.example`:
 * existing lines are rewritten in place, commented defaults are enabled in
 * place, and only genuinely new keys are appended in a small managed block.
 * The parser is last-value-wins, so appending is always safe even if an older
 * duplicate exists somewhere above.
 */
final class EnvFile
{
    /**
     * @return array<string,string>
     */
    public static function read(string $root): array
    {
        return Env::parseFile(self::path($root));
    }

    /**
     * Values from `.env.local`, which take precedence over `.env`.
     *
     * @return array<string,string>
     */
    public static function localOverrides(string $root): array
    {
        return Env::parseFile(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '.env.local');
    }

    public static function path(string $root): string
    {
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '.env';
    }

    /**
     * Apply values to `.env`.
     *
     * `null` removes the key (used for optional settings whose empty value means
     * "use the default"), while an empty string writes `KEY=`.
     *
     * @param array<string,string|null> $values
     * @return array{ok:bool,error:string,updated:int,appended:int,removed:int,path:string}
     */
    public static function apply(string $root, array $values): array
    {
        $path = self::path($root);
        $result = array(
            'ok' => false,
            'error' => '',
            'updated' => 0,
            'appended' => 0,
            'removed' => 0,
            'path' => $path,
        );

        if (!is_file($path) && !is_writable(dirname($path))) {
            $result['error'] = 'Каталог проекта недоступен для записи: не удаётся создать .env.';
            return $result;
        }
        if (is_file($path) && !is_writable($path)) {
            $result['error'] = 'Файл .env защищён от записи. Выдайте веб-серверу права на запись.';
            return $result;
        }

        $contents = is_file($path) ? @file_get_contents($path) : '';
        if (!is_string($contents)) {
            $result['error'] = 'Не удалось прочитать .env.';
            return $result;
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        $contents = str_replace(array("\r\n", "\r"), "\n", $contents);

        $lines = $contents === '' ? array() : explode("\n", $contents);
        if ($lines !== array() && end($lines) === '') {
            array_pop($lines);
        }
        if ($lines === array()) {
            $lines[] = '# MajorDoMo configuration';
            $lines[] = '# Created by the install wizard. The full reference is in .env.example.';
        }

        $active = array();
        $commented = array();
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $match)) {
                $active[$match[1]][] = $index;
            } elseif (preg_match('/^\s*#\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $match)) {
                $commented[$match[1]][] = $index;
            }
        }

        $append = array();
        foreach ($values as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key)) {
                continue;
            }
            if ($value !== null && !is_scalar($value)) {
                continue;
            }
            $value = $value === null ? null : (string)$value;
            if ($value !== null && preg_match('/[\r\n\x00]/', $value)) {
                $result['error'] = 'Значение ' . $key . ' содержит перевод строки — это недопустимо для .env.';
                return $result;
            }

            if ($value === null) {
                if (isset($active[$key])) {
                    foreach ($active[$key] as $index) {
                        $lines[$index] = null;
                        $result['removed']++;
                    }
                    unset($active[$key]);
                }
                unset($append[$key]);
                continue;
            }

            $formatted = $key . '=' . self::formatValue($value);
            if (isset($active[$key]) && $active[$key] !== array()) {
                $first = array_shift($active[$key]);
                $lines[$first] = $formatted;
                foreach ($active[$key] as $index) {
                    $lines[$index] = null;
                }
                $result['updated']++;
                continue;
            }
            if (isset($commented[$key]) && $commented[$key] !== array()) {
                // `.env.example` keeps most optional keys commented out. Turn
                // that exact documented line into a real value instead of
                // creating a second, unexplained copy at the bottom.
                $first = array_shift($commented[$key]);
                $lines[$first] = $formatted;
                $result['updated']++;
                continue;
            }
            $append[$key] = $formatted;
        }

        if ($append !== array()) {
            if ($lines !== array() && end($lines) !== null && trim((string)end($lines)) !== '') {
                $lines[] = '';
            }
            $lines[] = '# --- MajorDoMo install wizard, ' . gmdate('Y-m-d H:i') . ' UTC ---';
            foreach ($append as $formatted) {
                $lines[] = $formatted;
                $result['appended']++;
            }
        }

        $output = array();
        foreach ($lines as $line) {
            if ($line === null) {
                continue;
            }
            $output[] = $line;
        }
        $final = implode("\n", $output);
        if ($final !== '') {
            $final .= "\n";
        }

        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $final, LOCK_EX) === false) {
            $result['error'] = 'Не удалось записать временный файл .env.';
            @unlink($temporary);
            return $result;
        }
        @chmod($temporary, 0640);
        if (!@rename($temporary, $path)) {
            @unlink($path);
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
                $result['error'] = 'Не удалось заменить .env новым файлом.';
                return $result;
            }
        }

        $result['ok'] = true;
        return $result;
    }

    /**
     * Timestamped copy next to `.env`. Returns the absolute path or null.
     */
    public static function backup(string $root): ?string
    {
        $path = self::path($root);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $base = $path . '.bak.' . date('Ymd-His');
        $candidate = $base;
        $suffix = 1;
        while (file_exists($candidate)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }
        if (!@copy($path, $candidate)) {
            return null;
        }
        @chmod($candidate, 0600);
        return $candidate;
    }

    /**
     * Format a value so `Env::parse()` returns exactly the same string.
     */
    public static function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        // Plain values stay readable. Anything with whitespace, quotes, `#`,
        // `$` or shell-significant characters is double quoted and escaped.
        if (preg_match('/^[A-Za-z0-9_.:\/@%+\-=*;]+$/D', $value)) {
            return $value;
        }
        return '"' . strtr($value, array(
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        )) . '"';
    }

    /**
     * Keys from `.env.local` that win over the values the wizard is about to
     * write, so the UI can warn instead of silently doing nothing.
     *
     * @param string[] $keys
     * @return string[]
     */
    public static function overriddenKeys(string $root, array $keys): array
    {
        $local = self::localOverrides($root);
        return array_values(array_filter($keys, static function (string $key) use ($local): bool {
            return array_key_exists($key, $local);
        }));
    }
}
