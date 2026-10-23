<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use RuntimeException;

/**
 * Одинаковая серверная проверка RAG для настройки агента и его будущего запуска.
 *
 * Изменения:
 * 2026-10-23 — отсутствие Qdrant и эмбеддингов блокирует только RAG, без скрытого fallback.
 */
final class RagAvailability
{
    public function __construct(private KnowledgeSettings $settings, private ModelCatalog $models, private QdrantClient $qdrant) {}

    public function status(): array
    {
        $status = $this->qdrant->probe($this->settings->connection());
        if (!$status['available']) return $status;
        if ($this->models->embeddingModels() === array()) {
            return array('available' => false, 'reason' => 'embeddings_missing',
                'hint' => 'Нет включённой модели эмбеддингов. Создайте модель, отметьте «Модель для эмбеддингов» и включите её провайдера. Создание и запуск агентов с RAG недоступны.');
        }
        return array('available' => true, 'reason' => 'ready', 'hint' => 'Qdrant доступен, модель эмбеддингов выбрана.');
    }

    public function requireAvailable(): void
    {
        $status = $this->status();
        if (!$status['available']) throw new RuntimeException($status['hint']);
    }
}
