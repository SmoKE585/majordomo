<?php

namespace MajorDoMo\Core\Api;

use MajorDoMo\Core\Core;

/**
 * Запись сбоев API в журнал «Ошибки системы» (/panel/system_errors.html).
 *
 * 2026-10-23 — раньше непойманный сбой API уходил только в error_log сервера
 * (класс и request_id), поэтому в журнале панели было пусто. Теперь сбой
 * становится группой журнала с маршрутом, request_id, классом исключения,
 * сообщением, файлом/строкой и стеком вызовов.
 */
final class FailureLog
{
    /** Непойманное исключение: клиент получил 500 internal_error. */
    public const EXCEPTION = 'api_exception';

    /** Обработчик осознанно ответил 5xx (например, не выдал билет WebSocket). */
    public const UNAVAILABLE = 'api_unavailable';

    /**
     * Коды 5xx, которые означают штатный режим, а не сбой: обслуживание,
     * незавершённая установка. Их не пишем в журнал, иначе он заполнится
     * одинаковыми записями на каждый запрос.
     */
    private const EXPECTED = array('unavailable', 'install_required', 'maintenance');

    /** Сколько секунд одинаковые сбои одного маршрута не повторяются. */
    private const WINDOW = 60;

    /** Предел длины сообщения и стека: колонка COMMENTS — TEXT, но читать это глазами. */
    private const MESSAGE_LIMIT = 2000;
    private const TRACE_LIMIT = 8000;

    /** Код группы для журнала; пустая строка — сбой штатный и не журналируется. */
    public static function code(\Throwable $error): string
    {
        if ($error instanceof ApiException) {
            if ($error->status < 500 || in_array($error->errorCode, self::EXPECTED, true)) return '';
            return self::UNAVAILABLE;
        }
        return self::EXCEPTION;
    }

    /** Текст события: то, чего не хватало в error_log для разбора. */
    public static function describe(\Throwable $error, string $requestId, string $actor = ''): string
    {
        $result = $error instanceof ApiException
            ? 'HTTP ' . $error->status . ' ' . $error->errorCode
            : 'HTTP 500 internal_error';
        $lines = array(
            'Request: ' . self::requestLine(),
            'Result: ' . $result,
            'Request ID: ' . $requestId,
        );
        if ($actor !== '') $lines[] = 'Actor: ' . $actor;
        $lines[] = 'Exception: ' . get_class($error);
        $lines[] = 'Message: ' . self::message($error);
        $lines[] = 'Location: ' . self::shortPath($error->getFile()) . ':' . $error->getLine();
        $previous = $error->getPrevious();
        if ($previous !== null) {
            $lines[] = 'Previous: ' . get_class($previous) . ' — ' . self::message($previous);
        }
        $trace = trim($error->getTraceAsString());
        if ($trace !== '') {
            $lines[] = 'Trace:';
            $lines[] = mb_substr(self::trimPaths($trace), 0, self::TRACE_LIMIT);
        }
        return implode("\n", $lines);
    }

    /**
     * Регистрирует сбой в журнале. Вызывать можно на любой 5xx: 4xx и штатные
     * 5xx отбрасываются внутри, поэтому вызывающий код не дублирует правила.
     *
     * @param callable|null $sink тестовый приёмник: fn(string $code, string $details, string $key)
     */
    public static function report(\Throwable $error, string $requestId, string $actor = '', ?callable $sink = null): void
    {
        $code = self::code($error);
        if ($code === '') return;
        $details = self::describe($error, $requestId, $actor);
        $key = self::key();
        try {
            if ($sink !== null) {
                $sink($code, $details, $key);
                return;
            }
            Core::errors()->report($code, $details, $key, self::WINDOW);
        } catch (\Throwable $ignored) {
            // Журнал может быть недоступен по той же причине, что и сам сбой.
            // Тогда остаётся error_log: строка уже пишется в ApiApplication.
            error_log('MajorDoMo API failure could not be registered: ' . get_class($ignored));
        }
    }

    /** Ключ подавления повторов: маршрут без query, чтобы токены не влияли. */
    private static function key(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: $uri);
        return $path === '' ? 'api' : mb_substr($path, 0, 200);
    }

    private static function requestLine(): string
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'));
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: $uri);
        $query = (string)parse_url($uri, PHP_URL_QUERY);
        return $method . ' ' . $path . ($query === '' ? '' : '?' . self::redact($query));
    }

    /** Секреты не должны попадать в журнал из query string. */
    private static function redact(string $query): string
    {
        $parts = array();
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') continue;
            $name = strtolower((string)strstr($pair, '=', true));
            $parts[] = in_array($name, array('token', 'api_key', 'authorization', 'password', 'secret'), true)
                ? rawurlencode($name) . '=***'
                : $pair;
        }
        return implode('&', $parts);
    }

    private static function message(\Throwable $error): string
    {
        // Текст исключения — одной строкой без управляющих символов:
        // переносы ломают чтение события, а стек ниже и так многострочный.
        $message = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$error->getMessage());
        $message = trim((string)preg_replace('/\s+/u', ' ', $message));
        return $message === '' ? '(без текста)' : mb_substr($message, 0, self::MESSAGE_LIMIT);
    }

    /** Абсолютные пути проекта в журнале не нужны: они мешают читать стек. */
    private static function shortPath(string $path): string
    {
        $trimmed = self::trimPaths(str_replace('\\', '/', $path));
        return $trimmed;
    }

    /** Убирает префикс каталога проекта и в прямых, и в обратных слэшах. */
    private static function trimPaths(string $value): string
    {
        $root = rtrim(str_replace('\\', '/', \MajorDoMo\Core\Config::string('ROOT')), '/');
        if ($root === '') return $value;
        return str_replace(array($root . '/', str_replace('/', '\\', $root) . '\\'), '', $value);
    }
}
