<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use InvalidArgumentException;
use MajorDoMo\Core\Inspection\InstallationInspector;
use RuntimeException;

/**
 * Реестр реализованных Neuron Tools; произвольных обработчиков из запроса нет.
 *
 * Изменения:
 * 2026-10-23 — подтверждаемое создание и пульс во время поиска знаний.
 * 2026-10-23 — объекты, свойства, ссылки, код и история установки под тем же
 * контролем прав, что и знания; у каждого инструмента фиксированная схема.
 * 2026-10-23 — чтение знаний через общую границу прав и лимитов результата.
 */
final class ToolRegistry
{
    public function __construct(
        private ToolAccess $access,
        private KnowledgeReader $knowledge,
        private ?InstallationInspector $installation = null,
        private ?KnowledgeIndex $index = null,
        private ?\Closure $heartbeat = null
    ) {}

    public function tools(): array
    {
        $agent = $this->access->snapshot();
        $tools = array();
        foreach (array(
            new Tools\FindObjects($this),
            new Tools\ReadObject($this),
            new Tools\ReadProperty($this),
            new Tools\FindReferences($this),
            new Tools\ReadCode($this),
            new Tools\ReadHistory($this),
            new Tools\FindScripts($this),
            new Tools\SearchKnowledge($this),
            new Tools\ReadKnowledge($this),
            new Tools\CreateEntity(),
        ) as $tool) {
            if (in_array($tool->getName(), $agent['TOOLS'], true)) $tools[] = $tool;
        }
        return $tools;
    }

