<?php

declare(strict_types=1);

namespace MajorDoMo\Core\Console\Commands;

use Closure;
use MajorDoMo\Core\Console\{Command, Context, Input, Output};
use MajorDoMo\Core\Core;
use MajorDoMo\Modules\AiAssistant\{KnowledgeSettings, ModelCatalog, ModuleState, QdrantClient, RagAvailability, Schema, SecretVault};
use RuntimeException;

/**
 * Адаптер консоли к диагностике опциональной RAG модуля ИИ-помощника.
 *
 * Изменения:
 * 2026-10-23 — проверка без установки модуля, изменения данных и отправки документов.
 */
final class AiRagCheckCommand extends Command
{
    public function __construct(private ?Closure $availabilityFactory = null) {}
    public function name(): string { return 'ai:rag-check'; }
    public function description(): string { return 'Проверить подключение Qdrant и наличие моделей эмбеддингов'; }

    public function execute(Input $input, Output $output, Context $context): int
    {
        $availability = $this->availabilityFactory !== null ? ($this->availabilityFactory)() : $this->availability($context);
        $status = $availability->status();
        $output->title('Доступность RAG');
        if ($status['available']) $output->status('OK', $status['hint']);
        else $output->warning($status['hint']);
        $output->result($status);
        return $status['available'] ? 0 : 1;
    }

    private function availability(Context $context): RagAvailability
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
        return new RagAvailability(new KnowledgeSettings($db, $vault), new ModelCatalog($db, $vault), new QdrantClient());
    }
}
