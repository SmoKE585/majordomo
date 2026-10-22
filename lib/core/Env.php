<?php

namespace MajorDoMo\Core;

/**
 * Minimal dotenv reader.
 *
 * Deliberately small and dependency-free so configuration can boot before the
 * first Composer install. Files are maintained by humans on Windows and Linux.
 *
 * Supported:
 *   KEY=value
 *   KEY="quoted value" / KEY='literal value'
 *   export KEY=value
 *   # full-line comments and ` # inline comments` outside quotes
 *   UTF-8 BOM, CRLF and CR line endings
 *
 * Not supported (on purpose): variable interpolation, multiline values and
 * command substitution. Keep secrets and paths on a single line.
 */
final class Env
{
    /**
     * @return array<string, string> raw string values in file order
     */
    public static function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return array();
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return array();
        }
        return self::parse($contents);
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        $contents = str_replace(array("\r\n", "\r"), "\n", $contents);

        $values = array();
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }
            $name = trim(substr($line, 0, $separator));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
                continue;
            }
            $values[$name] = self::parseValue(substr($line, $separator + 1));
        }
        return $values;
    }

    private static function parseValue(string $raw): string
    {
        $raw = ltrim($raw);
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];
        if ($quote === '"' || $quote === "'") {
            $end = self::closingQuote($raw, $quote);
            $inner = $end === null ? substr($raw, 1) : substr($raw, 1, $end - 1);
            return self::unescape($inner, $quote === '"');
        }

        // A comment starts only after whitespace, so `p#ss` and `a#b` stay intact.
        if (preg_match('/\s+#/', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $raw = substr($raw, 0, $match[0][1]);
        }
        return trim($raw);
    }

    private static function closingQuote(string $raw, string $quote): ?int
    {
        $length = strlen($raw);
        for ($i = 1; $i < $length; $i++) {
            if ($quote === '"' && $raw[$i] === '\\') {
                $i++;
                continue;
            }
            if ($raw[$i] === $quote) {
                return $i;
            }
        }
        return null;
    }

    private static function unescape(string $value, bool $doubleQuoted): string
    {
        if (!$doubleQuoted) {
            return $value;
        }
        // Only the documented escapes are translated; `C:\Program Files` keeps
        // its backslashes because `\P` is not a known sequence.
        return strtr($value, array(
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\"' => '"',
            '\\\\' => '\\',
            '\\$' => '$',
        ));
    }
}
