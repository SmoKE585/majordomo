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
 * 2026-10-25 — унаследованный NO_LINKED помечается отдельно от явного запрета записи.
 * 2026-10-25 — исключения NO_LINKED привязаны к записанному свойству и
 *              сохраняются через методы и сценарии независимо от модуля.
 * 2026-10-22 — добавлена точка доставки (модуль + свойство). Исключение модуля
 *              из уведомлений больше не распространяется на всю цепочку:
 *              привязки свойств второго и следующих уровней не теряются.
 */
final class PropertyChain
{
    public const MAX_LENGTH = 32;
    public const EXCLUSION_SCOPE = '@mdj:scope';

    private static array $chain = array();
    private static string $source = '';
    private static string $point = '';
    /** @var array<string,string> Модуль → свойство, на котором исключили его эхо. */
    private static array $exclusions = array();

    /** @param string[] $chain */
    public static function enter(array $chain, string $source = '', string $point = '', array $exclusions = array()): void
    {
        self::$chain = self::clean($chain);
        self::$source = trim($source);
        self::$point = trim($point);
        self::$exclusions = array();
        foreach ($exclusions as $module => $token) {
            if (is_string($module) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $module)
                && is_string($token) && str_starts_with($token, 'property:')) {
                self::$exclusions[$module] = $token;
            }
        }
    }

    public static function leave(): void
    {
        self::$chain = array();
        self::$source = '';
        self::$point = '';
        self::$exclusions = array();
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

    /** Свойство уведомления; у входящего события модуля точки свойства нет. */
    public static function point(): string
    {
        return self::$point;
    }

    /**
     * Текущая доставка в виде, пригодном для параметров очереди.
     *
     * @return array{module:string,point:string,exclusions?:array<string,string>}
     */
    public static function delivery(): array
    {
        $delivery = array('module' => self::$source, 'point' => self::$point);
        if (self::$exclusions) $delivery['exclusions'] = self::$exclusions;
        return $delivery;
    }

    /** @return array<string,string> Исключения, унаследованные текущим обработчиком. */
    public static function exclusions(): array
    {
        return self::$exclusions;
    }

    /** Метка имеет недопустимое для модуля имя и не может совпасть с подписчиком. */
    public static function scopedExclusions(array $excluded, array $origins): array
    {
        unset($excluded[self::EXCLUSION_SCOPE]);
        $scope = array();
        foreach ($origins as $module => $point) {
            if (isset($excluded[$module]) && is_string($point)) $scope[$module] = $point;
        }
        if ($scope) $excluded[self::EXCLUSION_SCOPE] = $scope;
        return $excluded;
    }

    /** Привязывает NO_LINKED нового ONCHANGE к свойству, которое его вызвало. */
    public static function deliveryForProperty(string $token, array $excluded): array
    {
        $delivery = self::delivery();
        unset($delivery['exclusions']);
        foreach (array_keys($excluded) as $module) {
            if (is_string($module) && $module !== self::EXCLUSION_SCOPE && isset($excluded[$module])) {
                $delivery['exclusions'][$module] = $token;
            }
        }
        return $delivery;
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
