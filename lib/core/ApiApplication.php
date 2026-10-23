<?php

namespace MajorDoMo\Core;

use MajorDoMo\Core\Api\{AccessPolicy, ApiException, BrowserAccess, Contract, FailureLog, HookRateLimiter, HookStore, Request, Response, TokenStore, UserAuthentication, UserTokenStore, WebhookRunner};
use MajorDoMo\Core\Install\{InstallGate, InstallState};

/**
 * HTTP-граница API ядра.
 * Изменения: 2026-10-23 — пользовательские сеансы и CORS для поддоменов BASE_URL.
 * Изменения: 2026-10-22 — отдельная ветка публичных /api/hooks/<secret>.
 * Изменения: 2026-10-22 — результат AccessPolicy передаётся в Request как actor.
 * Изменения: 2026-10-23 — сбои API (5xx и прерванный запрос) регистрируются в
 * «Ошибках системы» с request_id, маршрутом и стеком, а не только в error_log.
 */
final class ApiApplication extends Application
{
    /** Необязательные зависимости — для изолированных HTTP-тестов и встраивания. */
    public function __construct(
        ?string $root = null,
        private ?\Closure $tokenVerifier = null,
        private ?\Closure $usersFactory = null,
        private ?HookStore $hookStore = null,
        private ?HookRateLimiter $hookLimiter = null,
        private ?UserTokenStore $userTokens = null
    ) {
        parent::__construct($root);
    }

