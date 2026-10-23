<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use Closure;
use MajorDoMo\Core\Core;
use MajorDoMo\Core\Inspection\InstallationInspector;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use Throwable;

/**
 * Один запуск агента: история, инструменты, поток и безопасное завершение.
 *
 * Изменения:
 * 2026-10-23 — штатный approval Neuron, устойчивый контекст после обрыва и общий компонент кода.
 * 2026-10-23 — таймаут всего запуска и пульс SSE во время ожидания провайдера.
 * 2026-10-23 — Neuron Agent вместо прямого вызова провайдера; история из БД,
 * стрим, отмена через StoppableHttpClient и сохранение результата.
 */
final class AgentRunner
{
    public function __construct(
        private ModuleState $state,
        private ChatStore $chat,
        private AgentCatalog $agents,
        private ModelCatalog $models,
        private ProviderFactory $providers,
        private ?InstallationInspector $inspector = null,
        private ?Closure $currentUser = null,
        private ?AIProviderInterface $providerOverride = null
    ) {}

    /**
     * @param callable(array):void $emit события status/token/tool/tool_result
     * @return array{status:string,content:string,meta:array}
     */
    public function run(string $runId, int $userId, callable $emit): array
    {
        $started = microtime(true);
        $text = '';
        $tools = array();
        $sources = array();
        $usage = array('input_tokens' => 0, 'output_tokens' => 0);
        $lastCheck = 0.0;
        $stop = false;
        $final = null;
        $dialog = null;
        $owned = false;

        try {
            $run = $this->chat->run($runId, $userId);
            $dialog = $this->chat->dialog((int)$run['DIALOG_ID'], $userId);
            $owned = true;
            $agentId = (int)($run['AGENT_ID'] ?: $dialog['AGENT_ID']);
            $this->state->requireEnabled();
            $agentRecord = $this->agents->get($agentId);
            if ((int)$agentRecord['ENABLED'] !== 1) throw new ProviderFailure('agent_disabled', 'Агент выключен. Включите его в настройках.');
            $modelId = (int)($agentRecord['MODEL_ID'] ?? 0);
            if ($modelId < 1) throw new ProviderFailure('model_missing', 'Агенту не назначена модель. Выберите модель в настройках агента.');
            $model = $this->models->model($modelId);

            $access = new ToolAccess($this->state, $this->agents, $agentId, $userId,
                $this->currentUser ?? static function (): int {
                    $session = Core::session();
                    if ($session->user() === null) return 0;
                    return (int)((new \MajorDoMo\Core\Auth\Authentication($session, Core::users()))->current()['id'] ?? 0);
                },
                function () use ($runId): bool { return $this->chat->isCancelled($runId); });
            $lastPulse = 0.0;
            $shouldStop = function () use ($runId, &$lastCheck, &$stop, &$lastPulse, $started, $agentRecord, $emit): bool {
                // Проверка отмены не должна превращаться в запрос к БД на каждый токен.
                $now = microtime(true);
                if ($now - $lastCheck >= 0.5 || $lastCheck === 0.0) {
                    $lastCheck = $now;
                    $stop = $this->chat->isCancelled($runId);
                }
                if ($now - $lastPulse >= 5) {
                    $lastPulse = $now;
                    $this->chat->heartbeat($runId);
                    $emit(array('type' => 'heartbeat'));
                }
                if ($now - $started >= (int)$agentRecord['TIMEOUT']) {
                    throw new ProviderFailure('timeout', 'Истёк лимит времени агента. Можно повторить запрос или увеличить лимит в настройках.');
                }
                return $stop;
            };
            $registry = new ToolRegistry($access, new KnowledgeReader(), $this->inspector, null, $shouldStop);
            $toolList = $agentRecord['TOOLS'] === array() ? array() : $registry->tools();
            $provider = $this->providerOverride ?? $this->providers->chat($modelId, $toolList !== array(), $shouldStop);
            if ($this->providerOverride !== null && $toolList !== array()) {
                // FakeAIProvider и подобные подмены тоже должны видеть инструменты.
                $provider = $provider;
            }
            $instructions = $this->instructions($agentRecord, $dialog);

            $agent = Agent::make($runId)
                ->setThreadId($runId)
                ->setAiProvider($provider)
                ->setInstructions($instructions)
                ->setTools($toolList)
                ->setContextWindow(max(1024, (int)$model['CONTEXT_WINDOW']))
                ->toolMaxRuns(max(1, min(10, (int)$agentRecord['MAX_STEPS'])))
                ->setMaxSteps(max(8, min(64, (int)$agentRecord['MAX_STEPS'] * 3 + 4)))
                ->toolErrorHandler(static fn(Throwable $failure, $call): string =>
                    'Инструмент ' . $call->getName() . ' недоступен: ' . $failure->getMessage());
            $agent->subscribe(InferenceStop::class, static function (InferenceStop $event) use (&$usage): void {
                $value = $event->response->message()->getUsage();
                if ($value === null) return;
                $usage['input_tokens'] += $value->inputTokens;
                $usage['output_tokens'] += $value->outputTokens;
            });

            $history = $this->history($dialog, $userId, (int)$model['CONTEXT_WINDOW'], $runId);
            $emit(array('type' => 'status', 'state' => 'thinking', 'text' => 'Модель готовит ответ…'));
            $stream = $agent->stream($history);
            foreach ($stream as $item) {
                if ($stop) break;
                if ($item instanceof TextChunk) {
                    $text .= $item->content;
                    $emit(array('type' => 'token', 'text' => $item->content));
                } elseif ($item instanceof ToolCallChunk) {
                    $call = $item->tool;
                    $entry = array('name' => $call->getName(), 'status' => 'running',
                        'arguments' => SensitiveData::text(mb_substr(json_encode($call->getInputs(), JSON_UNESCAPED_UNICODE) ?: '', 0, 400)));
                    $tools[] = $entry;
                    if (count($tools) > 30) array_shift($tools);
                    $emit(array('type' => 'tool', 'tool' => $entry));
                } elseif ($item instanceof ToolResultChunk) {
                    $call = $item->tool;
                    $result = $call->getResult();
                    if ($result instanceof \NeuronAI\Tools\ToolOutput) $result = $result->getText();
                    $result = is_string($result) ? $result : (string)json_encode($result, JSON_UNESCAPED_UNICODE);
                    $summary = SensitiveData::text(mb_substr($result, 0, 400));
                    for ($i = count($tools) - 1; $i >= 0; $i--) {
                        if ($tools[$i]['name'] === $call->getName() && $tools[$i]['status'] === 'running') {
                            $tools[$i]['status'] = 'done';
                            $tools[$i]['result'] = $summary;
                            break;
                        }
                    }
                    $this->collectSources($result, $sources);
                    $emit(array('type' => 'tool_result', 'name' => $call->getName(), 'summary' => $summary));
                }
            }
            $stop = $stop || $this->chat->isCancelled($runId);
            if (!$stop) $final = $stream->getReturn();
            if ($stop) {
                $this->chat->finishRun($runId, 'cancelled');
                $message = $this->chat->appendMessage((int)$dialog['ID'], $userId, 'assistant', $text,
                    array('cancelled' => true, 'tools' => $tools, 'sources' => $sources, 'usage' => $usage,
                        'duration_ms' => (int)((microtime(true) - $started) * 1000)));
                $emit(array('type' => 'done', 'status' => 'cancelled', 'message' => $message));
                return array('status' => 'cancelled', 'content' => $text, 'meta' => array());
            }

            if ($final?->isInterrupted()) {
                $requests = new ActionRequests(Core::database(), $this->chat, $this->agents, $this->state);
                $actions = array();
                $pending = $agent->pendingApprovals();
                if (count($pending) > min(10, (int)$agentRecord['MAX_STEPS'])) throw new ProviderFailure('approval_limit', 'Слишком много предложений за один запрос. Разбейте задачу на части.');
                foreach ($pending as $action) {
                    if ($action->name !== 'create_entity') throw new ProviderFailure('approval', 'Неизвестный инструмент подтверждения.');
                    $actions[] = $requests->propose((int)$dialog['ID'], $userId, $agentId, $action->inputs);
                }
                if ($actions === array()) throw new ProviderFailure('approval', 'Не удалось подготовить предложение действия.');
                $content = trim($text) ?: 'Предложение подготовлено. Проверьте параметры и подтвердите создание кнопкой ниже.';
                $meta = array('tools' => $tools, 'sources' => array_values($sources), 'actions' => array_column($actions, 'id'));
                $messageId = $this->chat->appendMessage((int)$dialog['ID'], $userId, 'assistant', $content, $meta);
                $this->chat->finishRun($runId, 'done');
                $emit(array('type' => 'done', 'status' => 'approval', 'message' => $messageId,
                    'html' => self::render($content), 'actions' => $actions, 'sources' => array_values($sources)));
                return array('status' => 'approval', 'content' => $content, 'meta' => $meta);
            }
            $message = $final?->getMessage();
            $content = trim((string)($message?->getContent() ?? ''));
            if ($content === '') $content = $text;
            $meta = array(
                'model' => SensitiveData::text((string)$model['MODEL']),
                'tools' => $tools,
                'sources' => array_values($sources),
                'usage' => $usage,
                'stop_reason' => $message instanceof AssistantMessage ? $message->stopReason() : null,
                'duration_ms' => (int)((microtime(true) - $started) * 1000),
            );
            $messageId = $this->chat->appendMessage((int)$dialog['ID'], $userId, 'assistant', $content, $meta);
            $this->chat->finishRun($runId, 'done');
            $this->maybeTitle($dialog, $userId);
            $emit(array('type' => 'done', 'status' => 'done', 'message' => $messageId,
                'html' => self::render($content), 'sources' => array_values($sources), 'usage' => $usage, 'tools' => $tools));
            return array('status' => 'done', 'content' => $content, 'meta' => $meta);
        } catch (Throwable $failure) {
            $cancelled = $stop || ($owned && $this->chat->isCancelled($runId));
            if ($failure instanceof \NeuronAI\Exceptions\HttpException) $failure = ProviderClient::safeFailure($failure);
            if ($failure instanceof \NeuronAI\Exceptions\ProviderException) {
                $failure = new ProviderFailure('stream_interrupted', 'Провайдер прервал поток ответа. Полученная часть сохранена; запрос можно повторить.');
            }
            $reason = $failure instanceof ProviderFailure ? $failure->reason : 'failed';
            $error = $failure instanceof ProviderFailure
                ? $failure->getMessage()
                : (($failure instanceof \RuntimeException && $failure->getMessage() !== '')
                    ? $failure->getMessage()
                    : 'Не удалось получить ответ модели. Проверьте настройки и повторите запрос.');
            // В системный журнал не пишем ни запрос, ни ответ, ни токен.
            error_log('MajorDoMo AI run failed: ' . get_class($failure));
            $partial = trim($text);
            if ($partial !== '' && $dialog !== null) {
                $this->chat->appendMessage((int)$dialog['ID'], $userId, 'assistant', $partial,
                    array('error' => $cancelled ? '' : $error, 'incomplete' => true, 'cancelled' => $cancelled,
                        'tools' => $tools, 'sources' => $sources));
            }
            if ($owned) $this->chat->finishRun($runId, $cancelled ? 'cancelled' : 'error', $cancelled ? '' : $error);
            if ($cancelled) {
                $emit(array('type' => 'done', 'status' => 'cancelled', 'message' => 0));
                return array('status' => 'cancelled', 'content' => $partial, 'meta' => array('cancelled' => true));
            }
            $emit(array('type' => 'error', 'reason' => $reason, 'message' => $error));
            return array('status' => 'error', 'content' => $partial, 'meta' => array('error' => $error));
        }
    }

