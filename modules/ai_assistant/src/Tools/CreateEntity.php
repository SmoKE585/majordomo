<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant\Tools;

use NeuronAI\Tools\{PropertyType, Tool, ToolProperty};

/**
 * Предложение создания; Neuron останавливается перед исполнением.
 * Изменения:
 * 2026-10-23 — штатный approval gate, действие применяется отдельным запросом пользователя.
 */
final class CreateEntity extends Tool
{
    public function __construct()
    {
        $this->name = 'create_entity';
        $this->description = 'Предложить создание сценария, класса, объекта или свойства. Требует подтверждения пользователя кнопкой; не выполняет и не запускает код. Одно действие за вызов; существующий родитель должен быть подтверждён раньше.';
        $this->properties = array(
            new ToolProperty('kind', PropertyType::STRING, 'script, class, object, class_property или object_property.'),
            new ToolProperty('name', PropertyType::STRING, 'Точное новое имя без точки и пробелов.'),
            new ToolProperty('parent', PropertyType::STRING, 'Имя существующего класса для object/class_property, объекта для object_property; необязательный родитель класса.'),
            new ToolProperty('description', PropertyType::STRING, 'Описание по-русски.'),
            new ToolProperty('code', PropertyType::STRING, 'Для script: законченный PHP-код без тегов. Для остальных — пустая строка.'),
        );
        $this->requireApproval();
    }

    public function __invoke(string $kind, string $name, string $parent = '', string $description = '', string $code = ''): never
    {
        // Прямой вызов инструмента, минуя подтверждение в панели, запрещён.
        throw new \RuntimeException('Создание доступно только через подтверждение сохранённого предложения в чате.');
    }
}
