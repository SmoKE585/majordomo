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

    echo 'ИИ-помощник чат: ' . $checks . ' проверок пройдено (SQLite, FakeAIProvider, без сети).' . PHP_EOL;
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