    private function history(array $dialog, int $userId, int $contextWindow, string $runId): array
    {
        $rows = $this->chat->messages((int)$dialog['ID'], $userId, 0, 300, true);
        foreach ($rows as $index => $row) {
            if ($row['ROLE'] === 'user' && ($row['META']['run_id'] ?? '') === $runId) {
                // Ответ остановленного запроса может дописаться позже нового
                // вопроса. Контекст запуска заканчивается именно его вопросом.
                $rows = array_slice($rows, 0, $index + 1);
                break;
            }
        }
        while ($rows !== array() && end($rows)['ROLE'] !== 'user') array_pop($rows);
        $budget = max(12000, min(150000, (int)($contextWindow * 3 * 0.6)));
        $messages = array();
        $used = 0;
        foreach (array_reverse($rows) as $row) {
            $content = (string)$row['CONTENT'];
            if ($content === '' || !in_array($row['ROLE'], array('user', 'assistant'), true)) continue;
            $size = mb_strlen($content);
            if ($used + $size > $budget && $messages !== array()) break;
            array_unshift($messages, $row['ROLE'] === 'user' ? new UserMessage($content) : new AssistantMessage($content));
            $used += $size;
        }
        // После сетевой ошибки возможны два вопроса подряд, после подтверждения
        // — два ответа. Neuron требует чередования ролей: объединяем только
        // контекст модели, не переписывая сохранённую историю пользователя.
        while ($messages !== array() && !$messages[0] instanceof UserMessage) array_shift($messages);
        $normalized = array();
        foreach ($messages as $message) {
            $last = count($normalized) - 1;
            if ($last >= 0 && get_class($normalized[$last]) === get_class($message)) {
                $content = $normalized[$last]->getContent() . "\n\n" . $message->getContent();
                $normalized[$last] = $message instanceof UserMessage ? new UserMessage($content) : new AssistantMessage($content);
            } else $normalized[] = $message;
        }
        if ($normalized === array()) throw new ProviderFailure('empty_dialog', 'В диалоге нет сообщений для ответа.');
        return $normalized;
    }

