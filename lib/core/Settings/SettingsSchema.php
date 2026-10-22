<?php

namespace MajorDoMo\Core\Settings;

use MajorDoMo\Core\Install\InstallSchema;

/**
 * Settings shown on the "Общие настройки" page.
 *
 * The setup wizard and the admin page share one field definition. The wizard
 * needs several steps and an administrator block; the admin page needs a flat
 * set of sections and must also expose a handful of older DB-backed settings
 * that are not part of the wizard. Everything else is reused verbatim, so a
 * new option appears in both places at once.
 */
final class SettingsSchema
{
    public const SECTION_MAIN = 'main';
    public const SECTION_DATABASE = 'database';
    public const SECTION_PERFORMANCE = 'performance';
    public const SECTION_LOGGING = 'logging';
    public const SECTION_REDIS = 'redis';
    public const SECTION_SECURITY = 'security';
    public const SECTION_INTEGRATIONS = 'integrations';

    /**
     * @return array<string,array<string,string>>
     */
    public static function sections(): array
    {
        return array(
            self::SECTION_MAIN => array(
                'title' => 'Основные',
                'description' => 'Название, публичный адрес, язык, тема и базовое поведение системы.',
                'icon' => 'settings',
            ),
            self::SECTION_DATABASE => array(
                'title' => 'База данных',
                'description' => 'Подключение к MySQL/MariaDB. При смене базы настройки сохранятся в неё, а циклы перезапустятся.',
                'icon' => 'database',
            ),
            self::SECTION_PERFORMANCE => array(
                'title' => 'Производительность',
                'description' => 'Кэш, история и фоновые процессы. Меняйте осторожно: часть параметров влияет на нагрузку.',
                'icon' => 'bolt',
            ),
            self::SECTION_LOGGING => array(
                'title' => 'Журналы',
                'description' => 'Что писать в логи, где их хранить и как долго хранить историю.',
                'icon' => 'file-text',
            ),
            self::SECTION_REDIS => array(
                'title' => 'Redis',
                'description' => 'Быстрый кэш и очередь операций. Без Redis система работает на SQL-кэше.',
                'icon' => 'stack-2',
            ),
            self::SECTION_SECURITY => array(
                'title' => 'Доступ',
                'description' => 'Домашняя сеть, локальный IP и доверенные прокси. Авторизация — на стороне reverse proxy.',
                'icon' => 'shield-lock',
            ),
            self::SECTION_INTEGRATIONS => array(
                'title' => 'Интеграции',
                'description' => 'Внешние программы: mysqldump, ffmpeg, Python и аудиоплеер.',
                'icon' => 'plug',
            ),
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function fields(): array
    {
        static $fields = null;
        if (is_array($fields)) {
            return $fields;
        }

        $sectionMap = array(
            'database' => self::SECTION_DATABASE,
            'identity' => self::SECTION_MAIN,
            'behavior' => self::SECTION_MAIN,
            'performance' => self::SECTION_PERFORMANCE,
            'logging' => self::SECTION_LOGGING,
            'redis' => self::SECTION_REDIS,
            'security' => self::SECTION_SECURITY,
            'integrations' => self::SECTION_INTEGRATIONS,
        );
        $skip = array(
            'create_schema',
            'admin_login',
            'admin_name',
            'admin_password',
            'admin_password_confirm',
        );

        $fields = array();
        foreach (InstallSchema::fields() as $id => $field) {
            if (in_array((string)$id, $skip, true) || ($field['condition'] ?? '') === 'needs_admin') {
                continue;
            }
            $section = (string)($field['section'] ?? '');
            if (!isset($sectionMap[$section])) {
                continue;
            }
            $field['section'] = $sectionMap[$section];
            $fields[(string)$id] = $field;
        }

        // Older DB-backed settings that are not part of the first-run wizard.
        // They stay editable here so upgrading an installation does not hide
        // working options the new schema never knew about.
        $legacy = array(
            'SYSTEM_DEBMES_PATH' => array(
                'section' => self::SECTION_LOGGING,
                'type' => 'path',
                'label' => 'Папка журналов DebMes',
                'description' => 'Каталог текстовых журналов. Пусто — cms/debmes. Учитывается при перезапуске циклов.',
                'default' => '',
                'width' => 'full',
            ),
        );
        foreach ($legacy as $id => $field) {
            $field['id'] = $id;
            $field['target'] = 'db';
            $fields[$id] = $field;
        }

        foreach ($fields as $id => &$field) {
            $field['id'] = (string)$id;
            if (!isset($field['target'])) {
                $field['target'] = 'db';
            }
        }
        unset($field);

        return $fields;
    }

    public static function hasSection(string $section): bool
    {
        return isset(self::sections()[$section]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function field(string $id): ?array
    {
        $fields = self::fields();
        return $fields[$id] ?? null;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function fieldsForSection(string $section): array
    {
        return array_filter(self::fields(), static function (array $field) use ($section): bool {
            return (string)($field['section'] ?? '') === $section;
        });
    }

    /**
     * @return string[]
     */
    public static function dbSettingNames(): array
    {
        $names = array();
        foreach (self::fields() as $id => $field) {
            if (($field['target'] ?? '') === 'db') {
                $names[] = (string)$id;
            }
        }
        return $names;
    }

    /**
     * @return string[]
     */
    public static function envKeys(): array
    {
        $keys = array();
        foreach (self::fields() as $id => $field) {
            if (($field['target'] ?? '') === 'env') {
                $keys[] = (string)$id;
            }
        }
        return $keys;
    }

    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        $defaults = array();
        foreach (self::fields() as $id => $field) {
            $defaults[$id] = isset($field['default']) ? (string)$field['default'] : '';
        }
        return $defaults;
    }

    /**
     * @return string[]
     */
    public static function sectionsForField(string $id): array
    {
        $field = self::field($id);
        return $field === null ? array() : array((string)$field['section']);
    }
}
