<?php

namespace MajorDoMo\Core\WebSockets;

use MajorDoMo\Core\Api\{AccessPolicy, ApiException};

final class Access
{
    public function __construct(private AccessPolicy $network, private Tickets $tickets, private \Closure $verifyToken,
        private \Closure $adminState) {}

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
        $host = parse_url('http://' . (string)($server['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if (!is_array($origin) || !in_array($origin['scheme'] ?? '', array('http', 'https'), true) || isset($origin['user']) || isset($origin['pass'])
            || isset($origin['query']) || isset($origin['fragment']) || !empty($origin['path'])
            || strcasecmp((string)($origin['host'] ?? ''), (string)$host) !== 0) {
            throw new ApiException(403, 'cross_origin_denied', 'Origin подключения не разрешён.');
        }
    }
}
