<?php

namespace MajorDoMo\Core\Maintenance;

use MajorDoMo\Core\Install\InstallGate;

/**
 * HTTP-граница режима обслуживания.
 *
 * Сайт и API получают 503 с Retry-After. Админка, вход и статические файлы
 * остаются доступны: иначе нельзя ни посмотреть прогресс обновления, ни
 * выключить режим через панель.
 *
 * 2026-10-22 — добавлено для деплоя без «полуживого» сайта.
 */
final class MaintenanceGate
{
    /** @return array{status:int,headers:array<string,string>,body:string}|null */
    public static function response(string $root, string $area = 'site', ?string $path = null, bool $preferJson = false): ?array
    {
        $mode = new MaintenanceMode($root);
        $status = $mode->status();
        if (empty($status['active'])) return null;

        $path = $path ?? self::requestPath();
        if (self::isAssetRequest($path) || self::isAdminRequest($path) || self::isInstallRequest($path)) {
            return null;
        }
        if ($area === 'admin') return null;

        $wantsJson = $preferJson || $area === 'api' || self::isApiRequest($path) || self::prefersJson();
        $message = trim((string)$status['message']);
        if ($message === '') {
            $message = !empty($status['failed'])
                ? 'Обновление системы прервано. Идут технические работы.'
                : 'Сайт временно на обслуживании. Идёт обновление системы.';
        }
        $retryAfter = max(30, (int)($status['expires_at'] > 0 ? min(300, $status['expires_at'] - time()) : 120));
        $headers = array(
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Retry-After' => (string)$retryAfter,
            'X-Robots-Tag' => 'noindex, nofollow',
        );

        if ($wantsJson) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
            $body = json_encode(array(
                'ok' => false,
                'error' => 'maintenance',
                'message' => $message,
                'retry_after' => $retryAfter,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return array('status' => 503, 'headers' => $headers, 'body' => (string)$body);
        }

        $headers['Content-Type'] = 'text/html; charset=utf-8';
        return array(
            'status' => 503,
            'headers' => $headers,
            'body' => self::renderHtml($root, $message, $retryAfter, $status),
        );
    }

    public static function enforce(string $root, string $area = 'site'): void
    {
        if (PHP_SAPI === 'cli') return;
        $response = self::response($root, $area);
        if ($response === null) return;
        if (!headers_sent()) {
            http_response_code($response['status']);
            foreach ($response['headers'] as $name => $value) header($name . ': ' . $value);
        }
        echo $response['body'];
        exit;
    }

    /** @param array<string,mixed> $status */
    private static function renderHtml(string $root, string $message, int $retryAfter, array $status): string
    {
        $template = $root . '/templates/maintenance.php';
        if (!is_file($template)) {
            return '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Обслуживание</title>'
                . '<body><h1>Сайт временно на обслуживании</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
        }
        $maintenance = array(
            'message' => $message,
            'retry_after' => $retryAfter,
            'failed' => !empty($status['failed']),
            'started_at' => (int)($status['started_at'] ?? 0),
        );
        if (!class_exists('MDJUI') && is_file($root . '/lib/ui/MDJUI.php')) {
            require_once $root . '/lib/ui/MDJUI.php';
        }
        ob_start();
        require $template;
        return (string)ob_get_clean();
    }

    private static function requestPath(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if ($uri === '' && isset($_SERVER['SCRIPT_NAME'])) $uri = (string)$_SERVER['SCRIPT_NAME'];
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    private static function relativePath(string $path): string
    {
        $root = defined('ROOTHTML') ? '/' . trim((string)ROOTHTML, '/') : '';
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        if ($root !== '' && $root !== '/' && str_starts_with($path, $root . '/')) {
            $path = substr($path, strlen($root));
        }
        return '/' . ltrim($path, '/');
    }

    private static function isAssetRequest(string $path): bool
    {
        return (bool)preg_match('#^/(?:assets|favicon\.ico|robots\.txt)(?:/|$)#i', self::relativePath($path));
    }

    private static function isAdminRequest(string $path): bool
    {
        $relative = self::relativePath($path);
        return (bool)preg_match('#^/(?:admin\.php|panel(?:/|$))#i', $relative);
    }

    private static function isApiRequest(string $path): bool
    {
        return (bool)preg_match('#^/(?:api\.php|api)(?:/|$)#i', self::relativePath($path));
    }

    private static function isInstallRequest(string $path): bool
    {
        return InstallGate::isInstallRequest(self::relativePath($path));
    }

    private static function prefersJson(): bool
    {
        if (isset($_GET['part_load']) || isset($_GET['ajax_panel'])) return true;
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
        return isset($_SERVER['HTTP_ACCEPT']) && str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json');
    }
}
