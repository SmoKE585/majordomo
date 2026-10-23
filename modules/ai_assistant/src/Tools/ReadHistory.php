<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant\Tools;

use MajorDoMo\Modules\AiAssistant\ToolRegistry;
use NeuronAI\Tools\{PropertyType, Tool, ToolProperty};

/**
 * Ограниченная история изменения свойства и цепочка запусков.
 * Изменения:
 * 2026-10-23 — существующий журнал аудита вместо чтения таблиц моделью.
 */
final class ReadHistory extends Tool
{
    public function __construct(private ToolRegistry $registry)
    {
        $this->name = 'read_history';
        $this->description = 'Показать последние изменения свойства: кто, откуда, старое и новое значение. Только чтение существующего журнала аудита.';
        $this->properties = array(
            new ToolProperty('object', PropertyType::STRING, 'Точное имя объекта.', true),
            new ToolProperty('property', PropertyType::STRING, 'Точное имя свойства.', true),
            new ToolProperty('days', PropertyType::INTEGER, 'Период в днях от 1 до 90, по умолчанию 7.'),
            new ToolProperty('page', PropertyType::INTEGER, 'Страница журнала, по умолчанию 1.'),
        );
    }

    public function __invoke(string $object, string $property, int $days = 7, int $page = 1): array
    {
        return $this->registry->call('read_history', compact('object', 'property', 'days', 'page'));
    }
}
