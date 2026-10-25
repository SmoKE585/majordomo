<?php

namespace MajorDoMo\Core;

/**
 * Единый диагностический конверт ошибок ядра, модулей и фоновых процессов.
 * 2026-10-25 — источник и стек сохраняются при сбое, отдельно от текста для человека.
 */
final class ErrorDetails
{
    private static ?\WeakMap $reported = null;

    public static function wasReported(\Throwable $error): bool
    {
        return self::$reported !== null && isset(self::$reported[$error]);
    }

    public static function decode(string $value): ?array
    {
        $data = json_decode($value, true);
        return is_array($data) && ($data['schema'] ?? '') === 'mjd.error.v1' ? $data : null;
    }

    public static function encode(string $message, array $context = array(), ?\Throwable $error = null): string
    {
        $data = self::decode($message) ?? self::capture($message, $context, $error);
        if ($error !== null) {
            self::$reported ??= new \WeakMap();
            self::$reported[$error] = true;
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    public static function capture(string $message, array $context = array(), ?\Throwable $error = null): array
    {
        $frames = $error ? $error->getTrace() : debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
        $file = $error ? $error->getFile() : '';
        $line = $error ? $error->getLine() : 0;
        $trace = array();
        foreach ($frames as $frame) {
            $path = self::path((string)($frame['file'] ?? ''));
            // Кадры самого регистратора не объясняют, где сломалась операция.
            if (in_array($path, array('lib/core/ErrorDetails.php', 'lib/core/ErrorReporter.php', 'lib/core/Helpers/ActionLog.php'), true)) continue;
            if ($file === '' && $path !== '') {
                $file = $path;
                $line = (int)($frame['line'] ?? 0);
            }
            $trace[] = '#' . count($trace) . ' ' . $path . '(' . (int)($frame['line'] ?? 0) . '): '
                . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
        }
        $script = basename((string)($GLOBALS['argv'][0] ?? ''));
        $cycle = str_starts_with($script, 'cycle_') ? basename($script, '.php') : '';
        $data = array(
            'schema' => 'mjd.error.v1', 'message' => self::clean($message, 6000),
            'file' => self::path($file), 'line' => $line, 'trace' => implode("\n", $trace),
            'cycle' => $cycle, 'entity' => '', 'action' => '',
            'url' => self::clean((string)($_SERVER['REQUEST_URI'] ?? ''), 1500),
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'at' => date('Y-m-d H:i:s'),
        );
        foreach (array('file', 'line', 'cycle', 'entity', 'action', 'url', 'method', 'ip', 'source', 'trigger_event') as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) $data[$key] = $key === 'line' ? max(0, (int)$context[$key]) : self::clean((string)$context[$key], 1500);
        }
        $data['file'] = self::path($data['file']);
        $data['source'] = $data['source'] ?? ($data['cycle'] !== '' ? $data['cycle'] : self::source($data['file']));
        // У API уже есть точное место исключения; оно полезнее кадра отправки отчёта.
        if (!$error && preg_match('/^Location: (.+):(\d+)$/m', $message, $match)) {
            $data['file'] = self::path($match[1]); $data['line'] = (int)$match[2];
            $data['source'] = self::source($data['file']);
        }
        return $data;
    }

    public static function path(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/') . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    public static function source(string $file): string
    {
        if (preg_match('#(?:^|/)modules/([^/]+)(?:/|\.class\.php)#', $file, $match)) return $match[1];
        if (preg_match('#(?:^|/)scripts/(cycle_[^/.]+)\.php#', $file, $match)) return $match[1];
        return 'ядро';
    }

    public static function clean(string $value, int $limit = 12000): string
    {
        $value = str_replace("\r", '', $value);
        $value = (string)preg_replace_callback('/([?&])([^=&\s]+)=([^&\s]*)/', static function (array $match): string {
            return preg_match('/pass|token|secret|auth|cookie|api.?key/i', rawurldecode($match[2]))
                ? $match[1] . $match[2] . '=***' : $match[0];
        }, $value);
        $value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        $value = (string)preg_replace('~(https?://)[^\s/@]+:[^\s/@]+@~i', '$1***@', $value);
        $value = (string)preg_replace('/((?:password|passwd|token|api[_-]?key|secret|authorization|cookie)\s*[=:]\s*)(?:Bearer\s+)?[^\s&,;]+/i', '$1***', $value);
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    public static function text(array $data): string
    {
        return ($data['message'] ?? '') . "\nИсточник: " . ($data['source'] ?? '')
            . "\nФайл: " . ($data['file'] ?? '') . ':' . ($data['line'] ?? 0)
            . "\nBacktrace:\n" . ($data['trace'] ?? '')
            . "\nContext:\nURL: " . ($data['url'] ?? '') . "\nMethod: " . ($data['method'] ?? '') . "\nIP: " . ($data['ip'] ?? '');
    }

    public static function status(int $active, ?array $latest = null, int $historyCount = 0): string
    {
        if ($active <= 0) return 'closed';
        return $active > 1 || (int)($latest['occurrences'] ?? $historyCount) > 1 ? 'repeated' : 'new';
    }

    public static function label(string $status): string
    {
        return array('new' => 'Новая', 'repeated' => 'Повторная', 'closed' => 'Закрыта')[$status] ?? 'Новая';
    }
}
