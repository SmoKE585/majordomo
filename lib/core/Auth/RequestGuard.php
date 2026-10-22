<?php
namespace MajorDoMo\Core\Auth;
use MajorDoMo\Core\Http\Session;

final class RequestGuard
{
    public static function sameOrigin(array $server): bool
    {
        $host = strtolower((string)($server['HTTP_HOST'] ?? ''));
        $scheme = Session::isSecure($server) ? 'https' : 'http';
        foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $key) {
            if (!isset($server[$key])) continue;
            $url = parse_url((string)$server[$key]);
            if (!is_array($url) || isset($url['user']) || isset($url['pass'])) return false;
            $source = strtolower((string)($url['host'] ?? '')) . (isset($url['port']) ? ':' . $url['port'] : '');
            return $host !== '' && $source === $host && ($url['scheme'] ?? '') === $scheme;
        }
        return false;
    }

    public static function allowed(Session $session, array $server, array $post, bool $api = false): bool
    {
        $site = strtolower((string)($server['HTTP_SEC_FETCH_SITE'] ?? ''));
        if ($site !== '' && !in_array($site, array('same-origin', 'none'), true)) return false;
        if (isset($server['HTTP_ORIGIN']) && !self::sameOrigin($server)) return false;
        $token = $server['HTTP_X_CSRF_TOKEN'] ?? $post['_md_csrf'] ?? null;
        if ($token !== null) return $session->checkCsrf($token);
        $safeMethod = in_array(strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET')), array('GET', 'HEAD', 'OPTIONS'), true);
        if ($api) return $safeMethod;
        // Address-bar/bookmark navigation is user initiated, unlike cross-site links.
        if ($site === 'none' && $safeMethod) return true;
        // Legacy modules still mutate through GET and submit forms in iframes.
        // They need an explicit same-origin browser context even for GET actions.
        if ($site === 'same-origin' || self::sameOrigin($server)) return true;
        $path = (string)parse_url((string)($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $root = Session::cookiePath();
        return $safeMethod && empty($server['QUERY_STRING']) && in_array($path, array($root, $root . 'index.php', $root . 'admin.php', $root . 'panel', $root . 'panel/'), true);
    }
}
