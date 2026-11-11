<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant\Tools;

use NeuronAI\Tools\{ArrayProperty, ObjectProperty, PropertyType, Tool, ToolProperty};

/**
 * Предложение создания; Neuron останавливается перед исполнением.
 * Изменения:
 * 2026-11-11 — пакет предложений, создание и правка методов с подтверждением.
 * 2026-10-23 — штатный approval gate, действие применяется отдельным запросом пользователя.
 */
final class CreateEntity extends Tool
{
    public function __construct()
    {
        $this->name = 'create_entity';
        $this->description = 'Предложить создание сценариев, классов, объектов, свойств и методов, либо правку методов. Все действия задачи передавай сразу в changes, в порядке зависимостей. Каждое подтверждается отдельной кнопкой. Код не запускается. Для одного действия допустимы поля верхнего уровня.';
        $fields = array(
            new ToolProperty('kind', PropertyType::STRING, 'script, class, object, class_property, object_property, class_method или object_method.'),
            new ToolProperty('name', PropertyType::STRING, 'Точное новое имя без точки и пробелов.'),
            new ToolProperty('parent', PropertyType::STRING, 'Точное имя класса для object/class_property/class_method, объекта для object_property/object_method; необязательный родитель класса. Может создаваться предыдущей карточкой пакета.'),
            new ToolProperty('description', PropertyType::STRING, 'Описание по-русски. При update отсутствие поля сохраняет текущее описание, пустая строка очищает.'),
            new ToolProperty('code', PropertyType::STRING, 'Полный PHP-код сценария или метода без тегов. Для остальных — пустая строка.'),
            new ToolProperty('operation', PropertyType::STRING, 'create (по умолчанию) или update (только методы).', false, array('create', 'update')),
            new ToolProperty('call_parent', PropertyType::STRING, 'Для метода: 0 — не вызывать родителя, 1 — до кода, 2 — после. Пусто — сохранить при правке, иначе 0.', false, array('', '0', '1', '2')),
        );
        $this->properties = array_merge($fields, array(new ArrayProperty('changes', 'Все предложения по задаче, в порядке: родители, затем дочерние сущности. Не смешивать с одиночными полями.', false,
            new ObjectProperty('change', 'Одно подтверждаемое действие.', false, null, $fields), 1, 30)));
        $this->requireApproval();
    }

    public function __invoke(string $kind = '', string $name = '', string $parent = '', string $description = '', string $code = '', string $operation = 'create', string $call_parent = '', array $changes = array()): never
    {
        // Прямой вызов инструмента, минуя подтверждение в панели, запрещён.
        throw new \RuntimeException('Создание доступно только через подтверждение сохранённого предложения в чате.');
    }
}
