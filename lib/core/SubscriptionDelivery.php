<?php

namespace MajorDoMo\Core;

/**
 * Строгая доставка подписок в воркере с сохранением старых публичных сигнатур.
 * 2026-10-25 — проглоченное исключение подписчика больше не подтверждается как успех очереди.
 */
final class SubscriptionDelivery
{
    private static int $depth = 0;
    public static function isStrict(): bool { return self::$depth > 0; }
    public static function run(string $event, array $details): mixed
    {
        self::$depth++;
        try { return processSubscriptions($event, $details); }
        finally { self::$depth--; }
    }
}
