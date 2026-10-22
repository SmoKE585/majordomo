<?php

namespace MajorDoMo\Core\WebSockets;

use MajorDoMo\Core\Api\ApiException;
use Workerman\{Worker, Timer};
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

/**
 * Transport adapter. No application operation is dispatched from a client name.
 *
 * 2026-10-23 — отказ рукопожатия пишется в журнал с причиной (status, код,
 * origin, host, forwarded-host): иначе в браузере только «403», а в журнале пусто.
 */
final class Server
{
    private array $connections = array();

    public function __construct(private Protocol $protocol, private Access $access, private array $limits = array(),
        private ?\Closure $drain = null, private ?\Closure $heartbeat = null) {}

    public function attach(Worker $worker): void
    {
        $worker->onConnect = function (TcpConnection $connection): void {
            $connection->maxPackageSize = $this->limits['frame'] ?? 1048576;
            $connection->maxSendBufferSize = max(2097152, $connection->maxPackageSize * 2);
            $connection->websocketType = "\x81";
            $ip = $connection->getRemoteIp();
            $sameIp = count(array_filter($this->connections, static fn($entry) => !isset($entry['identity']) && $entry['peer'] === $ip));
            if (count($this->connections) >= ($this->limits['clients'] ?? 100) || $sameIp >= ($this->limits['per_ip'] ?? 100)) { $connection->destroy(); return; }
            $this->connections[$connection->id] = array('connection' => $connection, 'peer' => $ip, 'created' => time());
        };
        $worker->onWebSocketConnect = function (TcpConnection $connection, Request $request): void {
            if (!isset($this->connections[$connection->id])) return;
            $server = array();
            try {
                if ($request->method() !== 'GET' || !in_array($request->path(), array('/majordomo', '/ws'), true)) throw new ApiException(404, 'not_found', 'Неизвестный WebSocket endpoint.');
                $server = array('REMOTE_ADDR' => $connection->getRemoteIp(), 'HTTP_HOST' => $request->header('host', ''));
                foreach (array('origin', 'authorization', 'x-api-key', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'sec-fetch-site') as $header) {
                    if ($request->header($header) !== null) $server['HTTP_' . strtoupper(str_replace('-', '_', $header))] = $request->header($header);
                }
                $identity = $this->access->authorize($server, $request->get());
                $ip = $this->access->clientIp($server);
                $sameIp = count(array_filter($this->connections, static fn($entry) => isset($entry['identity']) && ($entry['ip'] ?? '') === $ip));
                if ($sameIp >= ($this->limits['per_ip'] ?? 100)) throw new ApiException(429, 'connection_limit', 'Превышен лимит подключений с одного IP.');
                $this->connections[$connection->id]['identity'] = $identity;
                $this->connections[$connection->id]['ip'] = $ip;
            } catch (ApiException $error) {
                $this->reject($error, $server);
                $body = json_encode(array('error' => $error->errorCode));
                $connection->close('HTTP/1.1 ' . $error->status . " Rejected\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body, true);
            } catch (\Throwable $error) {
                $this->log($error);
                $connection->close("HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\nContent-Length: 0\r\n\r\n", true);
            }
        };
        $worker->onWebSocketConnected = function (TcpConnection $connection, Request $request): void {
            if (!isset($this->connections[$connection->id]['identity'])) return;
            $this->protocol->connect((string)$connection->id,
                static function (string $message) use ($connection): void { $connection->send($message); },
                function (int $code, string $reason) use ($connection): void { $this->close($connection, $code, $reason); },
                $request->get('snapshot', '1') !== '0');
        };
        $worker->onMessage = function (TcpConnection $connection, mixed $message): void {
            if (!isset($this->connections[$connection->id]['identity'])) { $connection->destroy(); return; }
            try {
                if (!$this->access->valid($this->connections[$connection->id]['identity'])) { $this->close($connection, 1008, 'authorization_expired'); return; }
                if (!is_string($message)) { $this->close($connection, 1003, 'text_only'); return; }
                $this->protocol->receive((string)$connection->id, $message);
            } catch (\Throwable $error) { $this->log($error); $this->close($connection, 1011, 'service_unavailable'); }
        };
        $worker->onBufferFull = function (TcpConnection $connection): void { $connection->destroy(); };
        $worker->onClose = function (TcpConnection $connection): void {
            unset($this->connections[$connection->id]);
            $this->protocol->disconnect((string)$connection->id);
        };
        $worker->onWorkerStart = function (): void {
            Timer::add(0.1, function (): void {
                try {
                    if ($this->drain !== null) ($this->drain)($this->protocol);
                    $this->protocol->tick();
                } catch (\Throwable $error) {
                    $this->log($error);
                    foreach ($this->connections as $entry) $this->close($entry['connection'], 1011, 'service_unavailable');
                }
            });
            Timer::add(5, function (): void {
                foreach ($this->connections as $entry) {
                    $connection = $entry['connection'];
                    if (!isset($entry['identity'])) {
                        if ($entry['created'] <= time() - 10) $connection->destroy();
                        continue;
                    }
                    try { $valid = $this->access->valid($entry['identity']); }
                    catch (\Throwable $error) { $this->log($error); $valid = false; }
                    if (!$valid) { $this->close($connection, 1008, 'authorization_expired'); continue; }
                    $type = $connection->websocketType;
                    $connection->websocketType = "\x89";
                    $connection->send('');
                    $connection->websocketType = $type;
                }
                if ($this->heartbeat !== null) {
                    try { ($this->heartbeat)($this->protocol->count()); }
                    catch (\Throwable $error) { $this->log($error); Worker::stopAll(); }
                }
            });
        };
    }

    private function close(TcpConnection $connection, int $code, string $reason): void
    {
        $this->protocol->disconnect((string)$connection->id);
        unset($this->connections[$connection->id]['identity']);
        $connection->websocketType = "\x88";
        $connection->close(pack('n', $code) . $reason);
    }

    /**
     * Отказ рукопожатия с причиной. Без этого в браузере видно только «403»,
     * а в журнале пусто — именно так выглядел Origin, не совпавший из-за
     * Host от nginx.
     * 2026-10-23 — добавлено вместе с расширенной проверкой Origin.
     */
    private function reject(ApiException $error, array $server): void
    {
        $parts = array('WebSocket handshake rejected: ' . $error->status . ' ' . $error->errorCode);
        foreach (array('REMOTE_ADDR' => 'peer', 'HTTP_HOST' => 'host', 'HTTP_X_FORWARDED_HOST' => 'forwarded-host', 'HTTP_ORIGIN' => 'origin') as $key => $label) {
            $value = (string)($server[$key] ?? '');
            if ($value !== '') $parts[] = $label . '=' . self::clean($value);
        }
        $message = implode('; ', $parts);
        if (function_exists('DebMes')) DebMes($message, 'websockets', 'error');
        else error_log($message);
    }

    /** Значения заголовков не содержат секретов, но не должны ломать строку лога. */
    private static function clean(string $value): string
    {
        return mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, 200);
    }

    private function log(\Throwable $error): void
    {
        // Exceptions may include SQL or credentials. Never log a request/URL here.
        $message = 'WebSocket: ' . get_class($error);
        if (function_exists('DebMes')) DebMes($message, 'websockets', 'error');
        else error_log($message);
    }
}
