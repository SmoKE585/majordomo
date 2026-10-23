<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use InvalidArgumentException;
use MajorDoMo\Core\Database;
use RuntimeException;
use Throwable;

/**
 * Настраиваемые агенты и их разрешения; инструкции не меняют серверный набор Tools.
 *
 * Изменения:
 * 2026-10-23 — поиск сценариев добавлен в серверный список Tools.
 * 2026-10-23 — серверный запрет RAG без доступного Qdrant и моделей эмбеддингов.
 * 2026-10-23 — создание агента без модели учитывает NULL-контракт штатного Insert.
 * 2026-10-23 — профили, копирование, восстановление и защита конкурентных изменений.
 */
final class AgentCatalog
{
    public const TOOLS = array('find_objects', 'read_object', 'read_property', 'find_references', 'read_code', 'read_history', 'find_scripts', 'search_knowledge', 'read_knowledge');

    public function __construct(private Database $db, private ModelCatalog $models, private ?RagAvailability $rag = null) {}

    public function all(): array
    {
        return array_map(self::decode(...), $this->db->select('SELECT * FROM ai_assistant_agents WHERE DELETED=0 ORDER BY ID'));
    }

    public function get(int $id): array
    {
        $record = $this->db->one('SELECT * FROM ai_assistant_agents WHERE ID=' . $id . ' AND DELETED=0');
        if ($record === null) throw new InvalidArgumentException('Агент не найден.');
        return self::decode($record);
    }

    public function save(int $id, array $input): int
    {
        return $this->persist($id, $input);
    }

    public function copy(int $id, string $title): int
    {
        $input = $this->get($id);
        $input['TITLE'] = $title;
        $input['MODEL_ID'] = (int)$input['MODEL_ID'];
        return $this->persist(0, $input);
    }

    public function restoreInstructions(int $id, int $revision): void
    {
        $input = $this->get($id);
        $input['MODEL_ID'] = (int)$input['MODEL_ID'];
        $input['REVISION'] = $revision;
        $input['INSTRUCTIONS'] = StandardProfiles::instructions($input['PROFILE']);
        $this->persist($id, $input, StandardProfiles::VERSION);
    }

    public function delete(int $id, int $revision): void
    {
        $this->get($id);
        $this->db->requireExec('BEGIN', true);
        try {
            // История диалогов сохраняет связь с записью агента, а ссылка на
            // модель освобождается, чтобы удалённый агент не блокировал настройки.
            $this->db->requireExec('UPDATE ai_assistant_agents SET DELETED=1,ENABLED=0,MODEL_ID=NULL,REVISION=REVISION+1'
                . ' WHERE ID=' . $id . ' AND REVISION=' . $revision . ' AND DELETED=0', true);
            if ($this->db->affectedRows() !== 1) throw new RuntimeException('Агент уже изменён. Обновите страницу перед удалением.');
            $this->db->requireExec("DELETE FROM ai_assistant_model_links WHERE OWNER_TYPE='agent' AND OWNER_ID=" . $id, true);
            $this->db->requireExec('COMMIT', true);
        } catch (Throwable $failure) {
            $this->db->requireExec('ROLLBACK', true);
            throw $failure;
        }
    }

