<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use InvalidArgumentException;
use MajorDoMo\Core\Config;
use MajorDoMo\Core\Core;
use MajorDoMo\Core\Database;
use MajorDoMo\Core\Inspection\InstallationInspector;
use RuntimeException;
use Throwable;

/**
 * Контроллер раздела «ИИ-помощник»: настройки, чат и служебные AJAX-операции.
 *
 * Изменения:
 * 2026-10-23 — компактный drawer, состояние запуска, подтверждения и защита аварийного завершения.
 * 2026-10-23 — единый экран вместо заглушки: провайдеры, модели, агенты,
 * знания, диагностика, чат с потоковым ответом и интеграция редактора.
 */
final class PanelController
{
    private const VIEWS = array('chat', 'agents', 'agent_edit', 'providers', 'provider_edit',
        'models', 'model_edit', 'knowledge', 'diagnostics');

    private Database $db;
    private ModuleState $state;
    private SecretVault $vault;
    private ModelCatalog $models;
    private AgentCatalog $agents;
    private KnowledgeSettings $knowledgeSettings;
    private ChatStore $chatStore;
    private ProviderFactory $providers;
    private AgentRunner $runner;
    private InstallationInspector $inspector;
    private KnowledgeIndex $index;
    private ?AgentRunner $runnerOverride;
    private bool $sqlite;
    private int $userId = 0;
    private string $view = 'chat';
    private int $id = 0;
    private int $dialogId = 0;
    private string $token = '';
    private string $error = '';
    private string $success = '';
    private array $posted = array();

    public function __construct(Database $db, ?ModuleState $state = null, bool $sqlite = false, ?AgentRunner $runner = null)
    {
        $this->db = $db;
        $this->state = $state ?? new ModuleState($db);
        $this->sqlite = $sqlite;
        $this->vault = new SecretVault();
        $this->models = new ModelCatalog($db, $this->vault);
        $this->agents = new AgentCatalog($db, $this->models);
        $this->knowledgeSettings = new KnowledgeSettings($db, $this->vault);
        $this->chatStore = new ChatStore($db);
        $this->providers = new ProviderFactory($this->models);
        $this->inspector = new InstallationInspector($db);
        $this->index = new KnowledgeIndex($db, $this->knowledgeSettings, $this->models, $this->providers, new QdrantClient());
        $this->runnerOverride = $runner;
        $this->runner = $runner ?? new AgentRunner($this->state, $this->chatStore, $this->agents,
            $this->models, $this->providers, $this->inspector);
    }

    public function handle(): string
    {
        $session = Core::session();
        $user = $session->user();
        if ($user === null) {
            http_response_code(403);
            return '';
        }
        $this->userId = (int)$user['id'];
        if (!$session->has('ai_assistant_csrf')) $session->set('ai_assistant_csrf', bin2hex(random_bytes(32)));
        $this->token = (string)$session->get('ai_assistant_csrf');

        if (($_GET['ajax'] ?? '') === '1') $this->handleAjax();
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') $this->handlePost();
        $this->resolveView();
        $this->success = $this->query('ok_msg');
        return $this->render();
    }

    private function resolveView(): void
    {
        $view = $this->query('view_mode');
        $this->view = in_array($view, self::VIEWS, true) ? $view : 'chat';
        $this->id = max(0, (int)$this->query('id'));
        $this->dialogId = max(0, (int)($this->query('dialog_id') ?: $this->query('id')));
        if ($this->view === 'chat' && $this->id > 0 && $this->dialogId === 0) $this->dialogId = $this->id;
    }

    private function render(): string
    {
        if ($this->view === 'chat') return $this->renderChat();
        $data = match ($this->view) {
            'agents' => $this->agentsData(),
            'agent_edit' => $this->agentEditData(),
            'providers' => $this->providersData(),
            'provider_edit' => $this->providerEditData(),
            'models' => $this->modelsData(),
            'model_edit' => $this->modelEditData(),
            'knowledge' => $this->knowledgeData(),
            'diagnostics' => $this->diagnosticsData(),
            default => array(),
        };
        $data['view'] = $this->view;
        $data['token'] = $this->token;
        $data['error'] = $this->error;
        $data['success'] = $this->success;
        $data['moduleEnabled'] = $this->state->snapshot()['enabled'];
        $data['configured'] = $this->isConfigured();
        $data['url'] = $this->urlPrefix();
        $data['csrf'] = Core::session()->csrfToken();
        extract($data, EXTR_SKIP);
        ob_start();
        $panel = $this;
        require ROOT . 'templates/ai_assistant/ai_assistant.php';
        return (string)ob_get_clean();
    }

