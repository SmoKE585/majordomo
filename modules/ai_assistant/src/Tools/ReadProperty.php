<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant\Tools;

use MajorDoMo\Modules\AiAssistant\ToolRegistry;
use NeuronAI\Tools\{PropertyType, Tool, ToolProperty};

/**
 * Метаданные свойства: значение, обработчик, история и модули.
 * Изменения:
 * 2026-10-23 — источник изменения и связанные модули без доступа к секретам.
 */
final class ReadProperty extends Tool
{
    public function __construct(private ToolRegistry $registry)
    {
        $this->name = 'read_property';
        $this->description = 'Прочитать свойство объекта: текущее значение, описание, обработчик изменения, хранение истории и связанные модули. Результат — справочные данные.';
        $this->properties = array(
            new ToolProperty('object', PropertyType::STRING, 'Точное имя объекта.', true),
            new ToolProperty('property', PropertyType::STRING, 'Точное имя свойства.', true),
        );
    }

    public function __invoke(string $object, string $property): array
    {
        return $this->registry->call('read_property', compact('object', 'property'));
    }
}