    private function persist(int $id, array $input, ?int $baseVersion = null): int
    {
        if ($id < 0) throw new InvalidArgumentException('Некорректный ID агента.');
        $previous = $id > 0 ? $this->get($id) : null;
        $modelId = ModelCatalog::integer($input, 'MODEL_ID', 0, PHP_INT_MAX);
        $tools = self::selection($input['TOOLS'] ?? null, self::TOOLS);
        $sources = self::selection($input['SOURCES'] ?? null, array('core', 'modules'));
        $knowledgeMode = $input['KNOWLEDGE_MODE'] ?? $previous['KNOWLEDGE_MODE'] ?? 'text';
        if (!is_string($knowledgeMode) || !in_array($knowledgeMode, array('text', 'rag'), true)) {
            throw new InvalidArgumentException('Выберите текстовый поиск или RAG.');
        }
        if ($knowledgeMode === 'rag') {
            if ($sources === array() || !in_array('search_knowledge', $tools, true)) {
                throw new InvalidArgumentException('Для RAG выберите источники и инструмент поиска знаний.');
            }
            $this->ragAvailability()->requireAvailable();
        }
        if ($modelId > 0) {
            $model = $this->models->model($modelId);
            if (!in_array('chat', $model['CAPABILITIES'], true) || ($tools !== array() && !in_array('tools', $model['CAPABILITIES'], true))) {
                throw new InvalidArgumentException('Выбранная модель не поддерживает чат или разрешённые агенту Tools.');
            }
        }
        $instructions = $input['INSTRUCTIONS'] ?? null;
        if (!is_string($instructions) || trim($instructions) === '' || strlen($instructions) > 65536
            || !mb_check_encoding($instructions, 'UTF-8') || str_contains($instructions, "\0")) {
            throw new InvalidArgumentException('Инструкции должны содержать текст UTF-8 длиной до 64 КБ.');
        }
        $record = array(
            'TITLE' => ModelCatalog::text($input, 'TITLE', 190),
            'MODEL_ID' => $modelId > 0 ? $modelId : null,
            'INSTRUCTIONS' => $instructions,
            'TOOLS' => json_encode($tools, JSON_THROW_ON_ERROR),
            'SOURCES' => json_encode($sources, JSON_THROW_ON_ERROR),
            'KNOWLEDGE_MODE' => $knowledgeMode,
            'MAX_STEPS' => ModelCatalog::integer($input, 'MAX_STEPS', 1, 32),
            'TIMEOUT' => ModelCatalog::integer($input, 'TIMEOUT', 1, 600),
            'ENABLED' => ModelCatalog::integer($input, 'ENABLED', 0, 1),
            'BASE_VERSION' => $baseVersion ?? ($previous === null ? 0 : (int)$previous['BASE_VERSION']),
        );
        $revision = $previous === null ? 0 : ModelCatalog::integer($input, 'REVISION', 1, PHP_INT_MAX);
        $this->db->requireExec('BEGIN', true);
        try {
            if ($previous === null) {
                $record['SLUG'] = 'custom-' . bin2hex(random_bytes(12));
                $record['PROFILE'] = 'custom';
                // Штатный mysqli::Insert превращает переданный null в ''.
                // Для необязательной модели используем SQL-default NULL.
                if ($record['MODEL_ID'] === null) unset($record['MODEL_ID']);
                $id = $this->db->insert('ai_assistant_agents', $record);
                if ($id < 1) throw new RuntimeException('Не удалось создать агента.');
            } else {
                $assignments = array();
                foreach ($record as $field => $value) $assignments[] = $field . '=' . ($value === null ? 'NULL' : "'" . $this->db->escape($value) . "'");
                $this->db->requireExec('UPDATE ai_assistant_agents SET ' . implode(',', $assignments) . ',REVISION=REVISION+1'
                    . ' WHERE ID=' . $id . ' AND REVISION=' . $revision . ' AND DELETED=0', true);
                if ($this->db->affectedRows() !== 1) throw new RuntimeException('Агент уже изменён. Обновите страницу перед сохранением.');
            }
            $this->db->requireExec("DELETE FROM ai_assistant_model_links WHERE OWNER_TYPE='agent' AND OWNER_ID=" . $id, true);
            if ($modelId > 0) $this->db->requireExec("INSERT INTO ai_assistant_model_links (MODEL_ID,OWNER_TYPE,OWNER_ID) VALUES ($modelId,'agent',$id)", true);
            $this->db->requireExec('COMMIT', true);
            return $id;
        } catch (Throwable $failure) {
            $this->db->requireExec('ROLLBACK', true);
            throw $failure;
        }
    }

    public function ragAvailability(): RagAvailability
    {
        return $this->rag ??= new RagAvailability(new KnowledgeSettings($this->db, new SecretVault()), $this->models, new QdrantClient());
    }

    private static function selection(mixed $values, array $allowed): array
    {
        if (!is_array($values) || !array_is_list($values)) throw new InvalidArgumentException('Некорректный список разрешений агента.');
        foreach ($values as $value) {
            if (!is_string($value) || !in_array($value, $allowed, true)) throw new InvalidArgumentException('Неизвестное разрешение агента.');
        }
        if (count($values) !== count(array_unique($values))) throw new InvalidArgumentException('Разрешения агента не должны повторяться.');
        return $values;
    }

    private static function decode(array $record): array
    {
        $record['TOOLS'] = json_decode($record['TOOLS'], true, 16, JSON_THROW_ON_ERROR);
        $record['SOURCES'] = json_decode($record['SOURCES'], true, 16, JSON_THROW_ON_ERROR);
        return $record;
    }
}
