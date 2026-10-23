<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use Closure;
use NeuronAI\Agent\{AgentResources, AgentState};
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Workflow\Events\Event;

/**
 * Оставляет модели время на ответ вместо бесконечного поиска до таймаута.
 *
 * Изменения:
 * 2026-10-23 — общий бюджет вызовов и завершающая генерация без инструментов.
 */
final class RunBudget extends AgentMiddleware
{
    private bool $finishing = false;
    private Closure $emit;

    public function __construct(
        private ToolAccess $access,
        private int $maximumCalls,
        private float $started,
        private int $timeout,
        callable $emit,
        private ?Closure $clock = null
    ) {
        $this->emit = Closure::fromCallable($emit);
    }

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
    {
        $now = $this->clock !== null ? ($this->clock)() : microtime(true);
        $reserve = min(30, $this->timeout * 0.2);
        if (($this->maximumCalls === 0 || $this->access->calls() < $this->maximumCalls)
            && $now - $this->started < $this->timeout - $reserve) return;

        // Убираем инструменты из следующего запроса, сохраняя уже полученные
        // результаты и обычную проверку прав внутри самих инструментов.
        foreach ($resources->tools->all() as $tool) $resources->tools->remove($tool->getName());
        if ($this->finishing) return;
        $this->finishing = true;
        $state->request->instructions->addContent(new TextContent(
            "\nИсследование завершено по бюджету вызовов или времени. Дай итоговый ответ по уже полученным данным. "
            . 'Больше не вызывай инструменты. Если сведений недостаточно, прямо укажи, чего не удалось проверить. '
            . 'Не выдумывай API, значения или результаты действий.'
        ));
        ($this->emit)(array('type' => 'status', 'state' => 'finishing', 'text' => 'Завершаю поиск и готовлю ответ по найденным данным…'));
    }
}
