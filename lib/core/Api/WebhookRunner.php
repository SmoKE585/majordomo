<?php

namespace MajorDoMo\Core\Api;

/**
 * Исполняет один проверенный вызов публичного webhook.
 *
 * Запрос передаётся сохранённому обработчику модуля без разбора как JSON API:
 * метод, query, форма, файлы, сырой php://input и заголовки остаются как есть.
 * Ответ обработчика отправляется без обёртки; обычный JSON-конверт API
 * используется только для ошибок самой границы webhook.
 *
 * Изменения: 2026-10-22 — добавлен исполнитель публичных webhook.
 */
final class WebhookRunner
{
    public const MAX_BODY = 1048576;

    private ?array $hook = null;
    private bool $finished = false;
    private string $handlerPath = '';
    private HookRateLimiter $limiter;

    public function __construct(private string $root, private HookStore $store, ?HookRateLimiter $limiter = null)
    {
        $this->limiter = $limiter ?? new HookRateLimiter($root);
    }

    /**
     * @param callable():void $boot загружает легаси-runtime и настройки после
     *        проверки, чтобы обработчик увидел обычный контекст MajorDoMo.
     */
    public function run(string $secret, callable $boot): void
    {
        $record = $this->store->findBySecret($secret);
        if ($record === null) {
            throw new ApiException(404, 'not_found', 'Вход не найден.');
        }
        $this->hook = $record;
        if ((int)$record['ENABLED'] !== 1) {
            $this->reject(404, 'not_found', 'Вход не найден.');
        }
        $methods = HookStore::methods($record);
        if ($methods === array()) {
            $this->reject(404, 'not_found', 'Вход не найден.');
        }

        try {
            $retry = $this->limiter->hit((int)$record['ID']);
        } catch (\Throwable $error) {
            error_log('MajorDoMo webhook limiter unavailable: ' . get_class($error));
            $this->reject(503, 'unavailable', 'Webhook временно недоступен.');
        }
        if ($retry > 0) {
            $this->reject(429, 'rate_limited', 'Слишком много обращений. Повторите позже.', array('Retry-After' => (string)$retry));
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, $methods, true)) {
            $this->reject(405, 'method_not_allowed', 'HTTP-метод не разрешён для этого входа.', array('Allow' => implode(', ', $methods)));
        }
        if (!$this->headerAllowed($record)) {
            $this->reject(401, 'invalid_webhook_secret', 'Не удалось подтвердить дополнительный секрет.');
        }
        $this->assertBodySize();

        try {
            $this->handlerPath = HookRegistry::resolve($this->root, (string)$record['MODULE'], (string)$record['HANDLER']);
        } catch (\Throwable $error) {
            error_log('MajorDoMo webhook handler unavailable: ' . get_class($error));
            $this->reject(404, 'not_found', 'Обработчик входа недоступен.');
        }

        // Разрешение живёт в процессе только на время этого проверенного
        // вызова. Загрузчик и AuthGate видят его и пускают обработчик в обычный
        // runtime, не открывая админку и общий API.
        HookContext::activate($record);
        $level = ob_get_level();
        ob_start();
        try {
            // Контекст совместимости готовим до загрузчика: THIS_URL и
            // настройки видят уже очищенный URI, а AuthGate — проверенное
            // разрешение текущего процесса.
            $this->prepareServer();
            $boot();
            include $this->handlerPath;
            $this->finish($this->statusCode());
            while (ob_get_level() > $level) {
                ob_end_flush();
            }
        } catch (ApiException $error) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $this->finish($error->status);
            throw $error;
        } catch (\Throwable $error) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $this->finish(500);
            throw $error;
        } finally {
            HookContext::reset();
        }
    }

    /** Вызывается из shutdown-обработчика, если модуль завершился через exit/die. */
    public function finishFromShutdown(): void
    {
        if ($this->hook === null || $this->finished) {
            return;
        }
        $error = error_get_last();
        $fatal = is_array($error) && in_array((int)($error['type'] ?? 0), array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true);
        try {
            $this->finish($fatal ? 500 : $this->statusCode());
        } finally {
            HookContext::reset();
        }
    }

    private function headerAllowed(array $record): bool
    {
        $name = trim((string)($record['HEADER_NAME'] ?? ''));
        $hash = $record['HEADER_SECRET_HASH'] ?? null;
        if ($name === '') {
            return $hash === null;
        }
        if (!is_string($hash) || $hash === '') {
            return false;
        }
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;
        return is_string($value) && $value !== '' && hash_equals($hash, hash('sha256', $value));
    }

    private function assertBodySize(): void
    {
        $length = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : null;
        if ($length !== null && $length > self::MAX_BODY) {
            $this->reject(413, 'payload_too_large', 'Максимальный размер запроса — 1 МиБ.');
        }
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            $this->reject(400, 'invalid_request', 'Не удалось прочитать тело запроса.');
        }
        try {
            // Чтение php://input не забирает поток у обработчика: PHP оставляет
            // сырой поток читаемым повторно, кроме multipart. Для multipart
            // данные уже доступны через $_POST/$_FILES.
            $raw = stream_get_contents($stream, self::MAX_BODY + 1);
        } finally {
            fclose($stream);
        }
        if ($raw === false) {
            $this->reject(400, 'invalid_request', 'Не удалось прочитать тело запроса.');
        }
        if (strlen($raw) > self::MAX_BODY) {
            $this->reject(413, 'payload_too_large', 'Максимальный размер запроса — 1 МиБ.');
        }
    }

    private function prepareServer(): void
    {
        $relative = 'modules/' . (string)$this->hook['MODULE'] . '/' . (string)$this->hook['HANDLER'];
        $_SERVER['SCRIPT_FILENAME'] = $this->handlerPath;
        $_SERVER['SCRIPT_NAME'] = '/' . $relative;
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
        if (empty($_SERVER['DOCUMENT_ROOT'])) {
            $_SERVER['DOCUMENT_ROOT'] = rtrim(str_replace('\\', '/', $this->root), '/') . '/public';
        }
        // Убираем секрет из REQUEST_URI: его читают старые журналы действий и
        // аудита. Точный URL движка не входит в контракт обработчика, а
        // SCRIPT_NAME, query, форма, тело и заголовки остаются без изменений.
        if (isset($_SERVER['REQUEST_URI'])) {
            $redacted = preg_replace('#(/api(?:\.php)?/hooks/)[a-f0-9]{64}#i', '$1[redacted]', (string)$_SERVER['REQUEST_URI']);
            if (is_string($redacted)) {
                $_SERVER['REQUEST_URI'] = $redacted;
            }
        }
        // Легаси-обработчики MajorDoMo ищут config.php/lib/loader.php от корня
        // приложения — так же, как публичные точки входа.
        chdir($this->root);
    }

    private function reject(int $status, string $code, string $message, array $headers = array()): never
    {
        $this->finish($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        throw new ApiException($status, $code, $message, array(), $headers);
    }

    private function finish(int $status): void
    {
        if ($this->finished || $this->hook === null) {
            return;
        }
        $this->finished = true;
        try {
            $this->store->touch((int)$this->hook['ID'], $status, (string)($_SERVER['REQUEST_METHOD'] ?? ''));
        } catch (\Throwable $error) {
            // Диагностика не должна ломать ответ обработчика или раскрывать детали.
            error_log('MajorDoMo webhook diagnostics unavailable: ' . get_class($error));
        }
    }

    private function statusCode(): int
    {
        $status = http_response_code();
        return is_int($status) && $status >= 100 && $status <= 599 ? $status : 200;
    }
}
