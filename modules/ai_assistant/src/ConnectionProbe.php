<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;

/**
 * Явная диагностика модели без данных дома и выполнения запрошенных Tools.
 *
 * Изменения:
 * 2026-10-23 — проверка чата, вызова инструмента и размерности эмбеддингов.
 */
final class ConnectionProbe
{
    public function __construct(private ProviderFactory $factory) {}

    public function check(int $modelId, string $capability): array
    {
        if (!in_array($capability, array('chat', 'tools', 'embeddings'), true)) {
            throw new \InvalidArgumentException('Неизвестная возможность модели.');
        }
        try {
            if ($capability === 'embeddings') {
                $vector = $this->factory->embeddings($modelId)->embedText('MajorDoMo connection test.');
                return array('ok' => true, 'code' => 'ok', 'message' => 'Эмбеддинги доступны.', 'dimensions' => count($vector));
            }
            $provider = $this->factory->chat($modelId, $capability === 'tools');
            if ($capability === 'tools') {
                $tool = new class extends Tool {
                    protected string $name = 'majordomo_connection_probe';
                    protected ?string $description = 'Connection check only. Call this tool with no arguments.';
                    public function __invoke(): string { return 'OK'; }
                };
                $provider->setTools(array($tool));
            }
            $message = $provider->chat(new UserMessage($capability === 'tools'
                ? 'Call majordomo_connection_probe with no arguments to test tool calling.' : 'Reply with OK only.'))->message();
            if ($capability === 'tools' && !$message instanceof ToolCallMessage) {
                return array('ok' => false, 'code' => 'tools_unconfirmed', 'message' => 'Модель ответила без вызова инструмента. Поддержка Tools не подтверждена.');
            }
            return array('ok' => true, 'code' => 'ok', 'message' => $capability === 'tools' ? 'Вызов Tools подтверждён.' : 'Чат доступен.');
        } catch (ProviderFailure $failure) {
            return array('ok' => false, 'code' => $failure->reason, 'message' => $failure->getMessage());
        } catch (\NeuronAI\Exceptions\ProviderException $failure) {
            return array('ok' => false, 'code' => 'invalid_response', 'message' => 'Ответ модели не соответствует выбранному протоколу или инструментам.');
        }
    }
}
