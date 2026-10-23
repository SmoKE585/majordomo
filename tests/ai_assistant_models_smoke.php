<?php

declare(strict_types=1);

// 2026-10-23 — каталоги ИИ и токены на SQLite; HTTP только к локальной simulation.

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('DB_HOST=');
putenv('DB_NAME=');
putenv('USE_REDIS=false');
putenv('AI_SECRET_KEY=');
require_once dirname(__DIR__) . '/lib/core.class.php';
require_once __DIR__ . '/fixtures/ai_assistant_database.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Database;
use MajorDoMo\Modules\AiAssistant\{AgentCatalog, ConnectionProbe, Endpoint, ModelCatalog, ProviderFactory, Schema, SecretVault, StandardProfiles};

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
        check(str_contains($failure->getMessage(), $message), 'Ожидаемая причина отказа: ' . $message);
        return;
    }
    throw new RuntimeException('Операция должна быть отклонена: ' . $message);
}

$fixture = sys_get_temp_dir() . '/mjd-ai-models-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
try {
    Config::boot($fixture);
    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->exec('PRAGMA foreign_keys=ON');
    check(Config::string('APP_ENV') === 'testing' && Config::string('DB_HOST') === ''
        && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Подтверждена изоляция testing/SQLite.');
    $db = new Database(new AiAssistantDatabaseFixture($pdo));
    Schema::install($db, true);
    $vault = new SecretVault(base64_encode(random_bytes(32)));
    $token = 'fixture-token-never-display';
    $encrypted = $vault->encrypt($token);
    check($encrypted !== $token && $vault->decrypt($encrypted) === $token, 'Токен шифруется и восстанавливается.');
    check($encrypted !== $vault->encrypt($token), 'Повторное шифрование использует новый nonce.');
    $tampered = base64_decode(substr($encrypted, 3), true);
    $tampered[28] = chr(ord($tampered[28]) ^ 1);
    rejects(fn() => $vault->decrypt('v1:' . base64_encode($tampered)), 'расшифровать');
    rejects(fn() => (new SecretVault(base64_encode(random_bytes(32))))->decrypt($encrypted), 'расшифровать');
    rejects(fn() => (new SecretVault('invalid-key'))->encrypt($token), '32 случайных байта');
    rejects(fn() => $vault->decrypt('v1:broken'), 'Повреждён');
    check($vault->encrypt('') === '' && $vault->decrypt('') === '', 'Локальная модель без токена не требует шифрования.');
    $fileVault = new SecretVault(null, $fixture . '/private');
    $fileCipher = $fileVault->encrypt($token);
    check((new SecretVault(null, $fixture . '/private'))->decrypt($fileCipher) === $token, 'Приватный ключ переживает новый экземпляр сервиса.');
    check(!is_file($fixture . '/.env'), 'Автоматический ключ не изменяет .env.');
    check(!array_key_exists('AI_SECRET_KEY', Config::safeAll()), 'Конфигурация не выдаёт ключ шифрования.');
    foreach (array('file:///tmp/test', 'http://user:password@localhost/v1', 'https://example.test/v1?key=secret', "http://localhost/\r\nx", 'https://example.test/v1#secret') as $url) {
        rejects(fn() => Endpoint::normalize($url), 'Укажите адрес');
    }
    check(Endpoint::normalize('http://127.0.0.1:4919/provider/v1/') === 'http://127.0.0.1:4919/provider/v1', 'Локальные API поддерживаются.');

    $catalog = new ModelCatalog($db, $vault);
    $providerInput = array('TITLE' => 'Локальный тест', 'BASE_URL' => 'http://127.0.0.1:4919/provider/v1', 'PROTOCOL' => 'openai_chat', 'TIMEOUT' => 1, 'ENABLED' => 1);
    $providerId = $catalog->saveProvider(0, $providerInput);
    $modelInput = array('PROVIDER_ID' => $providerId, 'TITLE' => 'Модель', 'MODEL' => 'fixture-model', 'TOKEN' => $token,
        'CAPABILITIES' => array('chat', 'tools', 'embeddings'), 'PARAMETERS' => array('temperature' => 0.2), 'CONTEXT_WINDOW' => 8192, 'MAX_OUTPUT' => 512, 'ENABLED' => 1);
    $modelId = $catalog->saveModel(0, $modelInput);
    check($catalog->model($modelId)['HAS_TOKEN'], 'Безопасная модель сообщает только наличие токена.');
    check(!str_contains(json_encode($catalog->models(), JSON_THROW_ON_ERROR), $token)
        && !array_key_exists('TOKEN_CIPHER', $catalog->model($modelId)), 'Список и карточка не содержат токен или шифротекст.');
    check(!str_contains($pdo->query('SELECT TOKEN_CIPHER FROM ai_assistant_models')->fetchColumn(), $token), 'В БД нет открытого токена.');
    $updated = $modelInput;
    unset($updated['TOKEN']);
    $catalog->saveModel($modelId, $updated);
    check($catalog->connection($modelId, 'chat')['token'] === $token, 'Отсутствующее поле не затирает сохранённый токен.');
    $updated['TOKEN'] = '';
    $catalog->saveModel($modelId, $updated);
    check(!$catalog->model($modelId)['HAS_TOKEN'] && $catalog->connection($modelId, 'chat')['token'] === '', 'Явный пустой токен очищает секрет.');
    rejects(fn() => $catalog->saveModel(0, array_replace($modelInput, array('CAPABILITIES' => array(array('chat'))))), 'Неизвестная возможность');
    rejects(fn() => $catalog->saveModel(0, array_replace($modelInput, array('CAPABILITIES' => array('tools')))), 'должна поддерживать чат');
    rejects(fn() => $catalog->saveModel(0, array_replace($modelInput, array('PARAMETERS' => array('model' => 'override')))), 'Допустимы параметры');
    rejects(fn() => $catalog->saveModel(0, array_replace($modelInput, array('MAX_OUTPUT' => 10000))), 'MAX_OUTPUT');
    rejects(fn() => $catalog->saveProvider(0, array_replace($providerInput, array('TIMEOUT' => 0))), 'TIMEOUT');
    rejects(fn() => $catalog->saveProvider(0, array_replace($providerInput, array('PROTOCOL' => 'arbitrary'))), 'Поддерживается протокол');
    rejects(fn() => $catalog->deleteProvider($providerId), 'есть модели');
    $pdo->exec("INSERT INTO ai_assistant_model_links VALUES ($modelId,'agent',1)");
    rejects(fn() => $catalog->deleteModel($modelId), 'используется');
    $catalog->saveProvider($providerId, array_replace($providerInput, array('ENABLED' => 0)));
    rejects(fn() => $catalog->connection($modelId, 'chat'), 'Провайдер выключен');
    $catalog->saveProvider($providerId, $providerInput);
    $catalog->saveModel($modelId, array_replace($updated, array('ENABLED' => 0)));
    rejects(fn() => $catalog->connection($modelId, 'chat'), 'Модель выключена');
    $catalog->saveModel($modelId, $updated);
    $second = $catalog->saveModel(0, array_replace($modelInput, array('TITLE' => 'Вторая модель', 'TOKEN' => 'other-fixture-token')));
    check(count($catalog->models()) === 2 && $catalog->connection($second, 'chat')['token'] === 'other-fixture-token', 'У провайдера несколько моделей с отдельными токенами.');
    Schema::install($db, true);
    check(count($catalog->models()) === 2, 'Обновление схемы сохраняет модели и секреты.');

    $agents = new AgentCatalog($db, $catalog);
    check(count($agents->all()) === 2, 'Созданы два стандартных агента.');
    $profiles = array_column($agents->all(), null, 'PROFILE');
    foreach (array('assistant', 'editor') as $profile) {
        $record = $profiles[$profile];
        check($record['INSTRUCTIONS'] === StandardProfiles::instructions($profile) && $record['MODEL_ID'] === null,
            'Профиль имеет инструкции и требует выбора модели: ' . $profile);
        $fake = new NeuronAI\Testing\FakeAIProvider(new NeuronAI\Chat\Messages\AssistantMessage('OK'));
        $agent = NeuronAI\Agent\Agent::make('profile-fixture-' . $profile)->setAiProvider($fake)->setInstructions($record['INSTRUCTIONS']);
        check($agent->chat(new NeuronAI\Chat\Messages\UserMessage('Проверка.'))->getMessage()?->getContent() === 'OK'
            && $fake->getRecorded()[0]->systemPrompt?->getContent() === $record['INSTRUCTIONS'], 'Профиль работает через общий механизм Neuron: ' . $profile);
    }
    $assistantId = (int)$profiles['assistant']['ID'];
    $draft = $profiles['assistant'];
    $draft['MODEL_ID'] = $modelId;
    $draft['INSTRUCTIONS'] = 'Пользовательские инструкции. Сначала уточняй существенные условия.';
    $agents->save($assistantId, $draft);
    check($agents->get($assistantId)['INSTRUCTIONS'] === $draft['INSTRUCTIONS'], 'Инструкции агента редактируются.');
    check($pdo->query("SELECT MODEL_ID FROM ai_assistant_model_links WHERE OWNER_TYPE='agent' AND OWNER_ID=$assistantId")->fetchColumn() == $modelId,
        'Назначение агента регистрирует использование модели.');
    rejects(fn() => $agents->save($assistantId, $draft), 'уже изменён');
    Schema::install($db, true);
    StandardProfiles::seed($db, true);
    check($agents->get($assistantId)['INSTRUCTIONS'] === $draft['INSTRUCTIONS'], 'Повторная установка и обновление стандартов не затирают инструкции.');
    $copyId = $agents->copy($assistantId, 'Своя задача');
    check($agents->get($copyId)['PROFILE'] === 'custom' && $agents->get($copyId)['INSTRUCTIONS'] === $draft['INSTRUCTIONS'], 'Копия профиля становится отдельным настраиваемым агентом.');
    rejects(fn() => $agents->restoreInstructions($copyId, 1), 'нет стандартного профиля');
    $unconfiguredId = $agents->save(0, array_replace($draft, array('TITLE' => 'Без модели', 'MODEL_ID' => 0)));
    check($agents->get($unconfiguredId)['MODEL_ID'] === null, 'Необязательная модель сохраняется как SQL NULL с учётом штатного Insert.');
    $agents->restoreInstructions($assistantId, (int)$agents->get($assistantId)['REVISION']);
    check($agents->get($assistantId)['INSTRUCTIONS'] === StandardProfiles::instructions('assistant')
        && (int)$agents->get($assistantId)['MODEL_ID'] === $modelId, 'Восстановление инструкций сохраняет выбранную модель.');
    $embeddingsOnly = $catalog->saveModel(0, array_replace($modelInput, array('TITLE' => 'Только эмбеддинги', 'CAPABILITIES' => array('embeddings'))));
    $draft = $agents->get($assistantId);
    rejects(fn() => $agents->save($assistantId, array_replace($draft, array('MODEL_ID' => $embeddingsOnly))), 'не поддерживает чат');
    rejects(fn() => $agents->save($assistantId, array_replace($draft, array('TOOLS' => array('execute_php')))), 'Неизвестное разрешение');
    rejects(fn() => $catalog->deleteModel($modelId), 'используется');
    rejects(fn() => $agents->delete($assistantId, 1), 'уже изменён');

    $baseUrl = $argv[1] ?? '';
    if ($baseUrl !== '') {
        $parts = parse_url($baseUrl);
        if (($parts['scheme'] ?? '') !== 'http' || ($parts['host'] ?? '') !== '127.0.0.1') throw new RuntimeException('HTTP-проверка разрешена только с локальной simulation на 127.0.0.1.');
        $catalog->saveProvider($providerId, array_replace($providerInput, array('BASE_URL' => Endpoint::normalize($baseUrl))));
        $probe = new ConnectionProbe(new ProviderFactory($catalog));
        $run = static function (string $model, string $capability = 'chat', string $secret = '') use ($catalog, $modelInput, $probe): array {
            $id = $catalog->saveModel(0, array_replace($modelInput, array('TITLE' => $model, 'MODEL' => $model, 'TOKEN' => $secret)));
            return $probe->check($id, $capability);
        };
        check($run('fixture-model')['ok'], 'Реальный HTTP-клиент Neuron получает ответ чата от simulation.');
        check($run('fixture-model', 'tools')['ok'], 'Модель вернула допустимый вызов Tools без его выполнения.');
        check($run('fixture-model', 'embeddings')['dimensions'] === 3, 'Эмбеддинги получены реальным клиентом Neuron.');
        check($run('without-token')['ok'], 'При пустом токене заголовок Authorization не отправляется.');
        check($run('no-tools', 'tools')['code'] === 'tools_unconfirmed', 'Обычный ответ не выдаётся за подтверждение Tools.');
        foreach (array('status-401' => 'authentication', 'status-404' => 'not_found', 'status-429' => 'rate_limit', 'status-500' => 'upstream',
            'redirect' => 'redirect', 'same-origin-redirect' => 'redirect', 'timeout' => 'timeout', 'invalid-json' => 'invalid_response', 'invalid-shape' => 'invalid_response') as $name => $reason) {
            $result = $run($name, 'chat', $token);
            check(!$result['ok'] && $result['code'] === $reason, 'HTTP-ошибка обработана: ' . $name . ' → ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            check(!str_contains(json_encode($result), $token) && !str_contains(json_encode($result), 'upstream-fixture-secret'), 'Ответ ошибки не раскрывает секреты провайдера.');
        }
    }
    foreach ($agents->all() as $record) $agents->delete((int)$record['ID'], (int)$record['REVISION']);
    check($agents->all() === array(), 'Удалённые агенты исключены из выбора.');
    StandardProfiles::seed($db, true);
    check($agents->all() === array(), 'Удалённые стандартные агенты не восстанавливаются без запроса.');
    $pdo->exec('DELETE FROM ai_assistant_model_links');
    foreach ($catalog->models() as $model) $catalog->deleteModel((int)$model['ID']);
    $catalog->deleteProvider($providerId);
    check($catalog->providers() === array() && $catalog->models() === array(), 'Неиспользуемые модели и провайдер удаляются штатно.');
} finally {
    $resolved = realpath($fixture);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved === false || $temporaryRoot === false || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $temporaryRoot) . '/mjd-ai-models-')) {
        throw new RuntimeException('Небезопасный путь очистки тестовой фикстуры.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($resolved);
}
echo 'Модели ИИ: ' . $checks . ' проверок пройдено (SQLite; HTTP ' . (($baseUrl ?? '') === '' ? 'не запускался' : 'локальная Postman simulation') . ').' . PHP_EOL;
