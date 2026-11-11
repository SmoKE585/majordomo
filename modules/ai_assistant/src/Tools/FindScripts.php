<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant\Tools;

use MajorDoMo\Modules\AiAssistant\ToolRegistry;
use NeuronAI\Tools\{PropertyType, Tool, ToolProperty};

/**
 * Поиск сценариев по названию, описанию и коду.
 * Изменения:
 * 2026-11-11 — поиск методов по пользовательскому коду и точный владелец результата.
 * 2026-10-23 — точный ID сценария для последующего read_code.
 */
final class FindScripts extends Tool
{
    public function __construct(private ToolRegistry $registry)
    {
        $this->name = 'find_scripts';
        $this->description = 'Найти пользовательский код по названию, описанию или фрагменту кода. kind=script ищет сценарии, kind=method — методы классов и объектов, включая привязанный сценарий. Возвращает точного владельца и ссылку для read_code. Пустой запрос выводит первые записи.';
        $this->properties = array(
            new ToolProperty('query', PropertyType::STRING, 'Часть названия, описания или кода.'),
            new ToolProperty('offset', PropertyType::INTEGER, 'Смещение, по умолчанию 0.'),
            new ToolProperty('limit', PropertyType::INTEGER, 'От 1 до 25, по умолчанию 10.'),
            new ToolProperty('kind', PropertyType::STRING, 'script (по умолчанию) или method.', false, array('script', 'method')),
        );
    }

    public function __invoke(string $query = '', int $offset = 0, int $limit = 10, string $kind = 'script'): array
    {
        return $this->registry->call('find_scripts', compact('query', 'offset', 'limit', 'kind'));
    }
}