    public function run(): void
    {
        $response = new Response(bin2hex(random_bytes(16)), microtime(true));
        $level = ob_get_level(); ob_start();
        $finished = false; $legacy = false; $webhook = null; $actor = ''; $corsHeaders = array();
        ini_set('display_errors', '0');
        // Легаси-настройки и модули могут завершиться через exit. HTML и
        // частичный вывод не должны попасть клиенту.
        register_shutdown_function(static function () use (&$finished, &$legacy, &$webhook, &$actor, &$corsHeaders, $response, $level): void {
            if ($finished) return;
            // Проверенный webhook может завершиться внутри обработчика. Его
            // вывод уже является ответом: обновляем только диагностику и
            // сохраняем буфер.
            if ($webhook instanceof WebhookRunner) {
                $webhook->finishFromShutdown();
                return;
            }
            while (ob_get_level() > $level) ob_end_clean();
            error_log('MajorDoMo API interrupted; request_id=' . $response->requestId);
            FailureLog::report(self::interrupted(), $response->requestId, $actor);
            $response->emit($response->failure(new ApiException(503, 'unavailable', 'Выполнение API прервано. Проверьте журнал системы.'), $legacy), 503, $corsHeaders + array('Retry-After' => '30'));
        });
        try {
            $this->bootConfiguration();
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '/api/');
            $probe = new Request($method, $uri, array(), array(), Config::string('ROOTHTML'));
            $legacy = $probe->legacy;
            if (!InstallState::isComplete($this->root)) throw new ApiException(503, 'install_required', 'Система ещё не настроена.', array('install_url' => InstallGate::installUrl()), array('Retry-After' => '30'));

            // API и публичные webhook закрываются на время обслуживания. Ответ
            // JSON, чтобы клиенты не пытались разобрать HTML-страницу.
            Maintenance\MaintenanceGate::enforce($this->root, 'api');

            // Публичные webhook проверяют только собственный секрет и никогда
            // не используют общий API-токен, правило локальной сети или сессию
            // администратора. Ветка идёт до JSON-парсера, AccessPolicy и loader.
            if (($probe->segments[0] ?? '') === 'hooks') {
                $legacy = false;
                $secret = count($probe->segments) >= 2 ? (string)$probe->segments[1] : '';
                if (!preg_match(HookStore::SECRET_PATTERN, $secret)) throw new ApiException(404, 'not_found', 'Вход не найден.');
                // Хвост после секрета — это PATH_INFO обработчика (Yandex Smart
                // Home и подобные модули). Он не меняет сохранённый файл.
                $tail = array_slice($probe->segments, 2);
                foreach ($tail as $segment) {
                    if ($segment === '.' || $segment === '..' || str_contains($segment, '/') || str_contains($segment, '\\')) {
                        throw new ApiException(400, 'invalid_path', 'Некорректный путь webhook.');
                    }
                }
                $pathInfo = $tail === array() ? '' : '/' . implode('/', $tail);
                try {
                    $store = $this->hookStore ?? HookStore::connect();
                } catch (\Throwable $error) {
                    error_log('MajorDoMo webhook store unavailable: ' . get_class($error));
                    throw new ApiException(503, 'unavailable', 'Webhook временно недоступен.');
                }
                $webhook = new WebhookRunner($this->root, $store, $this->hookLimiter);
                $webhook->run($secret, function (): void {
                    $this->bootLibraries();
                    $this->bootSettings();
                }, $pathInfo);
                $finished = true;
                return;
            }

            $browser = new BrowserAccess($legacy ? '' : Config::string('BASE_URL'));
            $corsHeaders = $browser->headers($_SERVER);
            if (!$legacy && $method === 'OPTIONS' && $corsHeaders !== array()) {
                $requestedMethod = strtoupper((string)($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? ''));
                Contract::match(new Request($requestedMethod, $uri, array(), array(), Config::string('ROOTHTML')));
                $requestedHeaders = array_filter(array_map('trim', explode(',', strtolower((string)($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '')))));
                if (array_diff($requestedHeaders, array('authorization', 'x-api-key', 'content-type')) !== array()) throw new ApiException(403, 'cross_origin_denied', 'Заголовки запроса не разрешены.');
                while (ob_get_level() > $level) ob_end_clean();
                $finished = true;
                $response->emit($response->success(new \stdClass()), 200, $corsHeaders + array('Access-Control-Allow-Methods' => $requestedMethod, 'Access-Control-Allow-Headers' => 'Authorization, X-API-Key, Content-Type', 'Access-Control-Max-Age' => '600'));
                return;
            }
            $policy = new AccessPolicy(Core::network(), Config::string('HOME_NETWORK'), Config::string('TRUSTED_PROXIES'), $legacy ? '' : Config::string('BASE_URL'));
            $gate = new Auth\AuthGate($this->root, $this->usersFactory);
            $identity = null;
            $authRoute = !$legacy && ($probe->segments[0] ?? '') === 'auth';
            $login = !$legacy && $probe->segments === array('auth', 'login');
            if ($authRoute) Contract::match($probe);
            // Вход публичен: просроченная cookie панели не мешает новому сеансу.
            if ($login) $actor = 'anonymous';
            else {
                $actor = $policy->authorize($_SERVER, function (string $token) use (&$identity): bool {
                    if (str_starts_with($token, 'mdmu_')) {
                        $identity = ($this->userTokens ??= UserTokenStore::connect())->authenticate($token);
                        return true;
                    }
                    return $this->tokenVerifier !== null ? (bool)($this->tokenVerifier)($token) : preg_match('/^mdm_[a-f0-9]{64}$/D', $token) === 1 && TokenStore::connect()->verify($token);
                }, $authRoute ? null : fn() => $gate->authorizeApi());
                if ($identity !== null) $actor = 'user_token';
            }
            $stream = fopen('php://input', 'rb');
            if ($stream === false) throw new ApiException(400, 'invalid_json', 'Не удалось прочитать тело запроса.');
            try { $raw = stream_get_contents($stream, 1048577); } finally { fclose($stream); }
            if ($raw === false) throw new ApiException(400, 'invalid_json', 'Не удалось прочитать тело запроса.');
            $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
            if (!$legacy && in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
                if (strtolower(trim(explode(';', $contentType)[0])) !== 'application/json') throw new ApiException(415, 'unsupported_media_type', 'Используйте Content-Type: application/json.');
                if ($raw === '') throw new ApiException(400, 'invalid_json', 'Передайте JSON-объект, например {}.');
            }
            $body = Request::decode($raw, $contentType, $legacy, $_POST);
            $query = $_GET;
            // Credentials and runtime trace flags are never accepted as user parameters.
            foreach (array('token', 'api_key', 'authorization', '_trace', '_chain') as $key) { unset($query[$key], $body[$key]); }
            $request = new Request($method, $uri, $query, $body, Config::string('ROOTHTML'), $actor);
            if (!$legacy) Contract::match($request);
            // The specification is available without booting the legacy runtime.
            if ($authRoute) {
                $users = $this->usersFactory !== null ? ($this->usersFactory)() : Core::users();
                $authentication = new UserAuthentication($users, $this->userTokens ??= UserTokenStore::connect(), new Auth\LoginAttempts($this->root));
                $ip = Core::network()->clientIp($_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', Config::string('TRUSTED_PROXIES'));
                $data = $authentication->dispatch($request, $identity, $ip);
            }
            elseif (!$legacy && $request->segments === array('openapi.json')) $data = Contract::openApi(Config::string('ROOTHTML'));
            else {
                $this->bootLibraries();
                $language = $_SERVER['HTTP_APP_LANGUAGE'] ?? '';
                if (is_string($language) && preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $language)) $_GET['lang'] = $language;
                $this->bootSettings();
                $data = Core::apiDispatcher()->dispatch($request);
            }
            $payload = !$legacy && $request->segments === array('openapi.json') ? $data : $response->success($data, $legacy, $request);
            $status = 200; $headers = array();
        } catch (ApiException $error) {
            $payload = $response->failure($error, $legacy);
            $status = $legacy && in_array($error->status, array(404, 405, 422), true) ? 400 : $error->status;
            $headers = $error->headers;
            // Осознанный 5xx (например, не выдан билет WebSocket) — тоже сбой:
            // он должен попасть в журнал с причиной, а не только в ответ.
            FailureLog::report($error, $response->requestId, $actor);
        } catch (\Throwable $error) {
            // Exception messages and input may contain secrets; correlate by ID only.
            error_log('MajorDoMo API ' . get_class($error) . '; request_id=' . $response->requestId);
            $payload = $response->failure(new ApiException(500, 'internal_error', 'Внутренняя ошибка API. Передайте request_id администратору.'), $legacy);
            $status = 500; $headers = array();
            // Подробности (маршрут, класс, сообщение, файл, стек) уходят в
            // «Ошибки системы» и errors.log — без них журнал молчал.
            FailureLog::report($error, $response->requestId, $actor);
        }
        while (ob_get_level() > $level) ob_end_clean();
        $finished = true;
        $response->emit($payload, $status, $corsHeaders + $headers);
    }

    /**
     * Причина прерванного запроса: последняя фатальная ошибка PHP, если она
     * есть. Нужна, чтобы в журнале был файл и строка, а не только факт обрыва.
     * 2026-10-23 — добавлено вместе с регистрацией сбоев API.
     */
    private static function interrupted(): \Throwable
    {
        $error = error_get_last();
        $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (is_array($error) && in_array($error['type'] ?? 0, $fatal, true)) {
            return new \ErrorException((string)($error['message'] ?? ''), 0, (int)$error['type'], (string)($error['file'] ?? ''), (int)($error['line'] ?? 0));
        }
        return new \RuntimeException('Выполнение API прервано: обработчик завершил запрос без ответа (exit или фатальная ошибка).');
    }
}