    /** Все пути, включая прямой вызов адаптера, проходят эту проверку. */
    public function call(string $name, array $arguments): array
    {
        $agent = $this->access->begin($name);
        $allowed = match ($name) {
            'find_objects' => array('query', 'offset', 'limit'),
            'read_object' => array('object'),
            'read_property' => array('object', 'property'),
            'find_references' => array('object', 'property', 'limit'),
            'read_code' => array('object', 'method', 'class', 'script', 'offset', 'limit'),
            'read_history' => array('object', 'property', 'days', 'page'),
            'find_scripts' => array('query', 'offset', 'limit'),
            'search_knowledge' => array('query', 'offset', 'limit'),
            'read_knowledge' => array('id', 'offset', 'limit'),
            default => throw new InvalidArgumentException('Инструмент пока не реализован.'),
        };
        if (array_diff(array_keys($arguments), $allowed) !== array()) throw new InvalidArgumentException('Неизвестный аргумент инструмента.');
        $result = match ($name) {
            'find_objects' => $this->installation()->searchObjects(
                self::optionalText($arguments, 'query'),
                self::integer($arguments, 'offset', 0, 0, 1000),
                self::integer($arguments, 'limit', 5, 1, 25)),
            'read_object' => $this->installation()->objectDetails(self::text($arguments, array('object'))),
            'read_property' => $this->installation()->propertyDetails(
                self::text($arguments, array('object')), self::text($arguments, array('property'))),
            'find_references' => $this->installation()->references(
                self::text($arguments, array('object')),
                self::optionalText($arguments, 'property'), self::integer($arguments, 'limit', 10, 1, 30)),
            'read_code' => $this->code($arguments),
            'read_history' => $this->installation()->history(
                self::text($arguments, array('object')), self::text($arguments, array('property')),
                time() - self::integer($arguments, 'days', 7, 1, 90) * 86400, time(),
                self::integer($arguments, 'page', 1, 1, 10000)),
            'find_scripts' => $this->installation()->searchScripts(
                self::optionalText($arguments, 'query'),
                self::integer($arguments, 'offset', 0, 0, 1000000),
                self::integer($arguments, 'limit', 10, 1, 25)),
            'search_knowledge' => $this->searchKnowledge($arguments, $agent),
            'read_knowledge' => $this->knowledge->read(self::text($arguments, array('id')),
                self::integer($arguments, 'offset', 0, 0, 1000000),
                self::integer($arguments, 'limit', 4000, 1, 6000), $agent['SOURCES']),
        };
        $current = $this->access->finish($name);
        if (strlen(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 32768) {
            throw new RuntimeException('Результат инструмента превышает 32 КБ. Уменьшите размер страницы.');
        }
        // Повторная проверка источников не выдаёт данные после отзыва источника
        // во время чтения; увеличение разрешений также требует нового вызова.
        if ($agent['SOURCES'] !== $current['SOURCES'] || $agent['KNOWLEDGE_MODE'] !== $current['KNOWLEDGE_MODE']
            || $agent['TOOLS'] !== $current['TOOLS']) {
            throw new RuntimeException('Разрешения или режим знаний агента изменились. Повторите запрос.');
        }
        return $result;
    }

    private function searchKnowledge(array $arguments, array $agent): array
    {
        $query = self::text($arguments, array('query'));
        if ($agent['KNOWLEDGE_MODE'] !== 'rag') {
            return $this->knowledge->search($query,
                self::integer($arguments, 'offset', 0, 0, 1000000),
                self::integer($arguments, 'limit', 5, 1, 10), $agent['SOURCES']);
        }
        $found = $this->knowledgeIndex()->search($query, $agent['SOURCES'],
            self::integer($arguments, 'limit', 5, 1, 10));
        $items = array();
        foreach ($found['items'] as $item) {
            $items[] = array('id' => $item['id'], 'title' => $item['title'], 'section' => $item['section'],
                'text' => mb_substr($item['text'], 0, 1200), 'url' => $item['url'], 'source' => $item['source'],
                'score' => $item['score']);
        }
        return array('kind' => 'reference_data', 'mode' => 'rag', 'query' => $query,
            'model' => $found['model'], 'items' => $items, 'total' => count($items), 'next_offset' => null,
            'context' => mb_substr((string)$found['context'], 0, 16000));
    }

    private function knowledgeIndex(): KnowledgeIndex
    {
        if ($this->index !== null) return $this->index;
        $vault = new SecretVault();
        $models = new ModelCatalog(\MajorDoMo\Core\Core::database(), $vault);
        return $this->index = new KnowledgeIndex(\MajorDoMo\Core\Core::database(),
            new KnowledgeSettings(\MajorDoMo\Core\Core::database(), $vault), $models,
            new ProviderFactory($models, null, $this->heartbeat), new QdrantClient());
    }

    private function code(array $arguments): array
    {
        $script = self::optionalText($arguments, 'script');
        $object = self::optionalText($arguments, 'object');
        $class = self::optionalText($arguments, 'class');
        $method = self::optionalText($arguments, 'method');
        $offset = self::integer($arguments, 'offset', 0, 0, 1000000);
        $limit = self::integer($arguments, 'limit', 4000, 1, 12000);
        if ($script !== '') {
            if ($object !== '' || $method !== '' || $class !== '') throw new InvalidArgumentException('Укажите либо script, либо object/class и method.');
            return $this->installation()->scriptCode($script, $offset, $limit);
        }
        if ($method === '' || ($object === '' && $class === '')) {
            throw new InvalidArgumentException('Укажите script или method с object либо class.');
        }
        return $this->installation()->methodCode($object, $method, $offset, $limit, $class);
    }

    private function installation(): InstallationInspector
    {
        return $this->installation ??= new InstallationInspector(\MajorDoMo\Core\Core::database());
    }

    private static function text(array $arguments, array $keys): string
    {
        foreach ($keys as $key) {
            if (!is_string($arguments[$key] ?? null)) throw new InvalidArgumentException('Требуется строковый аргумент ' . $key . '.');
        }
        return (string)$arguments[$keys[0]];
    }

    private static function optionalText(array $arguments, string $key): string
    {
        if (!array_key_exists($key, $arguments)) return '';
        if (!is_string($arguments[$key])) throw new InvalidArgumentException('Требуется строковый аргумент ' . $key . '.');
        return $arguments[$key];
    }

    private static function integer(array $arguments, string $key, int $default, int $minimum, int $maximum): int
    {
        if (!array_key_exists($key, $arguments)) return $default;
        return ModelCatalog::integer($arguments, $key, $minimum, $maximum);
    }
}
