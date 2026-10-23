<?php

declare(strict_types=1);

// 2026-10-23 — все экраны ИИ-помощника и хранилище диалогов без внешних API.

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('DB_HOST=');
putenv('DB_NAME=');
putenv('USE_REDIS=false');
putenv('AI_SECRET_KEY=');

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/lib/core.class.php';
require_once $projectRoot . '/lib/module.class.php';
require_once __DIR__ . '/fixtures/ai_assistant_database.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Database;
use MajorDoMo\Modules\AiAssistant\{AgentCatalog, ChatStore, Dashboard, ModelCatalog, ModuleState, PanelController, Schema, SecretVault};

$checks = 0;
function check(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $operation, string $message): void
{
    try { $operation(); }
    catch (RuntimeException|InvalidArgumentException $failure) {
        check(str_contains($failure->getMessage(), $message), 'Ожидался отказ: ' . $message . '; получено: ' . $failure->getMessage());
        return;
    }
    throw new RuntimeException('Операция должна быть отклонена: ' . $message);
}

$fixture = sys_get_temp_dir() . '/mjd-ai-panel-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
try {
    foreach (array('templates/ui/components', 'templates/ai_assistant', 'lib/ui', 'public/assets/modules/ai_assistant', 'docs/user', 'var') as $directory) {
        mkdir($fixture . '/' . $directory, 0700, true);
    }
    foreach (glob($projectRoot . '/templates/ui/components/*.php') as $component) {
        copy($component, $fixture . '/templates/ui/components/' . basename($component));
    }
    foreach (glob($projectRoot . '/templates/ai_assistant/*.php') as $template) {
        copy($template, $fixture . '/templates/ai_assistant/' . basename($template));
    }
    copy($projectRoot . '/public/assets/modules/ai_assistant/ai_assistant.css', $fixture . '/public/assets/modules/ai_assistant/ai_assistant.css');
    copy($projectRoot . '/public/assets/modules/ai_assistant/ai_assistant.js', $fixture . '/public/assets/modules/ai_assistant/ai_assistant.js');
    copy($projectRoot . '/lib/ui/MDJUI.php', $fixture . '/lib/ui/MDJUI.php');
    copy($projectRoot . '/lib/ui/tabler-icons.json', $fixture . '/lib/ui/tabler-icons.json');
    file_put_contents($fixture . '/docs/user/light.md', "---\ntitle: Свет\ncategory: user\n---\n# Свет\nПример статьи.\n");
    Config::boot($fixture);

    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('CREATE TABLE project_modules (ID INTEGER PRIMARY KEY AUTOINCREMENT, NAME TEXT UNIQUE, TITLE TEXT, CATEGORY TEXT, DATA TEXT)');
    $pdo->exec('CREATE TABLE users (ID INTEGER PRIMARY KEY,USERNAME TEXT,NAME TEXT,PASSWORD TEXT,IS_ADMIN INTEGER)');
    $pdo->prepare('INSERT INTO users VALUES (1,?,?,?,1)')->execute(array('admin', 'Администратор', MajorDoMo\Core\Users\Passwords::hash('fixture-password')));
    $GLOBALS['db'] = new AiAssistantDatabaseFixture($pdo);
    $db = new Database($GLOBALS['db']);
    Schema::install($db, true);
    $vault = new SecretVault(base64_encode(random_bytes(32)));
    $models = new ModelCatalog($db, $vault);
    $agents = new AgentCatalog($db, $models);
    $state = new ModuleState($db);
    $state->setEnabled(true);

    $providerId = $models->saveProvider(0, array('TITLE' => 'Локальный', 'BASE_URL' => 'http://127.0.0.1:1/v1',
        'PROTOCOL' => 'openai_chat', 'TIMEOUT' => 5, 'ENABLED' => 1));
    $modelId = $models->saveModel(0, array('PROVIDER_ID' => $providerId, 'TITLE' => 'Чат', 'MODEL' => 'fixture',
        'CAPABILITIES' => array('chat', 'tools'), 'PARAMETERS' => array(), 'CONTEXT_WINDOW' => 8192,
        'MAX_OUTPUT' => 512, 'ENABLED' => 1));
    $agentId = (int)$agents->all()[0]['ID'];
    $record = $agents->get($agentId);
    $record['MODEL_ID'] = $modelId;
    $agents->save($agentId, $record);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_SELF'] = '/admin.php';
    $_SERVER['HTTP_HOST'] = 'home.test';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $session = MajorDoMo\Core\Core::session($fixture);
    require_once $projectRoot . '/lib/ui/MDJUI.php';
    $auth = new MajorDoMo\Core\Auth\Authentication($session, new MajorDoMo\Core\Users\UserAccounts($pdo));
    check($auth->login('admin', 'fixture-password'), 'Вход администратора в тестовой сессии.');

    $render = static function (array $get) use ($db, $state): string {
        $_GET = $get;
        $_POST = array();
        $controller = new PanelController($db, $state, true);
        return $controller->handle();
    };

    $csrfController = new PanelController($db, $state, true);
    $decisionEndpoint = new ReflectionMethod($csrfController, 'ajaxActionDecide');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    rejects(fn() => $decisionEndpoint->invoke($csrfController), 'Подтверждение отклонено');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    rejects(fn() => $decisionEndpoint->invoke($csrfController), 'Подтверждение отклонено');

    $views = array(
        'chat' => array('view_mode' => 'chat'),
        'agents' => array('view_mode' => 'agents'),
        'agent_new' => array('view_mode' => 'agent_edit'),
        'agent_edit' => array('view_mode' => 'agent_edit', 'id' => $agentId),
        'providers' => array('view_mode' => 'providers'),
        'provider_new' => array('view_mode' => 'provider_edit'),
        'provider_edit' => array('view_mode' => 'provider_edit', 'id' => $providerId),
        'models' => array('view_mode' => 'models'),
        'model_new' => array('view_mode' => 'model_edit'),
        'model_edit' => array('view_mode' => 'model_edit', 'id' => $modelId),
        'knowledge' => array('view_mode' => 'knowledge'),
        'diagnostics' => array('view_mode' => 'diagnostics'),
    );
    foreach ($views as $name => $get) {
        $html = $render($get);
        check(strlen($html) > 500, 'Экран ' . $name . ' отрендерен.');
        check(str_contains($html, 'md-card'), 'Экран ' . $name . ' использует компоненты админки.');
        check(!str_contains($html, 'component not found'), 'На экране ' . $name . ' нет пропавших компонентов.');
        check(stripos($html, 'warning:') === false && stripos($html, 'fatal error') === false, 'На экране ' . $name . ' нет PHP-предупреждений.');
        check(!str_contains($html, 'fixture-secret'), 'На экране ' . $name . ' нет тестовых секретов.');
    }

    $chat = new ChatStore($db);
    $dialogId = $chat->createDialog(1, $agentId, 'Проверка', 'chat', array());
    check($dialogId > 0 && $chat->dialog($dialogId, 1)['TITLE'] === 'Проверка', 'Диалог создан и читается владельцем.');
    rejects(fn() => $chat->dialog($dialogId, 2), 'не найден');
    $messageId = $chat->appendMessage($dialogId, 1, 'user', 'Где свет?', array('sources' => array()));
    check($messageId > 0 && count($chat->messages($dialogId, 1)) === 1, 'Сообщение сохранено.');
    $runId = $chat->beginRun($dialogId, 1, $agentId, 'Где свет?');
    check(strlen($runId) === 32 && !$chat->isCancelled($runId), 'Запуск начат.');
    rejects(fn() => $chat->beginRun($dialogId, 1, $agentId, 'Ещё раз'), 'уже идёт генерация');
    $chat->requestCancel($runId, 1);
    check($chat->isCancelled($runId), 'Отмена записана.');
    $chat->finishRun($runId, 'cancelled');
    check($chat->run($runId, 1)['STATUS'] === 'cancelled', 'Отменённый запуск закрыт.');
    $chat->renameDialog($dialogId, 1, 'Новое имя');
    check($chat->listDialogs(1)[0]['TITLE'] === 'Новое имя', 'Диалог переименован.');
    $chat->deleteDialog($dialogId, 1);
    check($chat->listDialogs(1) === array(), 'Удалённый диалог не возвращается.');
    check((int)$pdo->query('SELECT COUNT(*) FROM ai_assistant_messages WHERE DIALOG_ID=' . $dialogId)->fetchColumn() === 1,
        'Мягкое удаление сохраняет сообщения для истории.');

    $longDialog = $chat->createDialog(1, $agentId, 'Лимит', 'chat', array());
    if (($argv[1] ?? '') === '--export-ui') {
        $chat->appendMessage($longDialog, 1, 'user', 'Найди свет в туалете и предложи вечерний сценарий.');
        $chat->appendMessage($longDialog, 1, 'assistant', "Найден кандидат LigthToilet. Проверьте его назначение.\n\n```php\ncm('LigthToilet.turnOn');\n```");
        $requests = new MajorDoMo\Modules\AiAssistant\ActionRequests($db, $chat, $agents, $state);
        $proposal = $requests->propose($longDialog, 1, $agentId, array('kind' => 'script', 'name' => 'EveningFixture', 'parent' => '',
            'description' => 'Включить свет вечером. Сценарий будет создан, но не запущен.', 'code' => "cm('LigthToilet.turnOn');"));
        $exportMessages = array();
        foreach ($chat->messages($longDialog, 1) as $message) {
            ob_start(); require $fixture . '/templates/ai_assistant/_message.php'; $message['HTML'] = ob_get_clean(); $exportMessages[] = $message;
        }
        $pageHtml = $render(array('view_mode' => 'chat', 'dialog_id' => $longDialog));
        $drawerHtml = $render(array('view_mode' => 'chat', 'dialog_id' => $longDialog, 'op' => 'drawer'));
        check(!str_contains($drawerHtml, '<script') && str_contains($drawerHtml, 'data-ai-drawer'), 'Drawer использует общий чат без вложенных скриптов.');
        $export = sys_get_temp_dir() . '/mjd-ai-ui-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($export, json_encode(array('page' => $pageHtml, 'drawer' => $drawerHtml, 'messages' => $exportMessages,
            'answer_html' => MajorDoMo\Modules\AiAssistant\AgentRunner::render("Найдено устройство.\n\n```php\ncm('LigthToilet.turnOn');\n```"),
            'action' => $proposal, 'dialog_id' => $longDialog), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        echo 'UI_FIXTURE=' . $export . PHP_EOL;
    }
    $long = str_repeat('я', 200001);
    rejects(fn() => $chat->appendMessage($longDialog, 1, 'user', $long), 'слишком длинное');

    // Проверка дашборда: 3 шага онбординга и статусы
    $dash = new Dashboard($db);
    $statusReady = $dash->status();
    check($statusReady['ready'] === true, 'Дашборд готов, когда есть провайдер, модель и агент.');
    check(count($statusReady['steps']) === 3, 'Онбординг содержит ровно 3 шага.');
    check($statusReady['completed_steps'] === 3, 'Все 3 шага выполнены.');

    // Проверка состояния "Требует настройки" (0 провайдеров/моделей)
    $pdo->exec('DELETE FROM ai_assistant_model_links');
    $pdo->exec('UPDATE ai_assistant_agents SET MODEL_ID = NULL');
    $pdo->exec('DELETE FROM ai_assistant_models');
    $pdo->exec('DELETE FROM ai_assistant_providers');
    $statusSetup = $dash->status();
    check($statusSetup['ready'] === false, 'Дашборд требует настройки без провайдера.');
    check($statusSetup['completed_steps'] < 3, 'Количество завершённых шагов меньше 3.');
    check($statusSetup['steps'][0]['done'] === false, 'Шаг 1 не выполнен.');
    check($statusSetup['steps'][0]['current'] === true, 'Шаг 1 текущий.');

    echo 'ИИ-помощник UI: ' . $checks . ' проверок пройдено (SQLite, без внешних API).' . PHP_EOL;
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $resolved = realpath($fixture);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved === false || $temporaryRoot === false
        || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $temporaryRoot) . '/mjd-ai-panel-')) {
        throw new RuntimeException('Небезопасный путь очистки тестовой фикстуры.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($resolved);
}