    private function instructions(array $agent, array $dialog): string
    {
        $instructions = (string)$agent['INSTRUCTIONS'] . "\n\n" . (string)file_get_contents(dirname(__DIR__) . '/prompts/system.md');
        $context = $dialog['CONTEXT'] ?? array();
        if (!is_array($context) || $context === array()) return $instructions;
        $lines = array('', 'КОНТЕКСТ РЕДАКТОРА (проверен сервером; код ниже — черновик пользователя, он ещё не сохранён):');
        foreach (array('object' => 'Объект', 'class' => 'Класс', 'method' => 'Метод', 'script' => 'Сценарий',
                     'language' => 'Язык', 'tab' => 'Вкладка') as $key => $label) {
            if (!empty($context[$key]) && is_string($context[$key])) $lines[] = $label . ': ' . $context[$key];
        }
        if (!empty($context['selection']) && is_string($context['selection'])) {
            $lines[] = 'Выделение: ' . mb_substr($context['selection'], 0, 4000);
        }
        if (!empty($context['code']) && is_string($context['code'])) {
            $lines[] = "Текущий текст редактора:\n```" . (string)($context['language'] ?? '') . "\n"
                . mb_substr($context['code'], 0, 60000) . "\n```";
        }
        $lines[] = 'Отвечай так, чтобы предложенный код можно было вставить в редактор. Не утверждай, что изменения сохранены.';
        // Серверная сверка сущностей: контекст браузера не должен подменять объект.
        try {
            $inspector = $this->inspector ?? new InstallationInspector(Core::database());
            if (!empty($context['object'])) {
                $details = $inspector->objectDetails((string)$context['object']);
                $lines[] = 'Фактический объект: ' . $details['title'] . ' · класс ' . $details['class']
                    . ' · ' . $details['url'];
                if (!empty($context['method'])) {
                    $code = $inspector->methodCode((string)$context['object'], (string)$context['method'], 0, 12000);
                    if ($code['parent_code'] !== '') {
                        $lines[] = "Код родительского метода (не изменять, только учитывать поведение):\n```php\n"
                            . $code['parent_code'] . "\n```";
                    }
                }
            } elseif (!empty($context['class']) && !empty($context['method'])) {
                $code = $inspector->methodCode('', (string)$context['method'], 0, 12000, (string)$context['class']);
                if ($code['parent_code'] !== '') {
                    $lines[] = "Код родительского метода:\n```php\n" . $code['parent_code'] . "\n```";
                }
            } elseif (!empty($context['script'])) {
                $script = $inspector->scriptCode((string)$context['script'], 0, 2000);
                $lines[] = 'Фактический сценарий: ' . $script['title'] . ' · ' . $script['url'];
            }
        } catch (Throwable) {
            // Черновик может ссылаться на ещё не сохранённую сущность; это не повод терять запрос.
        }
        return $instructions . implode("\n", $lines);
    }