    private function renderChat(): string
    {
        $dialogs = $this->chatStore->listDialogs($this->userId, 50);
        if ($this->query('op') === 'drawer') {
            $dialogs = array_values(array_filter($dialogs, static fn(array $dialog): bool => $dialog['CONTEXT_TYPE'] === 'chat'));
        }
        $dialog = null;
        $messages = array();
        if ($this->dialogId > 0) {
            try {
                $dialog = $this->chatStore->dialog($this->dialogId, $this->userId);
                $messages = $this->chatStore->messages($this->dialogId, $this->userId, 0, 300);
            } catch (InvalidArgumentException $failure) {
                $this->error = $failure->getMessage();
                $this->dialogId = 0;
            }
        }
        if ($dialog === null && $dialogs !== array()) {
            $this->dialogId = (int)$dialogs[0]['ID'];
            $dialog = $this->chatStore->dialog($this->dialogId, $this->userId);
            $messages = $this->chatStore->messages($this->dialogId, $this->userId, 0, 300);
        }
        $agents = array();
        foreach ($this->agents->all() as $agent) {
            $model = null;
            if ((int)$agent['MODEL_ID'] > 0) {
                try { $model = $this->models->model((int)$agent['MODEL_ID']); } catch (Throwable) { $model = null; }
            }
            $agent['MODEL_TITLE'] = $model['TITLE'] ?? '';
            $agent['READY'] = (int)$agent['ENABLED'] === 1 && $model !== null;
            $agents[] = $agent;
        }
        $data = array(
            'view' => 'chat', 'token' => $this->token, 'error' => $this->error, 'success' => $this->success,
            'moduleEnabled' => $this->state->snapshot()['enabled'], 'url' => $this->urlPrefix(),
            'configured' => $this->isConfigured(), 'csrf' => Core::session()->csrfToken(), 'dialogs' => $dialogs, 'dialog' => $dialog,
            'messages' => $messages, 'agents' => $agents, 'dialogId' => $this->dialogId,
            'drawer' => $this->query('op') === 'drawer',
            'actions' => $this->dialogId > 0 ? $this->actions()->forDialog($this->dialogId, $this->userId) : array(),
        );
        extract($data, EXTR_SKIP);
        ob_start();
        $panel = $this;
        require ROOT . 'templates/ai_assistant/ai_assistant.php';
        return (string)ob_get_clean();
    }

    private function agentsData(): array
    {
        $rows = array();
        foreach ($this->agents->all() as $agent) {
            $model = null;
            if ((int)$agent['MODEL_ID'] > 0) {
                try { $model = $this->models->model((int)$agent['MODEL_ID']); } catch (Throwable) { $model = null; }
            }
            $agent['MODEL_TITLE'] = $model['TITLE'] ?? '';
            $rows[] = $agent;
        }
        return array('agents' => $rows, 'profiles' => array('assistant' => 'Помощник MajorDoMo',
            'editor' => 'Помощник редактора', 'custom' => 'Свой агент'));
    }

    private function agentEditData(): array
    {
        $agent = null;
        if ($this->id > 0) {
            $agent = $this->agents->get($this->id);
        }
        $modelOptions = array('' => 'Без модели');
        foreach ($this->models->models() as $model) {
            $ready = (int)$model['ENABLED'] === 1 && in_array('chat', $model['CAPABILITIES'], true);
            $modelOptions[(string)$model['ID']] = $model['TITLE'] . ' · ' . $model['MODEL'] . ($ready ? '' : ' (выключена)');
        }
        return array('agent' => $agent, 'modelOptions' => $modelOptions,
            'modelsAvailable' => count($modelOptions) > 1,
            'allTools' => $this->toolLabels(), 'ragStatus' => $this->safeRagStatus(),
            'profiles' => array('assistant' => 'Помощник MajorDoMo', 'editor' => 'Помощник редактора', 'custom' => 'Свой агент'));
    }

