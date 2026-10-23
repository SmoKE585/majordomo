<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant\Tools;

use MajorDoMo\Modules\AiAssistant\ToolRegistry;
use NeuronAI\Tools\{PropertyType, Tool, ToolProperty};

/**
 * Чтение кода метода объекта или сценария порциями.
 * Изменения:
 * 2026-10-23 — код родителя и постраничное чтение без выполнения.
 */
final class ReadCode extends Tool
{
    public function __construct(private ToolRegistry $registry)
    {
        $this->name = 'read_code';
        $this->description = 'Прочитать код метода объекта (object + method) или сценария (script — ID или точное название). Код родительского метода возвращается отдельно. Код не выполняется.';
        $this->properties = array(
            new ToolProperty('object', PropertyType::STRING, 'Объект для метода.'),
            new ToolProperty('class', PropertyType::STRING, 'Класс, если метода нет у конкретного объекта.'),
            new ToolProperty('method', PropertyType::STRING, 'Имя метода объекта или класса.'),
            new ToolProperty('script', PropertyType::STRING, 'ID или точное название сценария.'),
            new ToolProperty('offset', PropertyType::INTEGER, 'Смещение в символах, по умолчанию 0.'),
            new ToolProperty('limit', PropertyType::INTEGER, 'От 1 до 12000 символов, по умолчанию 4000.'),
        );
    }

    public function __invoke(string $object = '', string $method = '', string $script = '', int $offset = 0, int $limit = 4000, string $class = ''): array
    {
        return $this->registry->call('read_code', compact('object', 'method', 'script', 'offset', 'limit', 'class'));
    }
}
