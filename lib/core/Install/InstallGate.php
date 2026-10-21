<?php

namespace MajorDoMo\Core\Install;

/**
 * The "setup not finished" gate.
 *
 * It is called from the admin, site and API entry points before they touch the
 * database or the legacy loader. A normal browser request gets a redirect to
 * the wizard; XHR/API clients get a small JSON response with the same URL.
 */
final class InstallGate
{
    public static function redirectIfRequired(string $root, bool $preferJson = false): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (InstallState::isComplete($root)) {
            return;
        }

        $path = self::requestPath();
        if (self::isInstallRequest($path)) {
            return;
        }

        $installUrl = self::installUrl();
        $wantsJson = $preferJson
            || self::isApiRequest($path)
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json'))
            || isset($_GET['part_load'])
            || isset($_GET['ajax_panel'])
            || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, max-age=0');
        header('Retry-After: 30');

        if ($wantsJson) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array(
                'ok' => false,
                'error' => 'install_required',
                'message' => 'Система ещё не настроена. Откройте мастер первичной настройки.',
                'install_url' => $installUrl,
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: ' . $installUrl, true, 302);
        exit;
    }

    public static function installUrl(): string
    {
        $rootHtml = defined('ROOTHTML') ? (string)ROOTHTML : '/';
        return InstallState::installUrl($rootHtml);
    }

    /**
     * Accept both the canonical public/install.php and a pretty /install route
     * (nginx config and dev_server provide the redirect).
     */
    public static function isInstallRequest(?string $path = null): bool
    {
        $path = $path ?? self::requestPath();
        if ($path === '') {
            return false;
        }
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        $root = defined('ROOTHTML') ? '/' . trim((string)ROOTHTML, '/') : '';
        $relative = $path;
        if ($root !== '' && $root !== '/' && str_starts_with($path, $root . '/')) {
            $relative = substr($path, strlen($root));
        }
        $relative = '/' . trim($relative, '/');
        return (bool)preg_match('#^/(?:install|install\.php|install/)$#i', $relative);
    }

    private static function isApiRequest(string $path): bool
    {
        return (bool)preg_match('#/(?:api\.php|api)(?:/|$)#i', $path);
    }

    private static function requestPath(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if ($uri === '' && isset($_SERVER['SCRIPT_NAME'])) {
            $uri = (string)$_SERVER['SCRIPT_NAME'];
        }
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? $path : '';
    }
}