    private function providersData(): array
    {
        $providers = $this->models->providers();
        $counts = array();
        foreach ($this->models->models() as $model) $counts[(int)$model['PROVIDER_ID']] = ($counts[(int)$model['PROVIDER_ID']] ?? 0) + 1;
        foreach ($providers as &$provider) $provider['MODELS'] = $counts[(int)$provider['ID']] ?? 0;
        unset($provider);
        return array('providers' => $providers);
    }

    private function providerEditData(): array
    {
        return array('provider' => $this->id > 0 ? $this->models->provider($this->id) : null);
    }

    private function modelsData(): array
    {
        $providers = array_column($this->models->providers(), 'TITLE', 'ID');
        $rows = $this->models->models();
        foreach ($rows as &$model) $model['PROVIDER_TITLE'] = $providers[(int)$model['PROVIDER_ID']] ?? '';
        unset($model);
        return array('models' => $rows, 'providersCount' => count($providers));
    }

    private function modelEditData(): array
    {
        $model = null;
        if ($this->id > 0) $model = $this->models->model($this->id);
        $options = array();
        foreach ($this->models->providers() as $provider) $options[(string)$provider['ID']] = $provider['TITLE'];
        return array('model' => $model, 'providerOptions' => $options,
            'providersAvailable' => $options !== array());
    }

    private function knowledgeData(): array
    {
        return array(
            'settings' => $this->knowledgeSettings->get(),
            'indexes' => $this->index->rows(),
            'embeddingModels' => $this->models->embeddingModels(),
            'ragStatus' => $this->safeRagStatus(),
            'documents' => $this->knowledgeStats(),
        );
    }

    private function diagnosticsData(): array
    {
        $runs = $this->db->select('SELECT ID,STATUS,ERROR,UPDATED_AT FROM ai_assistant_runs ORDER BY UPDATED_AT DESC LIMIT 20');
        $embeddingCount = 0;
        $modelTitles = array();
        foreach ($this->models->models() as $model) {
            $modelTitles[(int)$model['ID']] = $model['TITLE'];
            if (in_array('embeddings', $model['CAPABILITIES'], true)) $embeddingCount++;
        }
        return array(
            'state' => $this->state->snapshot(),
            'configured' => $this->isConfigured(),
            'schema' => Schema::VERSION,
            'providers' => count($this->models->providers()),
            'models' => $this->models->models(),
            'modelTitles' => $modelTitles,
            'profiles' => array('assistant' => 'Помощник MajorDoMo', 'editor' => 'Помощник редактора', 'custom' => 'Свой агент'),
            'embeddingCount' => $embeddingCount,
            'agents' => $this->agents->all(),
            'ragStatus' => $this->safeRagStatus(),
            'runs' => $runs,
            'indexes' => $this->index->rows(),
            'dialogs' => $this->db->one('SELECT COUNT(*) AS C FROM ai_assistant_dialogs WHERE DELETED=0')['C'] ?? 0,
            'messages' => $this->db->one('SELECT COUNT(*) AS C FROM ai_assistant_messages')['C'] ?? 0,
        );
    }

    /** Готовность «из коробки»: провайдер, модель и агент с моделью. */
    private function isConfigured(): bool
    {
        if ($this->models->providers() === array() || $this->models->models() === array()) return false;
        foreach ($this->agents->all() as $agent) {
            if ((int)$agent['ENABLED'] === 1 && (int)$agent['MODEL_ID'] > 0) return true;
        }
        return false;
    }

    private function safeRagStatus(): array
    {        try {
            return (new RagAvailability($this->knowledgeSettings, $this->models, new QdrantClient()))->status();
        } catch (Throwable $failure) {
            return array('available' => false, 'reason' => 'failed', 'hint' => $failure->getMessage());
        }
    }

    private function knowledgeStats(): array
    {
        try {
            require_once dirname(__DIR__, 3) . '/lib/knowledge_base.php';
            $installed = static fn(string $module): bool => (bool)\isModuleInstalled($module);
            $registry = new \MDKnowledgeBase(Config::root() . '/docs', defined('ROOTHTML') ? ROOTHTML : '/',
                \MDKnowledgeBase::moduleSources(Config::root() . '/modules', $installed));
            $stats = $registry->stats();
            return array('documents' => (int)($stats['total'] ?? 0), 'words' => 0,
                'modules' => (int)($stats['modules'] ?? 0), 'categories' => (int)($stats['categories'] ?? 0));
        } catch (Throwable) {
            return array('documents' => 0, 'words' => 0);
        }
    }

