<?php

namespace MajorDoMo\Core\Api;

use MajorDoMo\Core\Http\Session;
use MajorDoMo\Core\WebSockets\SiteDomain;

/**
 * Браузерный доступ с собственного сайта и поддоменов, заданных BASE_URL.
 * Изменения: 2026-10-23 — CORS без cookie и без расширения доступа по HOME_NETWORK.
 */
final class BrowserAccess
{
    public function __construct(private string $baseUrl = '') {}

    /** Возвращает true только для разрешённого другого origin. */
    public function check(array $server): bool
    {
        if (!isset($server['HTTP_ORIGIN'])) {
            if (strtolower((string)($server['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') self::denied();
            return false;
        }
        $raw = (string)$server['HTTP_ORIGIN'];
        $origin = parse_url($raw);
        if (preg_match('/[\x00-\x20\x7f]/', $raw) || !is_array($origin) || !in_array($origin['scheme'] ?? '', array('http', 'https'), true)
            || isset($origin['user']) || isset($origin['pass']) || isset($origin['path']) || isset($origin['query']) || isset($origin['fragment'])) self::denied();
        $host = SiteDomain::normalize((string)($origin['host'] ?? ''));
        if ($host === '') self::denied();
        $scheme = Session::isSecure($server) ? 'https' : 'http';
        $request = parse_url($scheme . '://' . (string)($server['HTTP_HOST'] ?? ''));
        $port = (int)($origin['port'] ?? ($origin['scheme'] === 'https' ? 443 : 80));
        if (is_array($request) && $host === SiteDomain::normalize((string)($request['host'] ?? ''))
            && $origin['scheme'] === $scheme && $port === (int)($request['port'] ?? ($scheme === 'https' ? 443 : 80))) return false;
        $base = parse_url($this->baseUrl);
        $publicHost = is_array($base) ? SiteDomain::normalize((string)($base['host'] ?? '')) : '';
        $domain = SiteDomain::registered($publicHost);
        if ($publicHost === '' || !in_array($base['scheme'] ?? '', array('http', 'https'), true) || $origin['scheme'] !== $base['scheme']
            || ($host !== $publicHost && ($domain === null || ($host !== $domain && !str_ends_with($host, '.' . $domain))))) self::denied();
        return true;
    }

    public function headers(array $server): array
    {
        if (!$this->check($server)) return array();
        return array('Access-Control-Allow-Origin' => $server['HTTP_ORIGIN'], 'Vary' => 'Origin', 'Access-Control-Expose-Headers' => 'X-Request-ID, Retry-After, WWW-Authenticate');
    }

    private static function denied(): never
    {
        throw new ApiException(403, 'cross_origin_denied', 'Origin обращения к API не разрешён.');
    }
}
