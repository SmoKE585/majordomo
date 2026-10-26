<?php

namespace MajorDoMo\Core\WebSockets;

use MajorDoMo\Core\Api\{AccessPolicy, ApiException};

/**
 * Доступ к WebSocket: Origin, API-токен или одноразовый билет администратора.
 * 2026-10-26 — localhost/127.0.0.1/LAN-IP считаются одним локальным стендом:
 *              панель по 192.168.1.2 больше не получает 403 при BASE_URL=localhost.
 * 2026-10-23 — истечение пользовательского токена закрывает соединение штатно.
 *
 * 2026-10-23 — домен и поддомены разрешаются по BASE_URL; Host запроса больше
 * не расширяет доступ при заданном публичном адресе.
 *
 * 2026-10-23 — Origin сравнивается не только с Host самого handshake, но и с
 * X-Forwarded-Host от доверенного прокси и с публичным адресом проекта. Без
 * этого nginx с настройкой по умолчанию (Host = адрес апстрима) отдавал 403
 * на каждое подключение, хотя билет выдавался.
 */
final class Access
{
    private ?string $publicDomain;

    public function __construct(private AccessPolicy $network, private Tickets $tickets, private \Closure $verifyToken,
        private \Closure $adminState, private string $publicHost = '')
    {
        $this->publicHost = self::host($publicHost);
        if ($publicHost !== '' && $this->publicHost === '') throw new \InvalidArgumentException('Некорректный публичный адрес WebSocket.');
        $this->publicDomain = SiteDomain::registered($this->publicHost);
    }

    public function authorize(array $server, array $query): array
    {
        // Без публичного адреса старые установки проверяют точный хост билета:
        // nginx может не передать исходный Host в WS-соединение.
        $ticketProvided = isset($query['ticket']);
        $ticket = $ticketProvided && is_string($query['ticket']) ? $this->tickets->consume($query['ticket']) : null;
        $this->checkOrigin($server, (string)($ticket['host'] ?? ''));
        if ($ticketProvided) {
            if ($ticket === null || !$this->tickets->valid($ticket, $this->adminState)) throw new ApiException(401, 'invalid_ticket', 'Билет WebSocket недействителен.');
            return array('type' => 'session', 'identity' => $ticket);
        }
        $token = $server['HTTP_X_API_KEY'] ?? $query['token'] ?? '';
        if (preg_match('/^Bearer\s+(\S+)$/iD', (string)($server['HTTP_AUTHORIZATION'] ?? ''), $match)) $token = $match[1];
        if (!is_string($token)) throw new ApiException(401, 'invalid_token', 'Недопустимый API-токен.');
        if ($token !== '') {
            if (!(($this->verifyToken)($token))) throw new ApiException(401, 'invalid_token', 'API-токен отозван, истёк или недействителен.');
            return array('type' => 'token', 'token' => $token);
        }
        if ($this->network->isLocal($server)) return array('type' => 'local');
        throw new ApiException(401, 'token_required', 'Для внешнего доступа требуется API-токен или сессия администратора.');
    }

    public function valid(array $identity): bool
    {
        try {
            return match ($identity['type']) {
                'local' => true,
                'token' => (bool)($this->verifyToken)($identity['token']),
                'session' => $this->tickets->valid($identity['identity'], $this->adminState),
                default => false,
            };
        } catch (ApiException $error) {
            if ($error->status !== 401) throw $error;
            return false;
        }
    }

    public function clientIp(array $server): string { return $this->network->clientIp($server); }

    private function checkOrigin(array $server, string $ticketHost = ''): void
    {
        if (!isset($server['HTTP_ORIGIN'])) {
            if (strtolower((string)($server['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') throw new ApiException(403, 'cross_origin_denied', 'Межсайтовые подключения запрещены.');
            return; // Небраузерные интеграции проходят обычную проверку токена или сети.
        }
        $raw = (string)$server['HTTP_ORIGIN'];
        $origin = parse_url($raw);
        if (!is_array($origin) || !in_array($origin['scheme'] ?? '', array('http', 'https'), true) || isset($origin['user']) || isset($origin['pass'])
            || isset($origin['query']) || isset($origin['fragment']) || !empty($origin['path'])) {
            throw new ApiException(403, 'cross_origin_denied', 'Origin подключения не разрешён.');
        }
        $originHost = SiteDomain::normalize((string)($origin['host'] ?? ''));
        // Для локального/приватного стенда localhost, 127.0.0.1 и LAN-IP
        // взаимозаменяемы: панель могла быть открыта по 192.168.1.2, а
        // BASE_URL остался localhost. Публичные домены проверяются строго.
        $localAlias = self::isLocalHost($this->publicHost) && self::isLocalHost($originHost);
        // Только настройка владельца задаёт границу сайта. Ни Host, ни билет,
        // ни заголовок same-site не могут разрешить посторонний домен.
        $allowed = $localAlias || ($this->publicHost !== ''
            ? $originHost === $this->publicHost || ($this->publicDomain !== null
                && ($originHost === $this->publicDomain || str_ends_with($originHost, '.' . $this->publicDomain)))
            : in_array($originHost, $this->allowedHosts($server, $ticketHost), true));
        if ($originHost === '' || !$allowed) {
            throw new ApiException(403, 'cross_origin_denied', 'Origin подключения не разрешён.');
        }
    }

    /**
     * Локальный или частный адрес стенда: localhost, loopback, LAN и ULA.
     * Такие адреса не являются публичным доменом владельца, поэтому их
     * взаимная замена не расширяет доступ из интернета.
     */
    private static function isLocalHost(string $host): bool
    {
        if (in_array($host, array('localhost', '127.0.0.1', '::1'), true)) return true;
        if (filter_var($host, FILTER_VALIDATE_IP) === false) return false;
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Совместимость без BASE_URL: разрешены только точные имена хостов:
     *  1) Host самого handshake;
     *  2) X-Forwarded-Host от доверенного прокси — nginx может оставить в Host
     *     адрес апстрима (127.0.0.1:8001);
     *  3) host из одноразового билета: его записал веб-процесс, который видел
     *     настоящий Host браузера.
     *
     * @return string[]
     */
    private function allowedHosts(array $server, string $ticketHost = ''): array
    {
        $forwarded = $this->network->viaTrustedProxy($server) ? (string)($server['HTTP_X_FORWARDED_HOST'] ?? '') : '';
        $hosts = array();
        foreach (array((string)($server['HTTP_HOST'] ?? ''), $forwarded, $ticketHost) as $candidate) {
            $host = self::host($candidate);
            if ($host !== '' && !in_array($host, $hosts, true)) $hosts[] = $host;
        }
        return $hosts;
    }

    /** Приводит Host, host:port или URL к имени хоста в нижнем регистре. */
    private static function host(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        // X-Forwarded-Host допускает цепочку из нескольких значений.
        $value = trim((string)strstr($value . ',', ',', true));
        $url = parse_url(str_contains($value, '://') ? $value : 'http://' . $value);
        return is_array($url) ? SiteDomain::normalize((string)($url['host'] ?? '')) : '';
    }
}
