<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use InvalidArgumentException;
use MajorDoMo\Core\Database;
use RuntimeException;

/**
 * Версионированные инструкции двух штатных агентов, независимые от настроек пользователя.
 *
 * Изменения:
 * 2026-10-23 — помощник MajorDoMo и редактора; обновление не затирает инструкции.
 */
final class StandardProfiles
{
    public const VERSION = 1;

    public static function instructions(string $profile): string
    {
        if (!in_array($profile, array('assistant', 'editor'), true)) throw new InvalidArgumentException('У агента нет стандартного профиля.');
        $path = dirname(__DIR__) . '/prompts/' . $profile . '.md';
        if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Стандартные инструкции агента недоступны. Восстановите файлы модуля.');
        $text = file_get_contents($path);
        if ($text === false || trim($text) === '') throw new RuntimeException('Стандартные инструкции агента недоступны. Восстановите файлы модуля.');
        return $text;
    }

    /** Начальные записи создаются при миграции; существующие поля не обновляются. */
    public static function seed(Database $db, bool $sqlite): void
    {
        foreach (array('assistant' => 'Помощник MajorDoMo', 'editor' => 'Помощник редактора') as $profile => $title) {
            $values = array(
                $db->escape($profile), $db->escape($title), $db->escape(self::instructions($profile)),
                $db->escape(json_encode(AgentCatalog::TOOLS, JSON_THROW_ON_ERROR)),
                $db->escape(json_encode(array('core', 'modules'), JSON_THROW_ON_ERROR)),
            );
            $db->requireExec("INSERT INTO ai_assistant_agents (SLUG,TITLE,PROFILE,INSTRUCTIONS,BASE_VERSION,TOOLS,SOURCES)
                VALUES ('{$values[0]}','{$values[1]}','{$values[0]}','{$values[2]}'," . self::VERSION . ",'{$values[3]}','{$values[4]}')"
                . ($sqlite ? ' ON CONFLICT(SLUG) DO NOTHING' : ' ON DUPLICATE KEY UPDATE ID=ID'));
        }
    }
}
