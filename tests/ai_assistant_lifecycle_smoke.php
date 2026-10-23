<?php

declare(strict_types=1);

// 2026-10-23 — жизненный цикл реального модуля на SQLite и Neuron без внешних API.

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('DB_HOST=');
putenv('DB_NAME=');
putenv('USE_REDIS=false');
putenv('LOG_DIRECTORY=');

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/lib/core.class.php';
require_once $projectRoot . '/lib/module.class.php';
require_once __DIR__ . '/fixtures/ai_assistant_database.php';

use Composer\InstalledVersions;
use MajorDoMo\Core\Auth\Authentication;
use MajorDoMo\Core\Config;
use MajorDoMo\Core\Core;
use MajorDoMo\Core\Database;
use MajorDoMo\Core\Users\UserAccounts;
use MajorDoMo\Modules\AiAssistant\Schema;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Testing\FakeAIProvider;

function SQLSelectOne($sql) { return $GLOBALS['db']->SelectOne($sql) ?: array(); }
function SQLExec($sql) { return $GLOBALS['db']->Exec($sql); }
function SQLInsert($table, &$record) { return $GLOBALS['db']->Insert($table, $record); }
function SQLUpdate($table, $record) { return $GLOBALS['db']->Update($table, $record); }
function isModuleInstalled($name) { return is_file(ROOT . 'cms/modules_installed/' . $name . '.installed'); }
function SaveFile($filename, $content)
{
    if (file_put_contents($filename, $content) === false) throw new RuntimeException('Не записан тестовый маркер.');
}
function gr($name) { return $name === 'md' ? 'ai_assistant' : ''; }

$checks = 0;
function check(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException|InvalidArgumentException $failure) {
        check(str_contains($failure->getMessage(), $message), 'Ожидаемая причина отказа: ' . $message);
        return;
    }
    throw new RuntimeException('Операция должна быть отклонена: ' . $message);
}

