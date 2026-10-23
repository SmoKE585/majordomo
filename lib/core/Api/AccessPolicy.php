<?php

namespace MajorDoMo\Core\Api;

use MajorDoMo\Core\NetworkAccess;

/**
 * Проверка сетевого доступа к API.
 * Изменения: 2026-10-23 — поддомены BASE_URL принимаются только с явным токеном.
 * Изменения: 2026-10-22 — loopback-прокси доверенный по умолчанию.
 */
final class AccessPolicy
{
    public const LOCAL_NETWORKS = '127.0.0.0/8,::1/128,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,169.254.0.0/16,fc00::/7,fe80::/10';

    public function __construct(private NetworkAccess $network, private string $homeNetworks = '', private string $trustedProxies = '', private string $baseUrl = '')
    {
        // Локальный nginx всегда ходит с loopback, независимо от .env.
        $this->trustedProxies = NetworkAccess::trustedProxies($this->trustedProxies);
    }

    /** A proxy address alone never proves that the original caller is local. */
    public function clientIp(array $server): string
    {
        $peer = self::normalize((string)($server['REMOTE_ADDR'] ?? ''));
        $forwarded = (string)($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if (!$this->network->inNetworks($peer, $this->trustedProxies)) return $forwarded === '' ? $peer : '';
        // Прямой loopback-запрос (CLI-сервер, локальная проверка) не имеет XFF,
        // но сам по себе является локальным. Внешний доверенный прокси без XFF
        // по-прежнему неоднозначен и не получает локальность.
        if ($forwarded === '') return $this->network->inNetworks($peer, NetworkAccess::LOOPBACK_PROXIES) ? $peer : '';
        if (strlen($forwarded) > 4096) return '';
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

    /**
     * Запрос пришёл от доверенного прокси (обычно loopback nginx), а не от
     * клиента напрямую. Только такому запросу доверяем X-Forwarded-*.
     * 2026-10-23 — нужно проверке Origin у WebSocket: nginx может не
     * передавать исходный Host и оставлять адрес апстрима.
     */
    public function viaTrustedProxy(array $server): bool
    {
        $peer = self::normalize((string)($server['REMOTE_ADDR'] ?? ''));
        return $peer !== '' && $this->network->inNetworks($peer, $this->trustedProxies);
    }

    public function authorize(array $server, callable $verify, ?callable $sessionVerifier = null): string
    {
        $crossOrigin = (new BrowserAccess($this->baseUrl))->check($server);
        $token = (string)($server['HTTP_X_API_KEY'] ?? '');
        $authorization = (string)($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/iD', $authorization, $match)) $token = $match[1];
        if ($token !== '') {
            if (!$verify($token)) throw new ApiException(401, 'invalid_token', 'Токен недействителен, отозван или истёк.', array(), array('WWW-Authenticate' => 'Bearer'));
            return 'token';
        }
        // Поддомен не получает cookie-доступ или обход по локальной сети.
        if ($crossOrigin) throw new ApiException(401, 'token_required', 'Для интерфейса на другом origin требуется Bearer-токен.', array(), array('WWW-Authenticate' => 'Bearer'));
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
