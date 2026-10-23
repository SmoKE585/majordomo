<?php

declare(strict_types=1);

namespace MajorDoMo\Core\Console\Commands;

use Closure;
use MajorDoMo\Core\Console\{Command, Context, Input, Output};
use MajorDoMo\Core\Core;
use MajorDoMo\Modules\AiAssistant\{KnowledgeIndex, KnowledgeSettings, ModelCatalog, ModuleState, ProviderFactory, QdrantClient, Schema, SecretVault};
use RuntimeException;
use Throwable;

/**
 * Обслуживание векторного индекса знаний без внешнего планировщика.
 *
 * Изменения:
 * 2026-10-23 — --sync обновляет только изменённые документы готового индекса.
 * 2026-10-23 — сборка по шагам, явная модель или все включённые модели.
 */
final class AiIndexCommand extends Command
{
    public function __construct(private ?Closure $indexFactory = null) {}

    public function name(): string { return 'ai:index'; }
    public function description(): string { return 'Собрать или пересобрать индекс знаний Qdrant'; }

    public function options(): array
    {
        return array(
            'model=' => 'ID модели эмбеддингов; по умолчанию выбранная в настройках',
            'all' => 'Пересобрать индексы всех включённых моделей эмбеддингов',
            'sync' => 'Сверить готовый индекс и обновить только изменённые файлы',
            'batch=' => 'Фрагментов за шаг, 1–40 (по умолчанию 16)',
        );
    }

    public function execute(Input $input, Output $output, Context $context): int
    {
        try {
            $batch = $input->integer('batch', 16, 1, 40);
            $index = $this->indexFactory !== null ? ($this->indexFactory)() : $this->index($context);
            $settings = $this->settings($context);
            $models = $this->models($context);
            $ids = array();
            if ($input->option('all')) {
                foreach ($models->embeddingModels() as $model) $ids[] = (int)$model['ID'];
            } else {
                $id = (int)$input->option('model', 0);
                if ($id < 1) $id = (int)$settings->get()['EMBEDDING_MODEL_ID'];
                if ($id < 1) throw new RuntimeException('Модель эмбеддингов не выбрана. Укажите --model=ID.');
                $ids[] = $id;
            }
            if ($ids === array()) throw new RuntimeException('Нет включённых моделей эмбеддингов.');
            $result = array();
            $failed = 0;
            foreach ($ids as $id) {
                $output->section('Модель #' . $id);
                try {
                    if ($input->option('sync')) {
                        $synced = $index->synchronize($id, $batch);
                        $output->info('Обновлено файлов: ' . $synced['changed'] . ', удалено: ' . $synced['removed']);
                        $result[] = array('model_id' => $id, 'status' => 'ready') + $synced;
                        continue;
                    }
                    $row = $index->start($id);
                    $output->info('Фрагментов в плане: ' . (int)$row['CHUNKS']);
                    $guard = 0;
                    do {
                        $row = $index->step($id, $batch);
                        $guard++;
                        if (!$output->isJson()) {
                            $output->write("\r  " . (int)$row['processed'] . '/' . (int)$row['chunks']
                                . ' (' . (int)$row['percent'] . '%)');
                        }
                        if ($guard > 50000) throw new RuntimeException('Сборка не завершилась за отведённое число шагов.');
                    } while ($row['status'] === 'building');
                    if (!$output->isJson()) $output->line();
                    if ($row['status'] !== 'ready') throw new RuntimeException((string)($row['error'] ?: 'Индекс не готов.'));
                    $output->success('Индекс готов: ' . (int)$row['chunks'] . ' фрагментов, размерность ' . (int)$row['dimension'] . '.');
                    $result[] = $row;
                } catch (Throwable $failure) {
                    $failed++;
                    $output->error($failure->getMessage());
                    $result[] = array('model_id' => $id, 'status' => 'error', 'error' => $failure->getMessage());
                }
            }
            $output->result(array('indexes' => $result, 'failed' => $failed));
            return $failed > 0 ? 1 : 0;
        } catch (Throwable $failure) {
            $output->error($failure->getMessage());
            $output->result(array('indexes' => array(), 'failed' => 1));
            return 1;
        }
    }

    private function services(Context $context): array
    {
        if (!is_file($context->root . '/modules/ai_assistant/ai_assistant.class.php')
            || !is_file($context->root . '/cms/modules_installed/ai_assistant.installed')) {
            throw new RuntimeException('ИИ-помощник не установлен. Откройте его раздел в админке для установки.');
        }
        chdir($context->root);
        require_once $context->root . '/config.php';
        require_once $context->root . '/lib/loader.php';
        $db = Core::database();
        $state = (new ModuleState($db))->snapshot();
        if (!$state['installed'] || $state['schema_version'] !== Schema::VERSION) {
            throw new RuntimeException('Откройте раздел ИИ-помощника в админке для установки или обновления схемы.');
        }
        $vault = new SecretVault();
        $models = new ModelCatalog($db, $vault);
        $settings = new KnowledgeSettings($db, $vault);
        return array($db, $models, $settings);
    }

    private function models(Context $context): ModelCatalog { return $this->services($context)[1]; }
    private function settings(Context $context): KnowledgeSettings { return $this->services($context)[2]; }

    private function index(Context $context): KnowledgeIndex
    {
        [$db, $models, $settings] = $this->services($context);
        return new KnowledgeIndex($db, $settings, $models, new ProviderFactory($models), new QdrantClient());
    }
}