$fixture = sys_get_temp_dir() . '/mjd-ai-lifecycle-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
try {
    foreach (array('cms/modules_installed', 'templates/ui/components', 'templates/ai_assistant', 'lib/ui', 'public/assets/modules/ai_assistant') as $directory) {
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
    Config::boot($fixture);
    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    check(Config::string('APP_ENV') === 'testing' && Config::string('DB_HOST') === ''
        && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Подтверждена изоляция testing/SQLite.');
    $pdo->exec('CREATE TABLE project_modules (ID INTEGER PRIMARY KEY AUTOINCREMENT, NAME TEXT UNIQUE, TITLE TEXT, CATEGORY TEXT, DATA TEXT)');
    $pdo->exec('CREATE TABLE users (ID INTEGER PRIMARY KEY,USERNAME TEXT,NAME TEXT,PASSWORD TEXT,IS_ADMIN INTEGER)');
    $password = MajorDoMo\Core\Users\Passwords::hash('fixture-password');
    $pdo->prepare('INSERT INTO users VALUES (1,?,?,?,1)')->execute(array('admin', 'Администратор', $password));
    $GLOBALS['db'] = new AiAssistantDatabaseFixture($pdo);
    $database = Core::database();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_SELF'] = '/admin.php';
    $_SERVER['HTTP_HOST'] = 'home.test';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $session = Core::session($fixture);
    require_once $projectRoot . '/modules/ai_assistant/ai_assistant.class.php';

    $module = new ai_assistant($database, true);
    $state = $module->state();
    check(isModuleInstalled('ai_assistant'), 'Штатный install создаёт маркер.');
    check((int)$pdo->query("SELECT COUNT(*) FROM project_modules WHERE NAME='ai_assistant'")->fetchColumn() === 1, 'Одна запись в реестре модулей.');
    check($state->snapshot() === array('installed' => true, 'enabled' => true, 'schema_version' => Schema::VERSION), 'Новая установка сразу доступна и имеет актуальную схему.');
    $state->requireEnabled();

    $fake = new FakeAIProvider(new AssistantMessage('Проверка Neuron выполнена.'));
    $module->run();
    check(http_response_code() === 403 && $module->result === '', 'Без админской сессии страница закрыта.');
    $auth = new Authentication($session, new UserAccounts($pdo));
    check($auth->login('admin', 'fixture-password'), 'Вход штатным механизмом на SQLite.');
    http_response_code(200);
    $module->run();
    check(str_contains($module->result, 'Осталось подключить модель') && str_contains($module->result, 'md-card'), 'Страница без модели предлагает настройку через MDJUI. ' . mb_substr($module->result, 0, 600));
    check(!str_contains($module->result, '<script>') && !str_contains($module->result, 'component not found'), 'Нет inline-скриптов и пропавших компонентов.');
    check($fake->getCallCount() === 0, 'Установка и просмотр не обращаются к модели.');

    $state->setEnabled(true);
    $state->requireEnabled();
    $before = $pdo->query('SELECT * FROM ai_assistant_settings WHERE ID=1')->fetch(PDO::FETCH_ASSOC);
    $module->install();
    $module->dbInstall('');
    $module = new ai_assistant($database, true);
    check($module->state()->snapshot()['enabled'], 'Повторная установка и загрузка не сбрасывают состояние.');
    check($before === $pdo->query('SELECT * FROM ai_assistant_settings WHERE ID=1')->fetch(PDO::FETCH_ASSOC), 'Повторная установка сохраняет все настройки.');
    check((int)$pdo->query('SELECT COUNT(*) FROM ai_assistant_settings')->fetchColumn() === 1, 'Повторная установка не создаёт дубли настроек.');
    check((int)$pdo->query('SELECT COUNT(*) FROM project_modules')->fetchColumn() === 1, 'Повторная установка не создаёт дубли реестра.');

    $pdo->exec('UPDATE ai_assistant_settings SET SCHEMA_VERSION=0 WHERE ID=1');
    $module = new ai_assistant($database, true);
    check($module->state()->snapshot()['schema_version'] === Schema::VERSION && $module->state()->snapshot()['enabled'], 'Старая схема обновляется при существующем маркере без сброса настроек.');
    $pdo->exec('UPDATE ai_assistant_settings SET SCHEMA_VERSION=999 WHERE ID=1');
    rejects(fn() => new ai_assistant($database, true), 'несовместима');
    check((int)$pdo->query('SELECT SCHEMA_VERSION FROM ai_assistant_settings')->fetchColumn() === 999, 'Более новая схема не понижается.');
    $pdo->exec('UPDATE ai_assistant_settings SET SCHEMA_VERSION=' . Schema::VERSION . ' WHERE ID=1');

    rejects(fn() => $module->uninstall(true), 'отдельное подтверждение');
    rejects(fn() => $module->uninstall('DELETE another_module'), 'отдельное подтверждение');
    rejects(fn() => Schema::remove($database, Schema::DELETE_CONFIRMATION), 'Перед очисткой');
    check(isModuleInstalled('ai_assistant') && $module->state()->snapshot()['enabled'], 'Неверное подтверждение не меняет установку и настройки.');
    $module->uninstall();
    check(!isModuleInstalled('ai_assistant') && (int)$pdo->query('SELECT COUNT(*) FROM project_modules')->fetchColumn() === 0, 'Обычное удаление снимает регистрацию и маркер.');
    check($state->snapshot() === array('installed' => false, 'enabled' => false, 'schema_version' => Schema::VERSION), 'Удаление выключает ИИ и сохраняет данные.');
    rejects(fn() => $state->setEnabled(true), 'Сначала установите');
    rejects(fn() => $state->requireEnabled(), 'удалён');

    $module = new ai_assistant($database, true);
    check(isModuleInstalled('ai_assistant') && $module->state()->snapshot()['enabled'], 'Возврат сохранённых данных сразу доступен.');
    $pdo->exec('CREATE TABLE unrelated_module (ID INTEGER PRIMARY KEY, VALUE TEXT)');
    $pdo->exec("INSERT INTO unrelated_module VALUES (1,'сохранить')");
    $module->uninstall(Schema::DELETE_CONFIRMATION);
    check((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='ai_assistant_settings'")->fetchColumn() === 0, 'Явное подтверждение удаляет собственную таблицу.');
    check($pdo->query('SELECT VALUE FROM unrelated_module')->fetchColumn() === 'сохранить', 'Очистка не затрагивает чужие таблицы.');

    check(InstalledVersions::getPrettyVersion('neuron-core/neuron-ai') === '4.1.13', 'Проверена зафиксированная версия Neuron.');
    check(new OpenAILike('https://example.invalid/v1', '', 'fixture-model') instanceof NeuronAI\Providers\AIProviderInterface, 'OpenAI-совместимый провайдер создаётся без сетевого запроса.');
    $agent = Agent::make('ai-lifecycle-fixture')->setAiProvider($fake);
    $response = $agent->chat(new UserMessage('Проверка совместимости.'));
    check($response->getMessage()?->getContent() === 'Проверка Neuron выполнена.' && $fake->getCallCount() === 1, 'Актуальный API агента проверен встроенным FakeAIProvider.');
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    // Удаляем только созданный этим тестом каталог внутри системного temp.
    $resolved = realpath($fixture);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved === false || $temporaryRoot === false
        || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $temporaryRoot) . '/mjd-ai-lifecycle-')) {
        throw new RuntimeException('Небезопасный путь очистки тестовой фикстуры.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($resolved);
}

echo 'ИИ-помощник: ' . $checks . ' проверок пройдено (SQLite, без внешних API).' . PHP_EOL;
