<?php

declare(strict_types=1);

// 2026-10-23 — полный цикл AgentRunner: история, инструменты, стрим и сохранение.

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('DB_HOST=');
putenv('DB_NAME=');
putenv('USE_REDIS=false');
putenv('AI_SECRET_KEY=');

$projectRoot = dirname(__DIR__);
define('ROOT', $projectRoot . '/');
define('ROOTHTML', '/');
require_once $projectRoot . '/lib/core.class.php';
require_once __DIR__ . '/fixtures/ai_assistant_database.php';

use MajorDoMo\Core\{Config, Database};
use MajorDoMo\Modules\AiAssistant\{AgentCatalog, AgentRunner, ChatStore, ModelCatalog, ModuleState, ProviderFactory, Schema, SecretVault};
use NeuronAI\Chat\Messages\{AssistantMessage, ToolCallMessage, UserMessage};
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;

$checks = 0;
function check(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($message);
}

$fixture = sys_get_temp_dir() . '/mjd-ai-chat-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
try {
    foreach (array('docs/user', 'var') as $directory) mkdir($fixture . '/' . $directory, 0700, true);
    file_put_contents($fixture . '/docs/user/light.md', "---\ntitle: Свет\n---\n# Свет\nСвойство lightStatus.\n");
    Config::boot($fixture);
    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->exec('PRAGMA foreign_keys=ON');
    foreach (array(
        'CREATE TABLE users (ID INTEGER PRIMARY KEY,USERNAME TEXT,NAME TEXT,PASSWORD TEXT,IS_ADMIN INTEGER)',
        'CREATE TABLE classes (ID INTEGER PRIMARY KEY,TITLE TEXT,PARENT_ID INTEGER DEFAULT 0,DESCRIPTION TEXT)',
        'CREATE TABLE objects (ID INTEGER PRIMARY KEY,TITLE TEXT,CLASS_ID INTEGER,DESCRIPTION TEXT,KEEP_HISTORY INTEGER DEFAULT 0)',
        'CREATE TABLE properties (ID INTEGER PRIMARY KEY,CLASS_ID INTEGER,OBJECT_ID INTEGER,TITLE TEXT,DESCRIPTION TEXT,KEEP_HISTORY INTEGER DEFAULT 0,ONCHANGE TEXT, SYSTEM TEXT, DATA_TYPE INTEGER DEFAULT 0, VALIDATION_TYPE INTEGER DEFAULT 0, VALIDATION_NUM_MIN TEXT, VALIDATION_NUM_MAX TEXT, VALIDATION_LIST TEXT)',
        'CREATE TABLE methods (ID INTEGER PRIMARY KEY,CLASS_ID INTEGER,OBJECT_ID INTEGER,TITLE TEXT,DESCRIPTION TEXT,CODE TEXT,CALL_PARENT INTEGER DEFAULT 0,SCRIPT_ID INTEGER DEFAULT 0)',
        'CREATE TABLE pvalues (ID INTEGER PRIMARY KEY,PROPERTY_ID INTEGER,OBJECT_ID INTEGER,VALUE TEXT,UPDATED TEXT,PROPERTY_NAME TEXT,LINKED_MODULES TEXT,SOURCE TEXT)',
        'CREATE TABLE scripts (ID INTEGER PRIMARY KEY,TITLE TEXT,DESCRIPTION TEXT,CODE TEXT)',
        'CREATE TABLE events (ID INTEGER PRIMARY KEY,EVENT_NAME TEXT,DESCRIPTION TEXT)',
        'CREATE TABLE events_params (ID INTEGER PRIMARY KEY,EVENT_ID INTEGER,LINKED_OBJECT TEXT,LINKED_PROPERTY TEXT)',
    ) as $sql) $pdo->exec($sql);
    $pdo->exec("INSERT INTO classes VALUES (1,'Lamp',0,'Лампа')");
    $pdo->exec("INSERT INTO objects VALUES (1,'KitchenLamp',1,'Лампа на кухне',0)");
    $pdo->exec("INSERT INTO properties VALUES (1,1,0,'status','Состояние',0,'','',0,0,'','','')");
    $pdo->exec("INSERT INTO pvalues VALUES (1,1,1,'1','2026-10-23 10:00:00','KitchenLamp.status','','')");
    $GLOBALS['db'] = new AiAssistantDatabaseFixture($pdo);
    $database = new Database($GLOBALS['db']);
    Schema::install($database, true);
    $vault = new SecretVault(base64_encode(random_bytes(32)));
    $models = new ModelCatalog($database, $vault);
    $providerId = $models->saveProvider(0, array('TITLE' => 'Тест', 'BASE_URL' => 'http://127.0.0.1:1/v1',
        'PROTOCOL' => 'openai_chat', 'TIMEOUT' => 1, 'ENABLED' => 1));
    $modelId = $models->saveModel(0, array('PROVIDER_ID' => $providerId, 'TITLE' => 'Чат', 'MODEL' => 'fixture',
        'CAPABILITIES' => array('chat', 'tools'), 'PARAMETERS' => array(), 'CONTEXT_WINDOW' => 8192,
        'MAX_OUTPUT' => 512, 'ENABLED' => 1));
    $agents = new AgentCatalog($database, $models);
    $agentId = (int)$agents->all()[0]['ID'];
    $record = $agents->get($agentId);
    $record['MODEL_ID'] = $modelId;
    $record['TOOLS'] = array('find_objects', 'read_object');
    $agents->save($agentId, $record);
    $state = new ModuleState($database);
    $state->setEnabled(true);
    $chat = new ChatStore($database);
    $factory = new ProviderFactory($models);

    $fake = new FakeAIProvider(
        new ToolCallMessage(null, array(new ToolCall('find_objects', 'call-1', array('query' => 'Lamp')))),
        new AssistantMessage('Нашёл KitchenLamp. Свойство status сейчас 1.')
    );
    $runner = new AgentRunner($state, $chat, $agents, $models, $factory, null, static fn(): int => 1, $fake);
    $dialogId = $chat->createDialog(1, $agentId, 'Новый диалог', 'chat', array());
    $chat->appendMessage($dialogId, 1, 'user', 'Где лампа?');
    $runId = $chat->beginRun($dialogId, 1, $agentId, 'Где лампа?');
    $events = array();
    $result = $runner->run($runId, 1, static function (array $event) use (&$events): void { $events[] = $event; });
    check($result['status'] === 'done' && str_contains($result['content'], 'KitchenLamp'), 'Ответ сохранён после цикла Tools.');
    check($fake->getCallCount() === 2, 'Модель вызвана дважды: tool call и финальный ответ.');
    check(count(array_filter($events, static fn(array $event): bool => $event['type'] === 'token')) > 1, 'Текст ответа пришёл порциями.');
    check(count(array_filter($events, static fn(array $event): bool => $event['type'] === 'tool')) === 1, 'Событие вызова инструмента отправлено.');
    check(count(array_filter($events, static fn(array $event): bool => $event['type'] === 'tool_result')) === 1, 'Событие результата инструмента отправлено.');
    $messages = $chat->messages($dialogId, 1);
    check(count($messages) === 2 && $messages[1]['ROLE'] === 'assistant', 'История содержит вопрос и ответ.');
    check(str_contains(json_encode($messages[1]['META'], JSON_UNESCAPED_UNICODE), 'find_objects'), 'Метаданные сохранили использованный инструмент.');
    check($chat->run($runId, 1)['STATUS'] === 'done', 'Запуск завершён и закрыт.');
    $recorded = $fake->getRecorded()[0];
    check(str_contains(implode(' ', array_map(static fn($message) => (string)$message->getContent(), $recorded->messages)), 'Где лампа?'),
        'Модель получила сообщение пользователя.');
    $toolResult = $fake->getRecorded()[1]->messages;
    check(str_contains(json_encode($toolResult, JSON_UNESCAPED_UNICODE), 'KitchenLamp'), 'Модель получила данные реального объекта.');

    $longAgent = $agents->get($agentId);
    $longAgent['MAX_STEPS'] = 30;
    $agents->save($agentId, $longAgent);
    $longDialog = $chat->createDialog(1, $agentId, 'Тридцать инструментов', 'chat', array());
    $chat->appendMessage($longDialog, 1, 'user', 'Проверь данные');
    $longRun = $chat->beginRun($longDialog, 1, $agentId, 'Проверь данные');
    $responses = array();
    for ($step = 0; $step < 30; $step++) {
        $responses[] = new ToolCallMessage(null, array(new ToolCall('find_objects', 'long-' . $step, array('query' => 'Lamp'))));
    }
    $responses[] = new AssistantMessage('Все тридцать проверок выполнены.');
    $longFake = new FakeAIProvider(...$responses);
    $longRunner = new AgentRunner($state, $chat, $agents, $models, $factory, null, static fn(): int => 1, $longFake);
    $longEvents = array();
    $longResult = $longRunner->run($longRun, 1, static function (array $event) use (&$longEvents): void { $longEvents[] = $event; });
    $completedTools = array_filter($longEvents, static fn(array $event): bool => $event['type'] === 'tool_result' && str_contains($event['summary'], 'KitchenLamp'));
    check(count($completedTools) === 30, 'Лимит 30 разрешает тридцать реальных вызовов, включая один инструмент больше десяти раз.');
    check($longResult['status'] === 'done' && $longFake->getCallCount() === 31, 'После тридцати инструментов остаётся возможность получить финальный ответ.');
    check($longFake->getRecorded()[30]->tools === array(), 'Финальный ответ после исчерпания бюджета запрашивается без инструментов.');
    check($chat->result($longRun, 1)['CONTENT'] === 'Все тридцать проверок выполнены.', 'Завершённый ответ восстанавливается по точному ID запуска.');
    $foreignResultDenied = false;
    try { $chat->result($longRun, 2); } catch (InvalidArgumentException) { $foreignResultDenied = true; }
    check($foreignResultDenied, 'Результат запуска недоступен другому пользователю.');

    $budgetAccess = new MajorDoMo\Modules\AiAssistant\ToolAccess($state, $agents, $agentId, 1, static fn(): int => 1);
    $budgetState = new NeuronAI\Agent\AgentState();
    $budgetState->request = new NeuronAI\Agent\InferenceRequest('Основные инструкции');
    $budgetResources = new NeuronAI\Agent\AgentResources($longFake,
        new NeuronAI\Chat\History\ChatHistory(new NeuronAI\Chat\History\InMemoryMessageStore(), 'budget-fixture'),
        new NeuronAI\Chat\Messages\SystemMessage('Основные инструкции'),
        new NeuronAI\Tools\ToolRegistry((new MajorDoMo\Modules\AiAssistant\ToolRegistry($budgetAccess, new MajorDoMo\Modules\AiAssistant\KnowledgeReader()))->tools()));
    $budget = new MajorDoMo\Modules\AiAssistant\RunBudget($budgetAccess, 30, 0, 120, static function (array $event): void {}, static fn(): float => 97);
    $budget->before(new NeuronAI\Agent\Nodes\ChatNode(), NeuronAI\Agent\Events\AIInferenceEvent::fromRequest($budgetState->request), $budgetState, $budgetResources);
    check($budgetResources->tools->all() === array() && $budgetAccess->calls() === 0, 'Перед таймаутом оставлено время на финальный ответ даже при неиспользованных шагах.');

    // Второй запуск продолжает историю: два прошлых сообщения и новый вопрос.
    $chat->appendMessage($dialogId, 1, 'user', 'А кто его меняет?');
    $run2 = $chat->beginRun($dialogId, 1, $agentId, 'А кто его меняет?');
    $fake2 = new FakeAIProvider(new AssistantMessage('Проверю журнал.'));
    $runner2 = new AgentRunner($state, $chat, $agents, $models, $factory, null, static fn(): int => 1, $fake2);
    $runner2->run($run2, 1, static function (array $event): void {});
    check(count($fake2->getRecorded()[0]->messages) === 3, 'В новом запуске передана вся история диалога.');

    // Чужой пользователь не может запустить диалог.
    $foreign = $chat->beginRun($dialogId, 1, $agentId, 'Чужой запрос');
    $fake3 = new FakeAIProvider(new AssistantMessage('Не должно быть.'));
    $runner3 = new AgentRunner($state, $chat, $agents, $models, $factory, null, static fn(): int => 2, $fake3);
    $foreignResult = $runner3->run($foreign, 2, static function (array $event): void {});
    check($foreignResult['status'] === 'error' && $fake3->getCallCount() === 0, 'Чужой запуск отклонён до обращения к модели.');
    $chat->finishRun($foreign, 'error', 'foreign test');

    // Удалённый модуль не обращается к модели.
    $state->detached();
    $run3 = $chat->beginRun($dialogId, 1, $agentId, 'Пока выключено');
    $fake4 = new FakeAIProvider(new AssistantMessage('Не должно быть.'));
    $runner4 = new AgentRunner($state, $chat, $agents, $models, $factory, null, static fn(): int => 1, $fake4);
    $disabled = $runner4->run($run3, 1, static function (array $event): void {});
    check($disabled['status'] === 'error' && $fake4->getCallCount() === 0, 'Удалённый модуль не вызывает модель.');

    // Подтверждение Neuron останавливает инструмент до любой записи в систему.
    $state->installed();
    $record = $agents->get($agentId);
    $record['TOOLS'] = array('find_objects', 'create_entity');
    $agents->save($agentId, $record);
    $payload = array('kind' => 'script', 'name' => 'EveningFixture', 'parent' => '',
        'description' => 'Тестовый вечерний сценарий', 'code' => '$GLOBALS["ai_fixture_executed"] = true;');
    $chat->appendMessage($dialogId, 1, 'user', 'Создай вечерний сценарий');
    $pendingRun = $chat->beginRun($dialogId, 1, $agentId, 'Создай вечерний сценарий');
    $approvalFake = new FakeAIProvider(new ToolCallMessage(null, array(new ToolCall('create_entity', 'create-1', $payload))));
    $approvalRunner = new AgentRunner($state, $chat, $agents, $models, $factory, null, static fn(): int => 1, $approvalFake);
    $approvalEvents = array();
    $approval = $approvalRunner->run($pendingRun, 1, static function (array $event) use (&$approvalEvents): void { $approvalEvents[] = $event; });
    check($approval['status'] === 'approval', 'Neuron приостановил создание для подтверждения.');
    check((int)$pdo->query('SELECT COUNT(*) FROM scripts')->fetchColumn() === 0, 'До подтверждения сценарий не создан.');
    $requests = new \MajorDoMo\Modules\AiAssistant\ActionRequests($database, $chat, $agents, $state);
    $draft = $requests->forDialog($dialogId, 1)[0];
    check($draft['status'] === 'pending' && str_contains($draft['code_html'], 'data-md-code-sample'), 'Сохранён точный код для подтверждения в общем UI-компоненте.');
    $invalidated = 0;
    $creator = new \MajorDoMo\Core\EntityCreator($database, static function () use (&$invalidated): void { $invalidated++; }, true);
    $foreignDenied = false;
    try { $requests->decide($draft['id'], 2, true, $creator); } catch (RuntimeException) { $foreignDenied = true; }
    check($foreignDenied, 'Чужой пользователь не может подтвердить предложение.');
    $decision = $requests->decide($draft['id'], 1, true, $creator);
    check($decision['status'] === 'created' && (int)$pdo->query('SELECT COUNT(*) FROM scripts')->fetchColumn() === 1, 'Подтверждение создало ровно один сценарий.');
    check(!isset($GLOBALS['ai_fixture_executed']) && $invalidated === 1, 'Создание обновило метаданные, не исполнило PHP-код.');
    $requests->decide($draft['id'], 1, true, $creator);
    check((int)$pdo->query('SELECT COUNT(*) FROM scripts')->fetchColumn() === 1, 'Повторный клик не создаёт дубликат.');
    $rejected = $requests->propose($dialogId, 1, $agentId, array_replace($payload, array('name' => 'RejectedFixture')));
    check($requests->decide($rejected['id'], 1, false, $creator)['status'] === 'rejected', 'Отказ сохранён.');
    check((int)$pdo->query('SELECT COUNT(*) FROM scripts')->fetchColumn() === 1, 'Отклонённый сценарий не создаётся.');
    $revoked = $requests->propose($dialogId, 1, $agentId, array_replace($payload, array('name' => 'RevokedFixture')));
    $record = $agents->get($agentId); $record['TOOLS'] = array('find_objects'); $agents->save($agentId, $record);
    $denied = false;
    try { $requests->decide($revoked['id'], 1, true, $creator); } catch (RuntimeException) { $denied = true; }
    check($denied, 'Отзыв права до клика запрещает создание.');
    $class = $creator->create(array('kind' => 'class', 'name' => 'FixtureLights'));
    $object = $creator->create(array('kind' => 'object', 'name' => 'FixtureToilet', 'parent' => 'FixtureLights'));
    $property = $creator->create(array('kind' => 'object_property', 'name' => 'status', 'parent' => 'FixtureToilet'));
    $classProperty = $creator->create(array('kind' => 'class_property', 'name' => 'level', 'parent' => 'FixtureLights'));
    check($class['id'] > 0 && $object['id'] > 0 && $property['id'] > 0 && $classProperty['id'] > 0, 'Классы, объекты и оба вида свойств создаются через общий сервис.');
    $inheritedDenied = false;
    try { $creator->create(array('kind' => 'object_property', 'name' => 'level', 'parent' => 'FixtureToilet')); } catch (InvalidArgumentException) { $inheritedDenied = true; }
    check($inheritedDenied, 'Создание не переопределяет унаследованное свойство неявно.');
    $invalidSyntax = false;
    try { $creator->create(array_replace($payload, array('name' => 'BrokenFixture', 'code' => 'if ('))); } catch (InvalidArgumentException) { $invalidSyntax = true; }
    check($invalidSyntax, 'Синтаксически неверный PHP не записывается.');
    $duplicateDenied = false;
    try { $creator->create(array('kind' => 'object', 'name' => 'FixtureToilet', 'parent' => 'FixtureLights')); } catch (InvalidArgumentException) { $duplicateDenied = true; }
    check($duplicateDenied, 'Существующий объект не перезаписывается.');
    $cancelRun = $chat->beginRun($dialogId, 1, $agentId, 'Проверка обрыва');
    $chat->requestCancel($cancelRun, 1);
    check($chat->run($cancelRun, 1)['STATUS'] === 'cancelled', 'Отмена сразу освобождает диалог.');
    $nextRun = $chat->beginRun($dialogId, 1, $agentId, 'Продолжение после обрыва');
    check($chat->latestRun($dialogId, 1)['ID'] === $nextRun, 'Последний запуск точен даже при запросах в одну секунду.');
    $pdo->exec("UPDATE ai_assistant_runs SET UPDATED_AT=1 WHERE ID='" . $nextRun . "'");
    check($chat->latestRun($dialogId, 1)['STATUS'] === 'error', 'Зависший процесс восстанавливается по просроченному пульсу.');
    $selection = ChatStore::context(array('selection' => array('from' => 1, 'to' => 3, 'text' => 'sg')));
    check($selection['selection'] === 'sg', 'Выделение редактора передаётся модели текстом.');
    $inspector = new \MajorDoMo\Core\Inspection\InstallationInspector($database);
    $pdo->exec("INSERT INTO objects VALUES (99,'LigthToilet',1,'',0)");
    $found = $inspector->searchObjects('свет в туалете');
    check(in_array('LigthToilet', array_column($found['items'], 'title'), true), 'Русский бытовой запрос находит устройство с опечаткой среди кандидатов.');
    $typoFound = $inspector->searchObjects('light toliet');
    check(in_array('LigthToilet', array_column($typoFound['items'], 'title'), true), 'Поиск учитывает переставленные соседние буквы.');

    $pdo->exec('ALTER TABLE objects ADD COLUMN LOCATION_ID INTEGER DEFAULT 0');
    $pdo->exec("UPDATE objects SET LOCATION_ID=7 WHERE TITLE IN ('KitchenLamp','LigthToilet')");
    $neighbors = $inspector->objectDetails('LigthToilet');
    check(in_array('KitchenLamp', array_column($neighbors['nearby'], 'title'), true), 'Соседи определяются по фактическому LOCATION_ID.');
    $correlation = bin2hex(random_bytes(16));
    $correlated = $chat->beginRun($dialogId, 1, $agentId, 'Запрос с потерянным первым SSE', $correlation);
    check($correlated === $correlation, 'Клиент знает ID запуска до первого события SSE.');
    $chat->requestCancel($correlation, 1);

    $httpBase = $argv[1] ?? '';
    if ($httpBase !== '') {
        if (parse_url($httpBase, PHP_URL_HOST) !== '127.0.0.1' || parse_url($httpBase, PHP_URL_SCHEME) !== 'http') throw new RuntimeException('Только локальная simulation.');
        $httpProvider = $models->saveProvider(0, array('TITLE' => 'Simulation', 'BASE_URL' => $httpBase . '/v1', 'PROTOCOL' => 'openai_chat', 'TIMEOUT' => 1, 'ENABLED' => 1));
        foreach (array('stream-drop' => 'error', 'stream-timeout' => 'error', 'stream-ok' => 'done') as $name => $expected) {
            $httpModel = $models->saveModel(0, array('PROVIDER_ID' => $httpProvider, 'TITLE' => $name, 'MODEL' => $name, 'CAPABILITIES' => array('chat'), 'PARAMETERS' => array(), 'CONTEXT_WINDOW' => 8192, 'MAX_OUTPUT' => 512, 'ENABLED' => 1));
            $httpAgent = $agents->get($agentId); $httpAgent['TOOLS'] = array(); $httpAgent['MODEL_ID'] = $httpModel; $agents->save($agentId, $httpAgent);
            $httpRun = $chat->beginRun($dialogId, 1, $agentId, 'HTTP-проверка');
            $chat->appendMessage($dialogId, 1, 'user', 'HTTP-проверка');
            $httpEvents = array();
            $httpRunner = new AgentRunner($state, $chat, $agents, $models, new ProviderFactory($models), null, static fn(): int => 1);
            $httpResult = $httpRunner->run($httpRun, 1, static function (array $event) use (&$httpEvents): void { $httpEvents[] = $event; });
            check($httpResult['status'] === $expected && $chat->run($httpRun, 1)['STATUS'] === $expected, 'Реальный HTTP-поток: ' . $name . ' завершает запуск.');
            check(count(array_filter($httpEvents, static fn(array $e): bool => $e['type'] === 'heartbeat')) > 0, 'cURL посылает пульс в ходе HTTP-запроса.');
            if ($name === 'stream-drop') check(str_contains($httpResult['content'], 'Часть ответа'), 'Обрыв провайдера сохраняет полученный текст.');
        }
        $httpModel = $models->saveModel(0, array('PROVIDER_ID' => $httpProvider, 'TITLE' => '30 HTTP-вызовов', 'MODEL' => 'stream-loop-30',
            'CAPABILITIES' => array('chat', 'tools'), 'PARAMETERS' => array(), 'CONTEXT_WINDOW' => 32768, 'MAX_OUTPUT' => 512, 'ENABLED' => 1));
        $httpAgent = $agents->get($agentId); $httpAgent['TOOLS'] = array('find_objects'); $httpAgent['MODEL_ID'] = $httpModel;
        $httpAgent['MAX_STEPS'] = 30; $agents->save($agentId, $httpAgent);
        $httpDialog = $chat->createDialog(1, $agentId, '30 HTTP-проверок', 'chat', array());
        $httpRun = $chat->beginRun($httpDialog, 1, $agentId, 'Лампа');
        $chat->appendMessage($httpDialog, 1, 'user', 'Лампа');
        $httpEvents = array();
        $httpResult = $httpRunner->run($httpRun, 1, static function (array $event) use (&$httpEvents): void { $httpEvents[] = $event; });
        check($httpResult['status'] === 'done', 'Полный цикл из 30 реальных HTTP-вызовов инструментов завершён ответом.');
        check(count(array_filter($httpEvents, static fn(array $event): bool => $event['type'] === 'tool_result')) === 30,
            'В HTTP-цикле выполнены все 30 инструментов без скрытого лимита 10.');
    }

    echo 'ИИ-помощник чат: ' . $checks . ' проверок пройдено (SQLite, FakeAIProvider; HTTP ' . ($httpBase !== '' ? 'локальная Postman simulation' : 'не запускался') . ').' . PHP_EOL;
} finally {
    $resolved = realpath($fixture);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved === false || $temporaryRoot === false
        || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $temporaryRoot) . '/mjd-ai-chat-')) {
        throw new RuntimeException('Небезопасный путь очистки тестовой фикстуры.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($resolved);
}
