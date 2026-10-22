<?php

namespace MajorDoMo\Core;

/** The legacy loader owns the connection; new code receives explicit dependencies. */
final class Core
{
    private static array $services = array();

    private static function service(string $name, callable $create): object
    {
        return self::$services[$name] ??= $create();
    }

    public static function code(): CodeExecutor { return self::service(__FUNCTION__, fn() => new CodeExecutor()); }

    public static function database(): Database
    {
        return self::service(__FUNCTION__, function () { return new Database($GLOBALS['db']); });
    }

    public static function redis(): RedisConnection { return self::service(__FUNCTION__, fn() => new RedisConnection()); }
    public static function metadata(): MetadataCache { return self::service(__FUNCTION__, fn() => new MetadataCache(self::database(), self::redis())); }
    public static function cache(): Cache { return self::service(__FUNCTION__, fn() => new Cache(self::database(), self::redis(), self::metadata())); }
    public static function locks(): PropertyLock { return self::service(__FUNCTION__, fn() => new PropertyLock(self::database(), self::redis())); }
    public static function properties(): Properties { return self::service(__FUNCTION__, fn() => new Properties(self::database(), self::cache(), self::metadata(), self::locks(), self::websockets(), self::code(), self::errors())); }
    public static function objects(): Objects { return self::service(__FUNCTION__, fn() => new Objects(self::database(), self::metadata(), self::cache(), self::properties(), self::locks())); }
    public static function bindings(): PropertyBindings { return self::service(__FUNCTION__, fn() => new PropertyBindings(self::database(), self::properties(), self::locks())); }
    public static function errors(): ErrorReporter { return self::service(__FUNCTION__, fn() => new ErrorReporter(self::cache())); }
    public static function methods(): Methods { return self::service(__FUNCTION__, fn() => new Methods(self::database(), self::metadata(), self::code())); }
    public static function queue(): OperationsQueue { return self::service(__FUNCTION__, fn() => new OperationsQueue(self::database(), self::redis())); }
    public static function websockets(): WebSocketQueue { return self::service(__FUNCTION__, fn() => new WebSocketQueue(self::database(), self::redis(), self::queue())); }
    public static function historyStorage(): HistoryStorage { return self::service(__FUNCTION__, fn() => new HistoryStorage(self::database())); }
    public static function history(): HistoryWriter { return self::service(__FUNCTION__, fn() => new HistoryWriter(self::database(), self::historyStorage())); }
    public static function migrations(): Migrations { return self::service(__FUNCTION__, fn() => new Migrations(self::database(), self::cache(), self::queue(), self::historyStorage(), self::websockets())); }
    public static function api(): ApiClient { return self::service(__FUNCTION__, fn() => new ApiClient(self::queue())); }
    public static function processes(): ProcessRunner { return self::service(__FUNCTION__, fn() => new ProcessRunner()); }
    public static function scheduler(): Scheduler { return self::service(__FUNCTION__, fn() => new Scheduler(self::database(), self::processes())); }
    public static function worker(): OperationWorker { return self::service(__FUNCTION__, fn() => new OperationWorker(self::database(), self::redis(), self::queue(), self::processes())); }
    public static function network(): NetworkAccess { return self::service(__FUNCTION__, fn() => new NetworkAccess()); }
}
