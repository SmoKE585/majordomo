<?php

namespace MajorDoMo\Core\WebSockets;

use MajorDoMo\Core\{Config, Core, PropertyValues};
use MajorDoMo\Core\Api\{AccessPolicy, TokenStore};
use MajorDoMo\Core\Users\UserAccounts;
use Workerman\Worker;

final class Runtime
{
    public static function run(string $root): void
    {
        if (PHP_SAPI !== 'cli') throw new \RuntimeException('WebSocket-сервер запускается только из CLI.');
        chdir($root);
        require_once $root . '/config.php';
        if (Config::bool('DISABLE_WEBSOCKETS')) return;
        if (!class_exists(Worker::class)) throw new \RuntimeException('Установите зависимости: composer install --no-dev.');
        require_once $root . '/lib/loader.php';
        require_once $root . '/load_settings.php';
        $source = new PropertyValues(Core::database());
        $protocol = new Protocol(fn() => $source->pages(), fn($names) => $source->named($names),
            max(1, Config::int('WEBSOCKETS_MAX_SUBSCRIPTIONS')), max(1, Config::int('WEBSOCKETS_CACHE_MAX_ENTRIES')),
            max(1024, Config::int('WEBSOCKETS_CACHE_MAX_BYTES')), max(1024, Config::int('WEBSOCKETS_MAX_FRAME_BYTES')),
            max(1, Config::int('WEBSOCKETS_MAX_REQUESTS_PER_MINUTE')), static function (int $id): string {
                require_once DIR_MODULES . 'objects/objects.class.php';
                static $objects;
                $objects ??= new \objects();
                $result = $objects->processObject($id);
                return (string)($result['HTML'] ?? '');
            });
        // The legacy DB wrapper reconnects when MySQL restarts. Never retain a
        // prepared-statement store bound to the previous mysqli handle for days.
        $connection = static function (): \mysqli {
            if (Core::database()->one('SELECT 1 AS READY') === null) throw new \RuntimeException('Хранилище авторизации недоступно.');
            return $GLOBALS['db']->dbh;
        };
        $access = new Access(new AccessPolicy(Core::network(), Config::string('HOME_NETWORK'), Config::string('TRUSTED_PROXIES')),
            new Tickets($root), fn($token) => (new TokenStore($connection()))->verify($token),
            fn($id) => (new UserAccounts($connection()))->adminState($id), Config::string('WEBSOCKETS_ALLOWED_ORIGINS'));
        $queue = Core::websockets();
        $drain = static function (Protocol $protocol) use ($queue): void {
            $items = $queue->claim(max(1, min(500, Config::int('WEBSOCKETS_QUEUE_LIMIT', 500))));
            try {
                $rows = array();
                foreach ($items as $item) {
                    $name = (string)$item['PROPERTY'];
                    $value = $item['DATAVALUE'];
                    if (($item['POST_ACTION'] ?? 'PostProperty') === 'PostProperty') $rows[] = array('PROPERTY' => $name, 'VALUE' => $value);
                    elseif ($item['POST_ACTION'] === 'PostEvent') {
                        if (is_string($value)) { $decoded = json_decode($value, true); if (is_array($decoded)) $value = $decoded; }
                        $protocol->event($name, $value);
                    }
                }
                $protocol->publish($rows);
                foreach ($items as $item) $queue->acknowledge($item);
            } catch (\Throwable $error) {
                foreach ($items as $item) $queue->release($item);
                throw $error;
            }
        };
        $context = array();
        if (Config::bool('WEBSOCKETS_TLS')) $context['ssl'] = array('local_cert' => Config::string('WEBSOCKETS_TLS_PEM_FILE'), 'passphrase' => Config::string('WEBSOCKETS_TLS_PEM_PASSPHRASE'));
        $host = Config::string('WEBSOCKETS_HOST', '0.0.0.0');
        if (str_contains($host, ':') && !str_starts_with($host, '[')) $host = '[' . $host . ']';
        $worker = new Worker('websocket://' . $host . ':' . Config::int('WEBSOCKETS_PORT', 8001), $context);
        if (Config::bool('WEBSOCKETS_TLS')) $worker->transport = 'ssl';
        $worker->name = 'MajorDoMo WebSocket';
        $worker->count = 1;
        $server = new Server($protocol, $access, array('clients' => max(1, Config::int('WEBSOCKETS_MAX_CLIENTS')),
            'per_ip' => max(1, Config::int('WEBSOCKETS_MAX_CONNECTIONS_PER_IP')), 'frame' => max(1024, Config::int('WEBSOCKETS_MAX_FRAME_BYTES')),
            'timeout' => Config::int('WEBSOCKETS_CLIENT_TIMEOUT')), $drain, static function (int $clients): void {
                setGlobal('cycle_websocketsRun', time(), 1);
                if ((int)gg('WSClientsTotal') !== $clients) setGlobal('WSClientsTotal', $clients, 1);
            });
        $server->attach($worker);
        $directory = $root . '/cms/cycles';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new \RuntimeException('Не удалось создать каталог состояния WebSocket.');
        Worker::$pidFile = $directory . '/websocket.pid';
        Worker::$logFile = $directory . '/websocket.log';
        Worker::$command = 'start';
        Worker::runAll();
    }
}
