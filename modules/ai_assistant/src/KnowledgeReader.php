<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use Closure;
use InvalidArgumentException;
use MajorDoMo\Core\Config;
use MDKnowledgeBase;

/**
 * Ограниченное чтение Markdown через существующий реестр базы знаний.
 *
 * Изменения:
 * 2026-10-23 — полный текстовый поиск, источники агента и постраничное чтение.
 */
final class KnowledgeReader
{
    public function __construct(private ?string $root = null, private ?Closure $isInstalled = null) {}

    public function search(string $query, int $offset, int $limit, array $sources): array
    {
        if (trim($query) === '' || mb_strlen($query) > 256 || !mb_check_encoding($query, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $query)) {
            throw new InvalidArgumentException('Поисковый запрос должен содержать от 1 до 256 символов UTF-8.');
        }
        self::page($offset, $limit, 10);
        $terms = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
        if (count($terms) > 10) throw new InvalidArgumentException('Используйте не больше 10 поисковых слов.');
        $registry = $this->registry();
        $matches = array();
        foreach ($registry->documents() as $document) {
            if (!$this->allowed($document, $sources)) continue;
            $title = SensitiveData::text($document['title']);
            $body = SensitiveData::text($document['body']);
            $text = mb_strtolower($title . '\n' . $body);
            $score = 0;
            foreach ($terms as $term) {
                if (!str_contains($text, $term)) continue 2;
                $score += 1 + (mb_stripos($title, $term) === false ? 0 : 5);
            }
            $position = mb_stripos($body, $terms[0]);
            $matches[] = array('id' => $document['id'], 'title' => $title,
                'url' => $registry->prettyUrl($document['id']), 'source' => $document['source_label'],
                'module' => $document['module'], 'score' => $score,
                'excerpt' => mb_substr($body, max(0, ($position === false ? 0 : $position) - 120), 500));
        }
        usort($matches, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['id'], $b['id']));
        $total = count($matches);
        return array('kind' => 'reference_data', 'mode' => 'text', 'query' => $query, 'items' => array_slice($matches, $offset, $limit),
            'total' => $total, 'next_offset' => $offset + $limit < $total ? $offset + $limit : null);
    }

    public function read(string $id, int $offset, int $limit, array $sources): array
    {
        self::page($offset, $limit, 6000);
        if ($id === '' || strlen($id) > 512 || str_contains($id, '..') || str_contains($id, '\\') || !mb_check_encoding($id, 'UTF-8')) {
            throw new InvalidArgumentException('Некорректный идентификатор статьи. Используйте ID из поиска знаний.');
        }
        $registry = $this->registry();
        $document = $registry->documents()[$id] ?? null;
        if ($document === null || !$this->allowed($document, $sources)) throw new InvalidArgumentException('Статья не найдена в разрешённых источниках знаний.');
        $body = SensitiveData::text($document['body']);
        $total = mb_strlen($body);
        return array('kind' => 'reference_data', 'id' => $document['id'], 'title' => SensitiveData::text($document['title']),
            'url' => $registry->prettyUrl($id), 'source' => $document['source_label'], 'module' => $document['module'],
            'content' => mb_substr($body, $offset, $limit), 'offset' => $offset, 'total_characters' => $total,
            'next_offset' => $offset + $limit < $total ? $offset + $limit : null);
    }

    private function registry(): MDKnowledgeBase
    {
        $root = $this->root ?? Config::root();
        require_once dirname(__DIR__, 3) . '/lib/knowledge_base.php';
        $installed = $this->isInstalled ?? static fn(string $module): bool => (bool)\isModuleInstalled($module);
        // Новый реестр на каждый вызов учитывает обновление файлов и удаление модуля
        // в ходе длинного диалога; правил сканирования здесь нет.
        return new MDKnowledgeBase($root . '/docs', Config::string('ROOTHTML'), MDKnowledgeBase::moduleSources($root . '/modules', $installed));
    }

    private function allowed(array $document, array $sources): bool
    {
        return in_array($document['is_module'] ? 'modules' : 'core', $sources, true);
    }

    private static function page(int $offset, int $limit, int $maximum): void
    {
        if ($offset < 0 || $offset > 1000000 || $limit < 1 || $limit > $maximum) throw new InvalidArgumentException('Параметры страницы выходят за допустимые пределы.');
    }
}
