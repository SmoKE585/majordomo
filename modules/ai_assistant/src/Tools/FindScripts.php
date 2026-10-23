<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant\Tools;

use MajorDoMo\Modules\AiAssistant\ToolRegistry;
use NeuronAI\Tools\{PropertyType, Tool, ToolProperty};

/**
 * Поиск сценариев по названию, описанию и коду.
 * Изменения:
 * 2026-10-23 — точный ID сценария для последующего read_code.
 */
final class FindScripts extends Tool
{
    public function __construct(private ToolRegistry $registry)
    {
        $this->name = 'find_scripts';
        $this->description = 'Найти сценарии по названию, описанию или фрагменту кода. Возвращает ID и ссылку. Пустой запрос выводит первые сценарии.';
        $this->properties = array(
            new ToolProperty('query', PropertyType::STRING, 'Часть названия, описания или кода.'),
            new ToolProperty('offset', PropertyType::INTEGER, 'Смещение, по умолчанию 0.'),
            new ToolProperty('limit', PropertyType::INTEGER, 'От 1 до 25, по умолчанию 10.'),
        );
    }

    public function __invoke(string $query = '', int $offset = 0, int $limit = 10): array
    {
        return $this->registry->call('find_scripts', compact('query', 'offset', 'limit'));
    }
}