    private function toolLabels(): array
    {
        return array(
            'find_objects' => 'Поиск объектов',
            'read_object' => 'Карточка объекта',
            'read_property' => 'Свойство объекта',
            'find_references' => 'Использование и привязки',
            'read_code' => 'Код метода или сценария',
            'read_history' => 'История изменения',
            'find_scripts' => 'Поиск сценариев',
            'search_knowledge' => 'Поиск по базе знаний',
            'read_knowledge' => 'Чтение статьи',
            'create_entity' => 'Создание с подтверждением',
        );
    }

    // ---------------------------------------------------------------- POST

    private function handlePost(): void
    {
        $this->posted = $_POST;
        try {
            if (!hash_equals($this->token, $this->post('_token'))) throw new InvalidArgumentException('Форма устарела. Обновите страницу.');
            $operation = $this->post('operation');
            match ($operation) {
                'save_provider' => $this->saveProvider(),
                'delete_provider' => $this->deleteProvider(),
                'save_model' => $this->saveModel(),
                'delete_model' => $this->deleteModel(),
                'save_agent' => $this->saveAgent(),
                'copy_agent' => $this->copyAgent(),
                'delete_agent' => $this->deleteAgent(),
                'restore_instructions' => $this->restoreAgent(),
                'save_qdrant' => $this->saveQdrant(),
                'save_index_settings' => $this->saveIndexSettings(),
                'save_knowledge' => $this->saveKnowledge(),
                'delete_index' => $this->deleteIndex(),
                default => throw new InvalidArgumentException('Неизвестное действие.'),
            };
        } catch (Throwable $failure) {
            $this->error = $failure->getMessage();
        }
    }

    private function saveProvider(): void
    {
        $id = max(0, (int)$this->post('id'));
        $this->models->saveProvider($id, array(
            'TITLE' => $this->post('title'), 'BASE_URL' => $this->post('base_url'),
            'PROTOCOL' => $this->post('protocol'), 'TIMEOUT' => $this->post('timeout'),
            'ENABLED' => $this->post('enabled') === '1' ? 1 : 0,
        ));
        $this->redirect($this->urlPrefix() . '/providers.html', 'Провайдер сохранён.');
    }

    private function deleteProvider(): void
    {
        if ($this->post('confirm_delete') !== '1') throw new InvalidArgumentException('Подтвердите удаление провайдера.');
        $this->models->deleteProvider(max(0, (int)$this->post('id')));
        $this->redirect($this->urlPrefix() . '/providers.html', 'Провайдер удалён.');
    }

    private function saveModel(): void
    {
        $id = max(0, (int)$this->post('id'));
        $parameters = array();
        foreach (array('temperature' => 2, 'top_p' => 1) as $name => $maximum) {
            $value = $this->post($name);
            if ($value === '') continue;
            $parameters[$name] = (float)str_replace(',', '.', $value);
        }
        $capabilities = array_values(array_intersect(array('chat', 'tools', 'embeddings'), $this->postArray('capabilities')));
        $input = array(
            'PROVIDER_ID' => $this->post('provider_id'), 'TITLE' => $this->post('title'),
            'MODEL' => $this->post('model'), 'CAPABILITIES' => $capabilities, 'PARAMETERS' => $parameters,
            'CONTEXT_WINDOW' => $this->post('context_window'), 'MAX_OUTPUT' => $this->post('max_output'),
            'ENABLED' => $this->post('enabled') === '1' ? 1 : 0,
            'EMBEDDING_PRIORITY' => $this->post('embedding_priority') === '' ? 100 : $this->post('embedding_priority'),
        );
        $input['TOKEN'] = $this->post('token');
        if ($input['TOKEN'] === '' && $this->post('clear_token') !== '1') unset($input['TOKEN']);
        if ($this->post('clear_token') === '1') $input['TOKEN'] = '';
        $this->models->saveModel($id, $input);
        $this->redirect($this->urlPrefix() . '/models.html', 'Модель сохранена.');
    }

    private function deleteModel(): void
    {
        if ($this->post('confirm_delete') !== '1') throw new InvalidArgumentException('Подтвердите удаление модели.');
        $this->models->deleteModel(max(0, (int)$this->post('id')));
        $this->redirect($this->urlPrefix() . '/models.html', 'Модель удалена.');
    }

