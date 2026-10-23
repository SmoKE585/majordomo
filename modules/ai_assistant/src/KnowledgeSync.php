<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use MajorDoMo\Core\Core;

/**
 * Фоновая сверка справки после установки модулей и обновления системы.
 * Изменения:
 * 2026-10-23 — штатный именованный таймер объединяет серию установок в одну проверку.
 */
final class KnowledgeSync
{
    public static function schedule(int $attempt = 0): void
    {
        if (!function_exists('setTimeOut')) return;
        $settings = Core::database()->one('SELECT QDRANT_URL FROM ai_assistant_knowledge WHERE ID=1');
        if (trim((string)($settings['QDRANT_URL'] ?? '')) === '') return;
        $attempt = max(0, min(2, $attempt));
        \setTimeOut('ai_assistant.knowledge_sync', '\\MajorDoMo\\Modules\\AiAssistant\\KnowledgeSync::run(' . $attempt . ');', 30);
    }

    public static function run(int $attempt = 0): void
    {
        $db = Core::database();
        if (!(new ModuleState($db))->snapshot()['installed']) return;
        $vault = new SecretVault();
        $models = new ModelCatalog($db, $vault);
        $settings = new KnowledgeSettings($db, $vault);
        if (trim((string)$settings->get()['QDRANT_URL']) === '') return;
        $index = new KnowledgeIndex($db, $settings, $models, new ProviderFactory($models), new QdrantClient());
        $modelId = (int)$settings->get()['EMBEDDING_MODEL_ID'];
        if ($modelId < 1) return;
        $rows = array_column($index->rows(), null, 'MODEL_ID');
        $row = $rows[$modelId] ?? null;
        try {
            if ($row === null || $row['STATUS'] !== 'building'
                && ($row['STATUS'] !== 'ready' || (int)$row['ACTIVE'] !== 1 || $index->needsRebuild($modelId))) {
                $index->start($modelId);
                $row = array('STATUS' => 'building');
            }
            if ($row['STATUS'] === 'building') {
                $progress = $index->step($modelId, 16);
                // После полной сборки сверяем файлы ещё раз: они могли
                // обновиться, пока выполнялись предыдущие порции.
                if (in_array($progress['status'], array('building', 'ready'), true)) self::schedule();
            } else $index->synchronize($modelId);
        } catch (\Throwable $failure) {
            // Ошибка не отменяет обновление системы; подробности видны у индекса.
            error_log('MajorDoMo AI knowledge sync: ' . get_class($failure));
            if ($attempt < 2) self::schedule($attempt + 1);
        }
    }
}
