<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant\Tools;

use MajorDoMo\Modules\AiAssistant\ToolRegistry;
use NeuronAI\Tools\{PropertyType, Tool, ToolProperty};

/**
 * Кто использует объект или свойство: события, модули и текстовые совпадения.
 * Изменения:
 * 2026-10-23 — точные связи отделены от текстового поиска по коду.
 */
final class FindReferences extends Tool
{
    public function __construct(private ToolRegistry $registry)
    {
        $this->name = 'find_references';
        $this->description = 'Найти точные связи объекта/свойства (события, модули) и текстовые совпадения в методах и сценариях. Текстовые совпадения не доказывают связь.';
        $this->properties = array(
            new ToolProperty('object', PropertyType::STRING, 'Точное имя объекта.', true),
            new ToolProperty('property', PropertyType::STRING, 'Имя свойства; пусто — весь объект.'),
            new ToolProperty('limit', PropertyType::INTEGER, 'От 1 до 30, по умолчанию 10.'),
        );
    }

    public function __invoke(string $object, string $property = '', int $limit = 10): array
    {
        return $this->registry->call('find_references', compact('object', 'property', 'limit'));
    }
}
