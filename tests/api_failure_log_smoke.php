<?php

/**
 * Сбой API обязан попасть в «Ошибки системы» с подробностями для разбора,
 * а штатные 4xx/5xx — нет. Без этого страница /panel/system_errors.html
 * молчала при 500 на /api/v1/panel/websocket-ticket.
 */
putenv('APP_ENV=testing');
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Api\{ApiException, FailureLog};
use MajorDoMo\Core\Config;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    ++$checks;
}

Config::boot(sys_get_temp_dir() . '/mjd-api-failure-' . bin2hex(random_bytes(4)));
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/v1/panel/websocket-ticket?tab=1&token=secret-token';

/** Исключение создаётся в функции: только тогда у него есть стек вызовов. */
function makeFailure(): RuntimeException
{
    return new RuntimeException('Файл сессии администратора не найден.');
}

function capture(\Throwable $error): array
{
    $captured = array();
    FailureLog::report($error, 'req-123', 'session', static function (string $code, string $details, string $key) use (&$captured): void {
        $captured = array($code, $details, $key);
    });
    return $captured;
}

// 1. Непойманная ошибка — группа api_exception с полным контекстом.
$event = capture(makeFailure());
check($event !== array(), 'uncaught API failure must be registered');
check($event[0] === FailureLog::EXCEPTION, 'uncaught failure is api_exception');
check(str_contains($event[1], 'GET /api/v1/panel/websocket-ticket'), 'route is recorded');
check(str_contains($event[1], 'tab=1'), 'safe query arguments are kept');
check(str_contains($event[1], 'token=***'), 'token is redacted');
check(!str_contains($event[1], 'secret-token'), 'token value never reaches the journal');
check(str_contains($event[1], 'req-123'), 'request id is recorded');
check(str_contains($event[1], 'Actor: session'), 'actor is recorded');
check(str_contains($event[1], 'HTTP 500 internal_error'), 'result is recorded');
check(str_contains($event[1], 'RuntimeException'), 'exception class is recorded');
check(str_contains($event[1], 'Файл сессии администратора не найден.'), 'message is recorded');
check(str_contains($event[1], 'api_failure_log_smoke.php:'), 'file and line are recorded');
check(str_contains($event[1], 'Trace:'), 'stack trace is recorded');
check($event[2] === '/api/v1/panel/websocket-ticket', 'dedupe key is the route without query');

// 2. Осознанный 5xx (не выдан билет) — отдельная группа с причиной.
$deliberate = capture(new ApiException(503, 'websocket_ticket_unavailable', 'Билет WebSocket не выдан: нет прав на var/security.'));
check($deliberate[0] === FailureLog::UNAVAILABLE, 'deliberate 5xx is api_unavailable');
check(str_contains($deliberate[1], '503 websocket_ticket_unavailable'), 'status and code are recorded');
check(str_contains($deliberate[1], 'нет прав на var/security'), 'reason is visible without the server log');

// 3. Штатные ответы не засоряют журнал.
check(capture(new ApiException(422, 'validation_failed', 'Некорректное поле.')) === array(), '4xx is not an error');
check(capture(new ApiException(401, 'session_expired', 'Сессия завершена.')) === array(), '401 is not an error');
check(capture(new ApiException(503, 'unavailable', 'Режим обслуживания.')) === array(), 'maintenance 503 is not an error');
check(capture(new ApiException(503, 'install_required', 'Система не настроена.')) === array(), 'install gate is not an error');

// 4. Причина сбоя не искажается управляющими символами и переносами.
$multiline = capture(new RuntimeException("Строка один\nСтрока два\x07"));
check(!str_contains($multiline[1], "\x07"), 'control characters are stripped');
check(str_contains($multiline[1], 'Message: Строка один Строка два'), 'message stays on one line');

printf("API failure log smoke: %d passed (routes, redaction, deliberate 5xx, quiet 4xx).\n", $checks);
