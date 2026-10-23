<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use MajorDoMo\Core\Database;
use RuntimeException;

/**
 * Состояние ИИ-помощника без создания провайдеров и сетевых обращений.
 *
 * Изменения:
 * 2026-10-23 — отдельного выключателя нет: установленный модуль всегда доступен,
 *             готовность определяется наличием провайдера, модели и агента.
 * 2026-10-23 — отключение, сохранение состояния при обновлении и переустановка.
 */
final class ModuleState
{
    public function __construct(private Database $db) {}

    /** @return array{installed:bool,enabled:bool,schema_version:int} */
    public function snapshot(): array
    {
        $record = $this->db->one('SELECT INSTALLED,ENABLED,SCHEMA_VERSION FROM ai_assistant_settings WHERE ID=1');
        if ($record === null) {
            throw new RuntimeException('Настройки ИИ-помощника отсутствуют. Повторите установку модуля.');
        }
        $installed = (int)$record['INSTALLED'] === 1;
        return array(
            'installed' => $installed,
            // Ручного выключателя больше нет: установленный модуль доступен всегда.
            'enabled' => $installed,
            'schema_version' => (int)$record['SCHEMA_VERSION'],
        );
    }

    /** Оставлено для совместимости со старыми вызовами; установка не выключается. */
    public function setEnabled(bool $enabled): void
    {
        if (!$enabled) return;
        if (!$this->snapshot()['installed']) {
            throw new RuntimeException('ИИ-помощник удалён. Сначала установите модуль.');
        }
        $this->db->requireExec('UPDATE ai_assistant_settings SET ENABLED=1,UPDATED_AT=' . time() . ' WHERE ID=1');
    }

    /** Проверяется до подготовки модели, истории и инструментов. */
    public function requireEnabled(): void
    {
        if (!$this->snapshot()['installed']) {
            throw new RuntimeException('ИИ-помощник удалён. Сначала установите модуль.');
        }
    }

    public function installed(): void
    {
        // Установленный модуль сразу доступен: отдельного включения нет.
        $this->db->requireExec('UPDATE ai_assistant_settings SET INSTALLED=1,ENABLED=1,UPDATED_AT=' . time()
            . ' WHERE ID=1 AND INSTALLED=0');
    }

    public function detached(): void
    {
        $this->db->requireExec('UPDATE ai_assistant_settings SET INSTALLED=0,ENABLED=0,UPDATED_AT=' . time() . ' WHERE ID=1');
    }
}
