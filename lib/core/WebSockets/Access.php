<?php

namespace MajorDoMo\Core\WebSockets;

use MajorDoMo\Core\Api\{AccessPolicy, ApiException};

/**
 * Доступ к WebSocket: Origin, API-токен или одноразовый билет администратора.
 *
 * 2026-10-23 — Origin сравнивается не только с Host самого handshake, но и с
 * X-Forwarded-Host от доверенного прокси и с публичным адресом проекта. Без
 * этого nginx с настройкой по умолчанию (Host = адрес апстрима) отдавал 403
 * на каждое подключение, хотя билет выдавался.
 */
final class Access
{
    public function __construct(private AccessPolicy $network, private Tickets $tickets, private \Closure $verifyToken,
        private \Closure $adminState, private string $publicHost = '') {}

    public function authorize(array $server, array $query): array
    {
        $this->checkOrigin($server);
        if (isset($query['ticket'])) {
            $ticket = is_string($query['ticket']) ? $this->tickets->consume($query['ticket']) : null;
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
        return match ($identity['type']) {
            'local' => true,
            'token' => (bool)($this->verifyToken)($identity['token']),
            'session' => $this->tickets->valid($identity['identity'], $this->adminState),
            default => false,
        };
    }

    public function clientIp(array $server): string { return $this->network->clientIp($server); }

    private function checkOrigin(array $server): void
    {
        if (strtolower((string)($server['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') throw new ApiException(403, 'cross_origin_denied', 'Межсайтовые подключения запрещены.');
        if (!isset($server['HTTP_ORIGIN'])) return; // Native integrations do not send Origin.
        $raw = (string)$server['HTTP_ORIGIN'];
        $origin = parse_url($raw);
        if (!is_array($origin) || !in_array($origin['scheme'] ?? '', array('http', 'https'), true) || isset($origin['user']) || isset($origin['pass'])
            || isset($origin['query']) || isset($origin['fragment']) || !empty($origin['path'])) {
            throw new ApiException(403, 'cross_origin_denied', 'Origin подключения не разрешён.');
        }
        $originHost = self::host((string)($origin['host'] ?? ''));
        if ($originHost === '' || !in_array($originHost, $this->allowedHosts($server), true)) {
            throw new ApiException(403, 'cross_origin_denied', 'Origin подключения не разрешён.');
        }
    }

    /**
     * Хосты, для которых Origin считается своим:
     *  1) Host самого handshake;
     *  2) X-Forwarded-Host от доверенного прокси — nginx может оставить в Host
     *     адрес апстрима (127.0.0.1:8001);
     *  3) публичный адрес проекта (BASE_URL) — он не зависит от того, что
     *     именно передал прокси.
     *
     * @return string[]
     */
    private function allowedHosts(array $server): array
    {
        $forwarded = $this->network->viaTrustedProxy($server) ? (string)($server['HTTP_X_FORWARDED_HOST'] ?? '') : '';
        $hosts = array();
        foreach (array((string)($server['HTTP_HOST'] ?? ''), $forwarded, $this->publicHost) as $candidate) {
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
        if (str_contains($value, '://')) return strtolower((string)parse_url($value, PHP_URL_HOST));
        return strtolower((string)preg_replace('/:\d+$/D', '', $value));
    }
}
