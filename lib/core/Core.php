<?php

namespace MajorDoMo\Core;

/**
 * The legacy loader owns the connection; new code receives explicit dependencies.
 * 2026-10-25 — очередь Redis отделена от прежней очереди при переходе на долговечный журнал SQL.
 * 2026-10-25 — общий сервис уведомлений модулей.
 * 2026-10-23 — общий сервис создания сущностей с обновлением метаданных.
 * Изменения: 2026-10-22 — добавлен сервис apiHooks() для реестра webhook.
 */
final class Core
{
    private static array $services = array();

    private static function service(string $name, callable $create): object
    {
        return self::$services[$name] ??= $create();
    }

    public static function code(): CodeExecutor { return self::service(__FUNCTION__, fn() => new CodeExecutor()); }
    public static function notifications(): ModuleNotifications { return self::service(__FUNCTION__, fn() => new ModuleNotifications(self::database())); }

    public static function users(): Users\UserAccounts { return self::service(__FUNCTION__, fn() => Users\UserAccounts::connect()); }
    public static function session(?string $root = null): Http\Session
    {
        $session = self::service(__FUNCTION__, fn() => new Http\Session('prj', $root));
        $GLOBALS['session'] = $session;
        return $session;
    }

    public static function database(): Database
    {
        return self::service(__FUNCTION__, function () { return new Database($GLOBALS['db']); });
    }

    public static function redis(): RedisConnection { return self::service(__FUNCTION__, fn() => new RedisConnection()); }
    public static function httpCache(): Http\HttpCache { return self::service(__FUNCTION__, fn() => new Http\HttpCache()); }
    public static function metadata(): MetadataCache { return self::service(__FUNCTION__, fn() => new MetadataCache(self::database(), self::redis())); }
    public static function cache(): Cache { return self::service(__FUNCTION__, fn() => new Cache(self::database(), self::redis(), self::metadata())); }
    public static function locks(): PropertyLock { return self::service(__FUNCTION__, fn() => new PropertyLock(self::database(), self::redis())); }
    public static function properties(): Properties { return self::service(__FUNCTION__, fn() => new Properties(self::database(), self::cache(), self::metadata(), self::locks(), self::websockets(), self::code(), self::errors(), self::audit())); }
    public static function objects(): Objects { return self::service(__FUNCTION__, fn() => new Objects(self::database(), self::metadata(), self::cache(), self::properties(), self::locks())); }
    public static function entities(): EntityCreator { return self::service(__FUNCTION__, fn() => new EntityCreator(self::database(), static function (): void { self::metadata()->invalidate(); })); }
    public static function bindings(): PropertyBindings { return self::service(__FUNCTION__, fn() => new PropertyBindings(self::database(), self::properties(), self::locks())); }
    public static function errors(): ErrorReporter { return self::service(__FUNCTION__, fn() => new ErrorReporter(self::cache())); }
    public static function audit(): PropertyAudit { return self::service(__FUNCTION__, fn() => new PropertyAudit(self::database(), self::errors())); }
    public static function methods(): Methods { return self::service(__FUNCTION__, fn() => new Methods(self::database(), self::metadata(), self::code(), self::audit())); }
    public static function coreLanes(): LaneEventQueue { return self::service(__FUNCTION__, fn() => new LaneEventQueue(self::redis(), 'core_runtime_durable', 300)); }
    public static function queue(): OperationsQueue { return self::service(__FUNCTION__, fn() => new OperationsQueue(self::database(), self::redis(), self::coreLanes())); }
    public static function websockets(): WebSocketQueue { return self::service(__FUNCTION__, fn() => new WebSocketQueue(self::database(), self::redis(), self::queue())); }
    public static function historyStorage(): HistoryStorage { return self::service(__FUNCTION__, fn() => new HistoryStorage(self::database())); }
    public static function history(): HistoryWriter { return self::service(__FUNCTION__, fn() => new HistoryWriter(self::database(), self::historyStorage())); }
    public static function migrations(): Migrations { return self::service(__FUNCTION__, fn() => new Migrations(self::database(), self::cache(), self::queue(), self::historyStorage(), self::websockets())); }
    public static function api(): ApiClient { return self::service(__FUNCTION__, fn() => new ApiClient(self::queue())); }
    public static function apiDispatcher(): Api\Dispatcher { return self::service(__FUNCTION__, fn() => new Api\Dispatcher(self::database())); }
    public static function apiTokens(): Api\TokenStore { return self::service(__FUNCTION__, fn() => new Api\TokenStore($GLOBALS['db']->dbh)); }
    public static function apiHooks(): Api\HookStore { return self::service(__FUNCTION__, fn() => new Api\HookStore($GLOBALS['db']->dbh, Config::root())); }
    public static function processes(): ProcessRunner { return self::service(__FUNCTION__, fn() => new ProcessRunner()); }
    public static function scheduler(): Scheduler { return self::service(__FUNCTION__, fn() => new Scheduler(self::database(), self::processes(), self::audit())); }
    public static function worker(): OperationWorker { return self::service(__FUNCTION__, fn() => new OperationWorker(self::database(), self::redis(), self::queue(), self::processes(), self::errors(), self::audit())); }
    public static function network(): NetworkAccess { return self::service(__FUNCTION__, fn() => new NetworkAccess()); }
}
