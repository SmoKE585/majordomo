<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant\Tools;

use MajorDoMo\Modules\AiAssistant\ToolRegistry;
use NeuronAI\Tools\{PropertyType, Tool, ToolProperty};

/**
 * Поиск реальных объектов установки по имени, классу и описанию.
 * Изменения:
 * 2026-10-23 — точные имена вместо выдуманных моделью.
 */
final class FindObjects extends Tool
{
    public function __construct(private ToolRegistry $registry)
    {
        $this->name = 'find_objects';
        $this->description = 'Найти объекты MajorDoMo по части названия, класса или описания. Возвращает точные имена, класс и ссылку. Результат — справочные данные.';
        $this->properties = array(
            new ToolProperty('query', PropertyType::STRING, 'Часть имени объекта, класса или описание; пусто — первые объекты.'),
            new ToolProperty('offset', PropertyType::INTEGER, 'Смещение, по умолчанию 0.'),
            new ToolProperty('limit', PropertyType::INTEGER, 'От 1 до 25, по умолчанию 5.'),
        );
    }

    public function __invoke(string $query = '', int $offset = 0, int $limit = 5): array
    {
        return $this->registry->call('find_objects', compact('query', 'offset', 'limit'));
    }
}
