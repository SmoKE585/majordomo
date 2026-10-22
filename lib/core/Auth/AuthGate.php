<?php
namespace MajorDoMo\Core\Auth;

use MajorDoMo\Core\{Config, Core};
use MajorDoMo\Core\Http\Session;
use MajorDoMo\Core\Users\UserAccounts;
use MajorDoMo\Core\Api\ApiException;
use MajorDoMo\Core\Api\HookContext;

/**
 * Проверка входа до загрузки модулей и БД.
 * Изменения: 2026-10-22 — удалено исключение для упразднённого /objects/.
 * Изменения: 2026-10-22 — проверенный webhook пропускает только собственный вызов.
 */
final class AuthGate
{
    public function __construct(private string $root, private ?\Closure $usersFactory = null) {}
    private function users(): UserAccounts { return $this->usersFactory !== null ? ($this->usersFactory)() : Core::users(); }

    public static function enforce(string $root): void
    {
        if (PHP_SAPI === 'cli') return;
        // Этот флаг процесса выставляет только проверенный /api/hooks/<secret>.
        // Он не выводится из query-параметра, заголовка или совпадения URI.
        if (HookContext::active()) return;
        // API has its own authentication boundary; a query parameter cannot skip it.
        $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if ($script !== false && $script === realpath($root . '/public/api.php')) return;
        (new self($root))->protect();
    }

    public function protect(): void
    {
        try {
            $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
            $session = Core::session($this->root);
            $loginEndpoint = $script !== false && $script === realpath($this->root . '/public/admin.php');
            $operation = $loginEndpoint && is_string($_GET['auth'] ?? null) ? $_GET['auth'] : '';
            if ($operation === 'login') $this->login($session);
            $user = isset($_SESSION['md_admin']) ? (new Authentication($session, $this->users()))->current() : null;
            if ($user === null) {
                if ($this->isAjax() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') $this->failure(401, 'authentication_required', 'Необходимо войти в систему.');
                header('Location: ' . self::loginUrl(), true, 302); exit;
            }
            if (!RequestGuard::allowed($session, $_SERVER, $_POST)) $this->failure(403, 'csrf_failed', 'Запрос отклонён. Обновите страницу.');
            if ($operation === 'logout') {
                if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$session->checkCsrf($_POST['_md_csrf'] ?? null)) $this->failure(403, 'csrf_failed', 'Запрос отклонён.');
                $this->attempts()->record('logout', $this->clientIp(), $user['username']);
                $session->logout();
                header('Location: ' . self::loginUrl(), true, 303); exit;
            }
            header('Cache-Control: no-store');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: same-origin');
        } catch (ApiException $error) {
            $this->failure($error->status, $error->errorCode, $error->getMessage());
        } catch (\Throwable $error) {
            if (isset($operation) && $operation === 'login' && isset($session)) $session->logout();
            // The exception may contain credentials or SQL; log its class only.
            error_log('MajorDoMo authentication unavailable: ' . get_class($error));
            $this->failure(503, 'authentication_unavailable', 'Авторизация временно недоступна. Проверьте журнал сервера и доступ к БД.');
        }
    }

    /** API cookies are accepted only as a checked administrator session. */
    public function authorizeApi(): bool
    {
        if (!isset($_COOKIE['prj'])) return false;
        $session = Core::session($this->root);
        $user = isset($_SESSION['md_admin']) ? (new Authentication($session, $this->users()))->current() : null;
        if ($user === null) throw new ApiException(401, 'session_expired', 'Сессия администратора завершена. Войдите снова.');
        if (!RequestGuard::allowed($session, $_SERVER, $_POST, true)) throw new ApiException(403, 'csrf_failed', 'Передайте действующий X-CSRF-Token.');
        return true;
    }

    private function login(Session $session): never
    {
        $error = ''; $username = ''; $status = 200;
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, array('GET', 'POST'), true)) { header('Allow: GET, POST'); $this->failure(405, 'method_not_allowed', 'Метод не поддерживается.'); }
        if ($method === 'POST') {
            $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            if (!RequestGuard::allowed($session, $_SERVER, $_POST) || !$session->checkCsrf($_POST['_md_csrf'] ?? null)) {
                $this->attempts()->record('csrf_rejected', $this->clientIp(), $username);
                $error = 'Форма устарела. Повторите вход.'; $status = 403;
            } else {
                $result = $this->attempts()->attempt($this->clientIp(), $username, fn() => (new Authentication($session, $this->users()))->login($username, $password));
                if ($result['ok']) { header('Location: ' . Session::cookiePath() . 'panel/', true, 303); exit; }
                if ($result['retry'] > 0) { header('Retry-After: ' . $result['retry']); $error = 'Слишком много попыток. Повторите вход через ' . (int)ceil($result['retry'] / 60) . ' мин.'; $status = 429; }
                else { $error = 'Неверный логин, пароль или нет прав администратора.'; $status = 401; }
            }
        }
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        $csrf = $session->csrfToken();
        $rootUrl = Session::cookiePath();
        require $this->root . '/lib/ui/MDJUI.php';
        require $this->root . '/templates/auth/login.php';
        exit;
    }

    public static function loginUrl(): string { return Session::cookiePath() . 'admin.php?auth=login'; }
    private function attempts(): LoginAttempts { return new LoginAttempts($this->root); }
    private function clientIp(): string
    {
        return Core::network()->clientIp($_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', Config::string('TRUSTED_PROXIES'));
    }
    private function isAjax(): bool
    {
        return isset($_GET['part_load']) || isset($_GET['ajax']) || isset($_GET['ajax_panel']) || isset($_GET['dynids'])
            || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }
    private function failure(int $status, string $code, string $message): never
    {
        http_response_code($status);
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'error' => array('code' => $code, 'message' => $message), 'login_url' => self::loginUrl(), 'NEED_RELOAD' => 1, 'CONTENT' => '', 'TITLE' => ''), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
