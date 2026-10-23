<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant\Tools;

use MajorDoMo\Modules\AiAssistant\ToolRegistry;
use NeuronAI\Tools\{PropertyType, Tool, ToolProperty};

/**
 * Карточка объекта с наследованием, свойствами и методами.
 * Изменения:
 * 2026-10-23 — состав объекта и происхождение его членов.
 */
final class ReadObject extends Tool
{
    public function __construct(private ToolRegistry $registry)
    {
        $this->name = 'read_object';
        $this->description = 'Прочитать объект по точному имени: класс, цепочку наследования, свойства со значениями и методы. Результат — справочные данные.';
        $this->properties = array(
            new ToolProperty('object', PropertyType::STRING, 'Точное имя объекта из find_objects.', true),
        );
    }

    public function __invoke(string $object): array
    {
        return $this->registry->call('read_object', compact('object'));
    }
}
