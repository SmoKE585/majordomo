<?php

use MajorDoMo\Core\Api\HookRegistry;

/**
 * Presentation helpers for the "Входящие webhook" tab.
 *
 * Storage and execution live in Core\Api\HookStore / WebhookRunner; this class
 * only turns rows into labels and lists for the admin templates.
 */
final class ApiHooks
{
    public static function decorate(array $hooks, string $root): array
    {
        foreach ($hooks as &$hook) {
            $hook['AVAILABLE'] = HookRegistry::available($root, (string)$hook['MODULE'], (string)$hook['HANDLER']);
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

    /** @return array<string,string> module name => human label */
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
                    // A fresh/test database may not have project_modules yet.
                }
            }
            $options[$module] = $title === $module ? $module : $title . ' (' . $module . ')';
        }
        return $options;
    }

    /** @return array<string,string[]> module name => relative PHP files */
    public static function handlerOptions(string $root): array
    {
        $options = array();
        foreach (array_keys(self::moduleOptions($root)) as $module) {
            $options[$module] = HookRegistry::moduleHandlers($root, $module);
        }
        return $options;
    }
}