    private function saveAgent(): void
    {
        $id = max(0, (int)$this->post('id'));
        $input = array(
            'TITLE' => $this->post('title'),
            'MODEL_ID' => $this->post('model_id') === '' ? 0 : $this->post('model_id'),
            'INSTRUCTIONS' => $this->post('instructions'),
            'TOOLS' => $this->postArray('tools'),
            'SOURCES' => $this->postArray('sources'),
            'KNOWLEDGE_MODE' => $this->post('knowledge_mode') ?: 'text',
            'MAX_STEPS' => $this->post('max_steps'), 'TIMEOUT' => $this->post('timeout'),
            'ENABLED' => $this->post('enabled') === '1' ? 1 : 0,
            'REVISION' => $this->post('revision') ?: 1,
        );
        $saved = $this->agents->save($id, $input);
        $this->redirect($this->urlPrefix() . '/agents/' . $saved . '.html', 'Агент сохранён.');
    }

    private function copyAgent(): void
    {
        $id = max(0, (int)$this->post('id'));
        $copy = $this->agents->copy($id, $this->post('title') ?: 'Копия агента');
        $this->redirect($this->urlPrefix() . '/agents/' . $copy . '.html', 'Агент скопирован.');
    }

    private function deleteAgent(): void
    {
        if ($this->post('confirm_delete') !== '1') throw new InvalidArgumentException('Подтвердите удаление агента.');
        $this->agents->delete(max(0, (int)$this->post('id')), max(1, (int)$this->post('revision')));
        $this->redirect($this->urlPrefix() . '/agents.html', 'Агент удалён. История диалогов сохранена.');
    }

    private function restoreAgent(): void
    {
        $id = max(0, (int)$this->post('id'));
        $this->agents->restoreInstructions($id, max(1, (int)$this->post('revision')));
        $this->redirect($this->urlPrefix() . '/agents/' . $id . '.html', 'Стандартные инструкции восстановлены.');
    }

    private function saveQdrant(): void
    {
        $current = $this->knowledgeSettings->get();
        $input = array('QDRANT_URL' => $this->post('qdrant_url'), 'TIMEOUT' => $this->post('timeout'),
            'REVISION' => $current['REVISION'], 'TOKEN' => $this->post('token'));
        $this->knowledgeSettings->save($input);
        $this->redirect($this->urlPrefix() . '/knowledge.html', 'Подключение Qdrant сохранено.');
    }

    private function saveIndexSettings(): void
    {
        $current = $this->knowledgeSettings->get();
        $this->knowledgeSettings->saveIndex(array(
            'EMBEDDING_MODEL_ID' => $this->post('embedding_model_id') === '' ? 0 : $this->post('embedding_model_id'),
            'CHUNK_SIZE' => $this->post('chunk_size'), 'CHUNK_OVERLAP' => $this->post('chunk_overlap'),
            'TOP_K' => $this->post('top_k'), 'MAX_CONTEXT' => $this->post('max_context'),
            'REVISION' => $current['REVISION'],
        ), $this->models);
        $this->redirect($this->urlPrefix() . '/knowledge.html', 'Параметры индекса сохранены.');
    }

    /** Одна кнопка сохраняет Qdrant, модель и параметры коллекции. */
    private function saveKnowledge(): void
    {
        $current = $this->knowledgeSettings->get();
        $input = array(
            'QDRANT_URL' => $this->post('qdrant_url'), 'TIMEOUT' => $this->post('timeout'),
            'EMBEDDING_MODEL_ID' => $this->post('embedding_model_id') === '' ? 0 : $this->post('embedding_model_id'),
            'CHUNK_SIZE' => $this->post('chunk_size'), 'CHUNK_OVERLAP' => $this->post('chunk_overlap'),
            'TOP_K' => $this->post('top_k'), 'MAX_CONTEXT' => $this->post('max_context'),
            'VECTOR_NAME' => $this->post('vector_name'), 'VECTOR_SIZE' => $this->post('vector_size'),
            'DISTANCE' => $this->post('distance'), 'HNSW_M' => $this->post('hnsw_m'),
            'HNSW_EF_CONSTRUCT' => $this->post('hnsw_ef_construct'),
            'REVISION' => $current['REVISION'],
        );
        if ($this->post('clear_token') === '1') $input['TOKEN'] = '';
        elseif ($this->post('token') !== '') $input['TOKEN'] = $this->post('token');
        $this->knowledgeSettings->saveAll($input, $this->models);
        KnowledgeSync::schedule();
        $this->redirect($this->urlPrefix() . '/knowledge.html', 'Настройки Qdrant и индекса сохранены.');
    }

