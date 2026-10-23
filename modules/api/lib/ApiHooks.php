<?php

use MajorDoMo\Core\Api\HookRegistry;

/**
 * Помощники представления для вкладки «Входящие webhook».
 *
 * Хранение и исполнение живут в Core\Api\HookStore / WebhookRunner; этот класс
 * только превращает строки БД в подписи и списки для шаблонов админки.
 * Изменения: 2026-10-23 — подписи и доступность module/script/code без чтения кода.
 */
final class ApiHooks
{
    public static function decorate(array $hooks, string $root): array
    {
        foreach ($hooks as &$hook) {
            $type = $hook['HANDLER_TYPE'];
            $hook['AVAILABLE'] = match ($type) {
                'module' => HookRegistry::available($root, (string)$hook['MODULE'], (string)$hook['HANDLER']),
                'script' => $hook['SCRIPT_TITLE'] !== null,
                'code' => !empty($hook['HAS_CODE']),
                default => false,
            };
            $hook['TYPE_LABEL'] = match ($type) { 'module' => 'Модуль', 'script' => 'Сценарий', 'code' => 'PHP-код', default => 'Неизвестный тип' };
            $hook['TARGET_LABEL'] = match ($type) {
                'module' => $hook['MODULE'] . '/' . $hook['HANDLER'],
                'script' => $hook['SCRIPT_TITLE'] ?? ('Сценарий #' . (int)$hook['SCRIPT_ID']),
                'code' => 'Собственный PHP-код',
                default => 'Обработчик недоступен',
            };
            $hook['STATUS_LABEL'] = self::statusLabel($hook['LAST_STATUS'] ?? null);
            $hook['STATUS_TONE'] = self::statusTone($hook['LAST_STATUS'] ?? null);
            $hook['LAST_USED_LABEL'] = empty($hook['LAST_USED_AT'])
                ? 'Ещё не вызывался'
                : date('d.m.Y H:i:s', (int)$hook['LAST_USED_AT']);
            $hook['METHODS_LIST'] = \MajorDoMo\Core\Api\HookStore::methods($hook);
        }
        unset($hook);
        return $hooks;
    }

    public static function stats(array $hooks): array
    {
        $stats = array('total' => 0, 'active' => 0, 'disabled' => 0, 'attention' => 0);
        foreach ($hooks as $hook) {
            $stats['total']++;
            if ((int)$hook['ENABLED'] === 1) {
                $stats['active']++;
            } else {
                $stats['disabled']++;
            }
            if (empty($hook['AVAILABLE']) || (int)($hook['LAST_STATUS'] ?? 0) >= 400) {
                $stats['attention']++;
            }
        }
        return $stats;
    }

    public static function statusLabel(?int $status): string
    {
        return $status === null ? 'Нет обращений' : 'HTTP ' . $status;
    }

    public static function statusTone(?int $status): string
    {
        if ($status === null) {
            return 'neutral';
        }
        if ($status >= 500) {
            return 'danger';
        }
        if ($status >= 400) {
            return 'warning';
        }
        if ($status >= 300) {
            return 'info';
        }
        return 'success';
    }

    public static function link(string $secret): string
    {
        return rtrim(defined('ROOTHTML') ? (string)ROOTHTML : '/', '/') . '/api/hooks/' . $secret;
    }

    /** @return array<string,string> имя модуля => подпись для человека */
    public static function moduleOptions(string $root): array
    {
        $options = array();
        foreach (HookRegistry::installedModules($root) as $module) {
            $title = $module;
            if (function_exists('SQLSelectOne')) {
                try {
                    $row = SQLSelectOne("SELECT TITLE FROM project_modules WHERE NAME='" . $module . "'");
                    if (is_array($row) && !empty($row['TITLE'])) {
                        $title = (string)$row['TITLE'];
                    }
                } catch (\Throwable $error) {
                    // В свежей или тестовой БД таблицы project_modules может ещё не быть.
                }
            }
            $options[$module] = $title === $module ? $module : $title . ' (' . $module . ')';
        }
        return $options;
    }

    /** @return array<string,string[]> имя модуля => относительные PHP-файлы */
    public static function handlerOptions(string $root): array
    {
        $options = array();
        foreach (array_keys(self::moduleOptions($root)) as $module) {
            $options[$module] = HookRegistry::moduleHandlers($root, $module);
        }
        return $options;
    }
}
