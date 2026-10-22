<?php

namespace MajorDoMo\Core\Events;

/**
 * Typed vocabulary for system lifecycle events.
 *
 * Keep names stable: they are public API for modules (`subscribeToEvent()`),
 * scenarios, MQTT forwarding and the admin journal.
 */
final class SystemEvents
{
    /** A module was installed or enabled. */
    public const MODULE_INSTALLED = 'MODULE_INSTALLED';
    /** A module was removed or uninstalled. */
    public const MODULE_REMOVED = 'MODULE_REMOVED';
    /** A module was updated to a newer version. */
    public const MODULE_UPDATED = 'MODULE_UPDATED';

    /** The system installation wizard completed. */
    public const SYSTEM_INSTALLED = 'SYSTEM_INSTALLED';
    /** A system update started (files are about to be replaced). */
    public const SYSTEM_UPDATE_STARTED = 'SYSTEM_UPDATE_STARTED';
    /** A system update finished successfully. */
    public const SYSTEM_UPDATED = 'SYSTEM_UPDATED';
    /** A system update failed before the files were replaced. */
    public const SYSTEM_UPDATE_FAILED = 'SYSTEM_UPDATE_FAILED';
    /** A system update was cancelled after the pause started. */
    public const SYSTEM_UPDATE_ABORTED = 'SYSTEM_UPDATE_ABORTED';

    /** General settings were saved. */
    public const SETTINGS_CHANGED = 'SETTINGS_CHANGED';

    /** A cycle script appeared in `scripts/` and was picked up by the supervisor. */
    public const CYCLE_SCRIPT_ADDED = 'CYCLE_SCRIPT_ADDED';
    /** A cycle script disappeared from `scripts/` and is no longer supervised. */
    public const CYCLE_SCRIPT_REMOVED = 'CYCLE_SCRIPT_REMOVED';

    /** @return string[] */
    public static function names(): array
    {
        return array(
            self::MODULE_INSTALLED,
            self::MODULE_REMOVED,
            self::MODULE_UPDATED,
            self::SYSTEM_INSTALLED,
            self::SYSTEM_UPDATE_STARTED,
            self::SYSTEM_UPDATED,
            self::SYSTEM_UPDATE_FAILED,
            self::SYSTEM_UPDATE_ABORTED,
            self::SETTINGS_CHANGED,
            self::CYCLE_SCRIPT_ADDED,
            self::CYCLE_SCRIPT_REMOVED,
        );
    }

    /** Human readable title for the admin journal. */
    public static function title(string $name): string
    {
        $titles = array(
            self::MODULE_INSTALLED => 'Модуль установлен',
            self::MODULE_REMOVED => 'Модуль удалён',
            self::MODULE_UPDATED => 'Модуль обновлён',
            self::SYSTEM_INSTALLED => 'Система установлена',
            self::SYSTEM_UPDATE_STARTED => 'Обновление системы начато',
            self::SYSTEM_UPDATED => 'Система обновлена',
            self::SYSTEM_UPDATE_FAILED => 'Обновление системы не выполнено',
            self::SYSTEM_UPDATE_ABORTED => 'Обновление системы отменено',
            self::SETTINGS_CHANGED => 'Изменены настройки',
            self::CYCLE_SCRIPT_ADDED => 'Добавлен цикл',
            self::CYCLE_SCRIPT_REMOVED => 'Удалён цикл',
        );
        return $titles[$name] ?? $name;
    }

    /** Severity used by the journal and the dashboard tone. */
    public static function severity(string $name): string
    {
        if (in_array($name, array(self::SYSTEM_UPDATE_FAILED, self::SYSTEM_UPDATE_ABORTED), true)) {
            return 'error';
        }
        if (in_array($name, array(self::MODULE_REMOVED, self::CYCLE_SCRIPT_REMOVED), true)) {
            return 'warning';
        }
        if (in_array($name, array(self::SYSTEM_UPDATED, self::MODULE_INSTALLED, self::SYSTEM_INSTALLED), true)) {
            return 'success';
        }
        return 'info';
    }

    public static function moduleInstalled(string $module, string $source = 'market'): int
    {
        return EventBus::emit(self::MODULE_INSTALLED, array(
            'module' => $module,
            'source' => $source,
        ), $source);
    }

    /**
     * @param string[] $cycles cycle scripts that belong to the removed module
     */
    public static function moduleRemoved(string $module, array $cycles = array(), string $source = 'market'): int
    {
        return EventBus::emit(self::MODULE_REMOVED, array(
            'module' => $module,
            'cycles' => array_values($cycles),
        ), $source);
    }

    public static function moduleUpdated(string $module, string $from = '', string $to = '', string $source = 'market'): int
    {
        return EventBus::emit(self::MODULE_UPDATED, array(
            'module' => $module,
            'from' => $from,
            'to' => $to,
        ), $source);
    }

    public static function systemInstalled(string $version = ''): int
    {
        return EventBus::emit(self::SYSTEM_INSTALLED, array('version' => $version), 'install');
    }

    public static function systemUpdateStarted(string $from = '', string $to = '', string $branch = ''): int
    {
        return EventBus::emit(self::SYSTEM_UPDATE_STARTED, array(
            'from' => $from,
            'to' => $to,
            'branch' => $branch,
        ), 'saverestore');
    }

    public static function systemUpdated(string $from = '', string $to = '', string $branch = ''): int
    {
        return EventBus::emit(self::SYSTEM_UPDATED, array(
            'from' => $from,
            'to' => $to,
            'branch' => $branch,
        ), 'saverestore');
    }

    public static function systemUpdateFailed(string $reason = ''): int
    {
        return EventBus::emit(self::SYSTEM_UPDATE_FAILED, array(
            'reason' => substr($reason, 0, 2000),
        ), 'saverestore');
    }

    public static function systemUpdateAborted(string $reason = ''): int
    {
        return EventBus::emit(self::SYSTEM_UPDATE_ABORTED, array(
            'reason' => substr($reason, 0, 2000),
        ), 'saverestore');
    }

    /**
     * Changed keys only: values may contain credentials and are never logged.
     *
     * @param string[] $keys
     */
    public static function settingsChanged(string $section, array $keys = array(), string $source = 'settings'): int
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
        return EventBus::emit(self::SETTINGS_CHANGED, array(
            'section' => $section,
            'keys' => array_slice($keys, 0, 100),
        ), $source);
    }

    public static function cycleScriptAdded(string $cycle): int
    {
        return EventBus::emit(self::CYCLE_SCRIPT_ADDED, array('cycle' => $cycle), 'cycle');
    }

    public static function cycleScriptRemoved(string $cycle): int
    {
        return EventBus::emit(self::CYCLE_SCRIPT_REMOVED, array('cycle' => $cycle), 'cycle');
    }
}