    private function deleteIndex(): void
    {
        $this->index->delete(max(0, (int)$this->post('model_id')));
        $this->redirect($this->urlPrefix() . '/knowledge.html', 'Индекс удалён.');
    }

    // ---------------------------------------------------------------- AJAX

    private function handleAjax(): never
    {
        try {
            $op = $this->query('op');
            $result = match ($op) {
                'dialogs' => $this->ajaxDialogs(),
                'dialog_create' => $this->ajaxDialogCreate(),
                'dialog_delete' => $this->ajaxDialogDelete(),
                'dialog_rename' => $this->ajaxDialogRename(),
                'messages' => $this->ajaxMessages(),
                'run_status' => $this->ajaxRunStatus(),
                'drawer' => $this->ajaxDrawer(),
                'action_decide' => $this->ajaxActionDecide(),
                'send' => $this->ajaxSend(),
                'cancel' => $this->ajaxCancel(),
                'editor_state' => $this->ajaxEditorState(),
                'probe' => $this->ajaxProbe(),
                'rag_status' => array('ok' => true, 'status' => $this->safeRagStatus()),
                'index_start' => $this->ajaxIndexStart(),
                'index_step' => $this->ajaxIndexStep(),
                'index_cancel' => $this->ajaxIndexCancel(),
                'index_status' => $this->ajaxIndexStatus(),
                'test_search' => $this->ajaxTestSearch(),
                default => throw new InvalidArgumentException('Неизвестная операция.'),
            };
            $this->json(array('ok' => true) + $result);
        } catch (Throwable $failure) {
            $this->json(array('ok' => false, 'message' => $failure->getMessage()), 400);
        }
    }

    private function ajaxDialogs(): array
    {
        $dialogs = $this->chatStore->listDialogs($this->userId, 50);
        return array('dialogs' => array_map(static fn(array $dialog): array => array(
            'id' => (int)$dialog['ID'], 'title' => $dialog['TITLE'], 'type' => $dialog['CONTEXT_TYPE'],
            'messages' => (int)$dialog['MESSAGES'], 'updated' => (int)$dialog['UPDATED_AT'],
        ), $dialogs));
    }

    private function ajaxDialogCreate(): array
    {
        $context = $this->contextInput();
        $agentId = max(0, (int)$this->post('agent_id'));
        $title = $this->post('title') ?: 'Новый диалог';
        $contextType = $this->post('context_type') ?: 'chat';
        $id = $this->chatStore->createDialog($this->userId, $agentId ?: null, $title, $contextType, $context);
        return array('dialog' => array('id' => $id, 'title' => $title,
            'type' => $contextType, 'messages' => 0, 'updated' => time()));
    }

    private function ajaxDialogDelete(): array
    {
        $this->chatStore->deleteDialog((int)$this->post('dialog_id'), $this->userId);
        return array('deleted' => true);
    }

    private function ajaxDialogRename(): array
    {
        $this->chatStore->renameDialog((int)$this->post('dialog_id'), $this->userId, $this->post('title'));
        return array('renamed' => true);
    }

    private function ajaxMessages(): array
    {
        $dialogId = (int)($this->post('dialog_id') ?: $this->query('dialog_id'));
        $dialog = $this->chatStore->dialog($dialogId, $this->userId);
        $rows = $this->chatStore->messages($dialogId, $this->userId, 0, 300);
        return array('actions' => $this->actions()->forDialog($dialogId, $this->userId), 'run' => $this->chatStore->latestRun($dialogId, $this->userId), 'dialog' => array('id' => (int)$dialog['ID'], 'title' => $dialog['TITLE'],
                'agent_id' => (int)$dialog['AGENT_ID'], 'type' => $dialog['CONTEXT_TYPE']),
            'messages' => array_map(function (array $row): array {
                $row['HTML'] = $this->messageHtml($row);
                return $row;
            }, $rows));
    }

    private function ajaxRunStatus(): array
    {
        return array('run' => $this->chatStore->latestRun((int)$this->post('dialog_id'), $this->userId));
    }