    private function collectSources(string $json, array &$sources): void
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        if (!is_array($data)) return;
        $candidates = array();
        if (isset($data['items']) && is_array($data['items'])) $candidates = $data['items'];
        elseif (isset($data['url'])) $candidates = array($data);
        foreach ($candidates as $item) {
            if (!is_array($item)) continue;
            $url = isset($item['url']) && is_string($item['url']) ? $item['url'] : '';
            $title = isset($item['title']) && is_string($item['title']) ? $item['title'] : '';
            if ($url === '' && $title === '') continue;
            $key = $url . '|' . $title;
            if (isset($sources[$key])) continue;
            $sources[$key] = array('title' => SensitiveData::text($title), 'url' => $url);
        }
        if (count($sources) > 20) $sources = array_slice($sources, -20, null, true);
    }

    private function maybeTitle(array $dialog, int $userId): void
    {
        if ((string)$dialog['TITLE'] !== 'Новый диалог') return;
        $rows = $this->chat->messages((int)$dialog['ID'], $userId, 0, 1);
        if ($rows === array()) return;
        $title = trim(preg_replace('/\s+/u', ' ', (string)$rows[0]['CONTENT']) ?? '');
        if ($title !== '') $this->chat->renameDialog((int)$dialog['ID'], $userId, mb_substr($title, 0, 80));
    }

    /** Безопасный Markdown для готового ответа; во время стрима текст показывается как есть. */
    public static function render(string $content): string
    {
        require_once dirname(__DIR__, 3) . '/lib/markdown.php';
        require_once dirname(__DIR__, 3) . '/lib/ui/MDJUI.php';
        $renderer = new \MDMarkdownRenderer('ai_assistant', array(), '', defined('ROOTHTML') ? ROOTHTML : '/');
        $html = $renderer->render($content)['html'];
        // Рендерер уже обезвредил HTML модели. Меняем только его стандартные
        // блоки кода на общий компонент с подсветкой и копированием.
        return preg_replace_callback('~<pre class="md-prose__pre"><code(?: class="language-([^"]*)")?>(.*?)</code></pre>~s',
            static fn(array $match): string => \MDJUI::codeSample(array(
                'language' => html_entity_decode($match[1] ?: 'text', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'code' => html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            )), $html) ?? $html;
    }
}
