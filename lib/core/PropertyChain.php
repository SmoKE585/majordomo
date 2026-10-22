<?php

namespace MajorDoMo\Core;

/**
 * Per-delivery change chain.
 *
 * The worker executes one module/method operation in its own process. Every
 * property written from that operation carries the chain of properties already
 * visited by the delivery. A repeated property means an automation loop
 * (A -> script -> B -> script -> A): the value is still stored, but no further
 * notifications/ONCHANGE are queued for it.
 *
 * Кроме цепочки доставка помнит модуль и свойство, о котором этот модуль был
 * уведомлён. Это нужно, чтобы гасить только собственное эхо: модуль не должен
 * получать уведомление о свойстве, которое сам же записал, но обязан получать
 * уведомления о других своих свойствах — даже если они записаны в том же
 * процессе, глубже по цепочке.
 *
 * Изменения:
 * 2026-10-22 — добавлена точка доставки (модуль + свойство). Исключение модуля
 *              из уведомлений больше не распространяется на всю цепочку:
 *              привязки свойств второго и следующих уровней не теряются.
 */
final class PropertyChain
{
    public const MAX_LENGTH = 32;

    private static array $chain = array();
    private static string $source = '';
    private static string $point = '';

    /** @param string[] $chain */
    public static function enter(array $chain, string $source = '', string $point = ''): void
    {
        self::$chain = self::clean($chain);
        self::$source = trim($source);
        self::$point = trim($point);
    }

    public static function leave(): void
    {
        self::$chain = array();
        self::$source = '';
        self::$point = '';
    }

    /** @return string[] */
    public static function current(): array
    {
        return self::$chain;
    }

    public static function source(): string
    {
        return self::$source;
    }

    /** Свойство, о котором уведомили модуль-источник ('' — источник не модуль). */
    public static function point(): string
    {
        return self::$point;
    }

    /**
     * Текущая доставка в виде, пригодном для параметров очереди.
     *
     * @return array{module:string,point:string}
     */
    public static function delivery(): array
    {
        return array('module' => self::$source, 'point' => self::$point);
    }

    public static function token(string $object, string $property): string
    {
        return 'property:' . $object . '.' . $property;
    }

    public static function contains(string $token): bool
    {
        return in_array($token, self::$chain, true);
    }

    /**
     * @param string[] $chain
     * @return string[]
     */
    public static function append(array $chain, string $token): array
    {
        $chain = self::clean($chain);
        if (!in_array($token, $chain, true)) $chain[] = $token;
        return array_slice($chain, 0, self::MAX_LENGTH);
    }

    /** @param array<int,mixed> $chain */
    private static function clean(array $chain): array
    {
        $result = array();
        foreach ($chain as $token) {
            if (!is_string($token) || $token === '' || in_array($token, $result, true)) continue;
            $result[] = $token;
            if (count($result) >= self::MAX_LENGTH) break;
        }
        return $result;
    }
}