    private function actions(): ActionRequests
    {
        return new ActionRequests($this->db, $this->chatStore, $this->agents, $this->state);
    }

    private function ajaxActionDecide(): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || !Core::session()->checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            throw new InvalidArgumentException('Подтверждение отклонено. Обновите страницу и повторите действие.');
        }
        $decision = $this->post('decision');
        if (!in_array($decision, array('approve', 'reject'), true)) throw new InvalidArgumentException('Неизвестное решение.');
        return array('action' => $this->actions()->decide((int)$this->post('action_id'), $this->userId,
            $decision === 'approve', Core::entities()));
    }

    private function ajaxDrawer(): array
    {
        $this->dialogId = max(0, (int)$this->post('dialog_id'));
        $this->view = 'chat';
        return array('html' => $this->renderChat());
    }

    /** SSE-поток: пользовательское сообщение уже сохранено, отмена идёт через БД. */
    private function ajaxSend(): never
    {
        $dialogId = (int)$this->post('dialog_id');
        $prompt = trim($this->post('prompt'));
        if ($prompt === '') throw new InvalidArgumentException('Введите сообщение.');
        $dialog = $this->chatStore->dialog($dialogId, $this->userId);
        $agentId = (int)$this->post('agent_id') ?: (int)$dialog['AGENT_ID'];
        if ($agentId < 1) throw new InvalidArgumentException('Выберите агента.');
        $agent = $this->agents->get($agentId);
        if ((int)$agent['ENABLED'] !== 1) throw new InvalidArgumentException('Выбранный агент выключен.');
        if ((int)$agent['MODEL_ID'] < 1) throw new InvalidArgumentException('Агенту не назначена модель. Откройте настройки агента.');
        if ($agentId !== (int)$dialog['AGENT_ID']) $this->chatStore->setAgent($dialogId, $this->userId, $agentId);
        $context = $this->contextInput();
        if ($context !== array() && (string)$dialog['CONTEXT_TYPE'] === 'editor') {
            // Контекст обновляется перед каждым запросом: в редакторе мог измениться черновик.
            $this->chatStore->updateContext($dialogId, $this->userId, $context);
        }
        $runId = $this->chatStore->beginRun($dialogId, $this->userId, $agentId, $prompt, $this->post('request_id'));
        // PHP может завершиться без catch (лимит времени, fatal). Запуск обязан
        // получить конечный статус; клиент сможет увидеть его отдельным запросом.
        register_shutdown_function(function () use ($runId): void {
            try {
                $this->chatStore->finishRun($runId, 'error', 'Поток ответа прерван сервером. Повторите запрос; подробности — в журнале PHP.');
            } catch (Throwable $failure) {
                error_log('MajorDoMo AI shutdown: ' . get_class($failure));
            }
        });
        try {
            $this->chatStore->appendMessage($dialogId, $this->userId, 'user', $prompt, array('run_id' => $runId));
        } catch (Throwable $failure) {
            $this->chatStore->finishRun($runId, 'error', $failure->getMessage());
            throw $failure;
        }
        $this->startSse();
        ignore_user_abort(true);
        set_time_limit(max(30, (int)$agent['TIMEOUT'] + 15));
        $this->sse(array('type' => 'run', 'run_id' => $runId, 'dialog_id' => $dialogId));
        try {
            $this->runner->run($runId, $this->userId, function (array $event): void {
                $this->sse($event);
            });
        } catch (Throwable $failure) {
            error_log('MajorDoMo AI SSE failed: ' . get_class($failure));
            $this->chatStore->finishRun($runId, 'error', 'Поток ответа прерван. Повторите запрос.');
            $this->sse(array('type' => 'error', 'message' => 'Поток ответа прерван. Перезагрузите диалог.'));
        }
        $this->sse(array('type' => 'close'));
        exit;
    }

    private function ajaxCancel(): array
    {
        $id = $this->post('run_id');
        if ($id === '') {
            $run = $this->chatStore->latestRun((int)$this->post('dialog_id'), $this->userId);
            if ($run === null || $run['STATUS'] !== 'running') return array('cancelled' => true);
            $id = (string)$run['ID'];
        }
        $this->chatStore->requestCancel($id, $this->userId);
        return array('cancelled' => true);
    }

    private function ajaxEditorState(): array
    {
        // Отдельного включения нет: важна только готовность агента и модели.
        $agents = array();
        foreach ($this->agents->all() as $agent) {
            if ((int)$agent['ENABLED'] !== 1 || (int)$agent['MODEL_ID'] < 1) continue;
            $agents[] = array('id' => (int)$agent['ID'], 'title' => $agent['TITLE'], 'profile' => $agent['PROFILE']);
        }
        if ($agents === array()) return array('enabled' => false, 'message' => 'Нет включённого агента с моделью.');
        $default = 0;
        foreach ($agents as $agent) if ($agent['profile'] === 'editor') { $default = $agent['id']; break; }
        if ($default === 0) $default = $agents[0]['id'];
        return array('enabled' => true, 'agents' => $agents, 'default_agent' => $default,
            'endpoint' => $this->urlPrefix() . '.html?ajax=1',
            'max_prompt' => 16000);
    }

    private function ajaxProbe(): array
    {
        $id = (int)$this->post('model_id');
        $capability = $this->post('capability') ?: 'chat';
        return array('result' => (new ConnectionProbe($this->providers))->check($id, $capability));
    }

    private function ajaxIndexStart(): array
    {
        $this->state->requireEnabled();
        $modelId = (int)$this->post('model_id');
        $row = $this->index->start($modelId);
        return array('index' => array('status' => $row['STATUS'], 'model_id' => (int)$row['MODEL_ID'],
            'documents' => (int)$row['DOCUMENTS'], 'chunks' => (int)$row['CHUNKS'],
            'processed' => (int)$row['PROCESSED'], 'percent' => 0, 'error' => ''));
    }

    private function ajaxIndexStep(): array
    {
        $this->state->requireEnabled();
        $modelId = (int)$this->post('model_id');
        $batch = max(1, min(16, (int)$this->post('batch') ?: 8));
        return array('index' => $this->index->step($modelId, $batch));
    }

    private function ajaxIndexCancel(): array
    {
        $this->index->cancel((int)$this->post('model_id'));
        return array('cancelled' => true);
    }

    private function ajaxIndexStatus(): array
    {
        return array('indexes' => $this->index->rows(), 'status' => $this->safeRagStatus());
    }

    private function ajaxTestSearch(): array
    {
        $agent = $this->agents->get((int)$this->post('agent_id'));
        $query = $this->post('query');
        if ($agent['KNOWLEDGE_MODE'] === 'rag') {
            $found = $this->index->search($query, $agent['SOURCES'], (int)($this->knowledgeSettings->get()['TOP_K'] ?? 5));
        } else {
            $found = (new KnowledgeReader())->search($query, 0, 5, $agent['SOURCES']);
        }
        return array('result' => $found);
    }

    // ---------------------------------------------------------------- output

    private function startSse(): void
    {
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, no-transform');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        echo ": stream\n\n";
        flush();
    }

    private function sse(array $event): void
    {
        echo 'event: ' . ($event['type'] ?? 'message') . "\n";
        echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
        flush();
    }

    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function redirect(string $url, string $message): never
    {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'ok_msg=' . rawurlencode($message);
        if (isset($_GET['part_load'])) {
            $this->json(array('CONTENT' => '', 'NEED_RELOAD' => 1, 'REDIRECT' => $url));
        }
        header('Location: ' . $url, true, 303);
        exit;
    }

    private function messageHtml(array $row): string
    {
        ob_start();
        $message = $row;
        require ROOT . 'templates/ai_assistant/_message.php';
        return (string)ob_get_clean();
    }

    private function contextInput(): array
    {
        $raw = $this->post('context');
        if ($raw === '') return array();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new InvalidArgumentException('Некорректный контекст редактора.');
        return ChatStore::context($decoded);
    }

    private function urlPrefix(): string
    {
        return (defined('ROOTHTML') ? (string)ROOTHTML : '/') . 'panel/ai_assistant';
    }

    private function query(string $key): string
    {
        // Чистые URL разбирает общий роутер в $_REQUEST; без gr() страница
        // всегда открывалась бы на вкладке чата.
        $value = function_exists('gr') ? gr($key) : ($_GET[$key] ?? '');
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function post(string $key): string
    {
        $value = $_POST[$key] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function postArray(string $key): array
    {
        $value = $_POST[$key] ?? array();
        if (!is_array($value)) return array();
        return array_values(array_filter($value, 'is_string'));
    }
}
