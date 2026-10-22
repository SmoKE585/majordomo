<?php

namespace MajorDoMo\Core\WebSockets;

use MajorDoMo\Core\{Config, Core, PropertyValues};
use MajorDoMo\Core\Api\{AccessPolicy, TokenStore};
use MajorDoMo\Core\Users\UserAccounts;
use Workerman\Worker;

final class Runtime
{
    public const PORT = 8001;

    public static function run(string $root): void
    {
        if (PHP_SAPI !== 'cli') throw new \RuntimeException('WebSocket-сервер запускается только из CLI.');
        chdir($root);
        require_once $root . '/config.php';
        if (!class_exists(Worker::class)) throw new \RuntimeException('Установите зависимости: composer install --no-dev.');
        require_once $root . '/lib/loader.php';
        require_once $root . '/load_settings.php';
        $source = new PropertyValues(Core::database());
        $protocol = new Protocol(fn() => $source->pages(), fn($names) => $source->named($names),
            renderObject: static function (int $id): string {
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
        // Only the local reverse proxy reaches this loopback listener.
        $trustedProxies = '127.0.0.1/32,::1/128,' . Config::string('TRUSTED_PROXIES');
        $access = new Access(new AccessPolicy(Core::network(), Config::string('HOME_NETWORK'), $trustedProxies),
            new Tickets($root), fn($token) => (new TokenStore($connection()))->verify($token),
            fn($id) => (new UserAccounts($connection()))->adminState($id));
        $queue = Core::websockets();
        $drain = static function (Protocol $protocol) use ($queue): void {
            $items = $queue->claim(500);
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
        $worker = new Worker('websocket://127.0.0.1:' . self::PORT);
        $worker->name = 'MajorDoMo WebSocket';
        $worker->count = 1;
        $server = new Server($protocol, $access, array(), $drain, static function (int $clients): void {
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
