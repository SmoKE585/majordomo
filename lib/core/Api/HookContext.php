<?php

namespace MajorDoMo\Core\Api;

/**
 * Разрешение на исполнение проверенного публичного webhook в текущем процессе.
 *
 * Флаг выставляется только после сверки секрета из /api/hooks/<secret> с БД.
 * Он не выводится из query-параметра, заголовка или одного совпадения URI и
 * сбрасывается после возврата обработчика. Легаси-загрузчик вызывает AuthGate;
 * AuthGate видит этот флаг и пропускает штатный runtime MajorDoMo, не открывая
 * админку и общий API.
 *
 * Изменения: 2026-10-22 — добавлен для отдельной ветки публичных webhook.
 */
final class HookContext
{
    private static bool $active = false;
    private static ?array $hook = null;

    public static function activate(array $hook): void
    {
        self::$active = true;
        self::$hook = $hook;
    }

    public static function active(): bool
    {
        return self::$active;
    }

    /** Сохранённая запись входа без секретов и содержимого запроса. */
    public static function hook(): ?array
    {
        return self::$hook;
    }

    public static function reset(): void
    {
        self::$active = false;
        self::$hook = null;
    }
}
