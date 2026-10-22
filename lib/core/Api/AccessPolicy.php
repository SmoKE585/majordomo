<?php

namespace MajorDoMo\Core\Api;

use MajorDoMo\Core\NetworkAccess;

final class AccessPolicy
{
    public const LOCAL_NETWORKS = '127.0.0.0/8,::1/128,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,169.254.0.0/16,fc00::/7,fe80::/10';

    public function __construct(private NetworkAccess $network, private string $homeNetworks = '', private string $trustedProxies = '') {}

    /** A proxy address alone never proves that the original caller is local. */
    public function clientIp(array $server): string
    {
        $peer = self::normalize((string)($server['REMOTE_ADDR'] ?? ''));
        $forwarded = (string)($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if (!$this->network->inNetworks($peer, $this->trustedProxies)) return $forwarded === '' ? $peer : '';
        if ($forwarded === '' || strlen($forwarded) > 4096) return '';
        $chain = array_reverse(explode(',', $forwarded));
        foreach ($chain as $candidate) {
            $candidate = self::normalize(trim($candidate));
            if ($candidate === '') return '';
            if (!$this->network->inNetworks($candidate, $this->trustedProxies)) return $candidate;
        }
        return '';
    }

    public function isLocal(array $server): bool
    {
        $networks = $this->homeNetworks !== '' ? '127.0.0.0/8,::1/128,' . $this->homeNetworks : self::LOCAL_NETWORKS;
        return $this->network->inNetworks($this->clientIp($server), $networks);
    }

    public function authorize(array $server, callable $verify, ?callable $sessionVerifier = null): string
    {
        // Browser navigation from another site must not invoke a legacy GET action.
        if (in_array(strtolower((string)($server['HTTP_SEC_FETCH_SITE'] ?? '')), array('cross-site'), true)) {
            throw new ApiException(403, 'cross_origin_denied', 'Межсайтовые обращения к API запрещены.');
        }
        if (isset($server['HTTP_ORIGIN'])) {
            $origin = parse_url((string)$server['HTTP_ORIGIN']);
            $host = strtolower((string)($server['HTTP_HOST'] ?? ''));
            $originHost = is_array($origin) ? strtolower((string)($origin['host'] ?? '')) . (isset($origin['port']) ? ':' . $origin['port'] : '') : '';
            if ($host === '' || $originHost !== $host || !in_array($origin['scheme'] ?? '', array('http', 'https'), true)) throw new ApiException(403, 'cross_origin_denied', 'Межсайтовые обращения к API запрещены.');
        }
        $token = (string)($server['HTTP_X_API_KEY'] ?? '');
        $authorization = (string)($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/iD', $authorization, $match)) $token = $match[1];
        if ($token !== '') {
            if (!$verify($token)) throw new ApiException(401, 'invalid_token', 'Токен недействителен, отозван или истёк.', array(), array('WWW-Authenticate' => 'Bearer'));
            return 'token';
        }
        if ($sessionVerifier !== null && $sessionVerifier()) return 'session';
        if ($this->isLocal($server)) return 'local';
        throw new ApiException(401, 'token_required', 'Для внешнего доступа требуется API-токен.', array(), array('WWW-Authenticate' => 'Bearer'));
    }

    private static function normalize(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return '';
        if (str_starts_with(strtolower($ip), '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return substr($ip, 7);
        return $ip;
    }
}
