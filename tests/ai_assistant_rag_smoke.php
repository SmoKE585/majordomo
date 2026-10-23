<?php

declare(strict_types=1);

// 2026-10-23 — опциональный Qdrant, режим RAG и порядок эмбеддингов на изолированной SQLite.

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('DB_HOST=');
putenv('DB_NAME=');
putenv('USE_REDIS=false');
putenv('AI_SECRET_KEY=');
require_once dirname(__DIR__) . '/lib/core.class.php';
require_once __DIR__ . '/fixtures/ai_assistant_database.php';

use MajorDoMo\Core\{Config, Database};
use MajorDoMo\Modules\AiAssistant\{AgentCatalog, KnowledgeSettings, ModelCatalog, QdrantClient, RagAvailability, Schema, SecretVault};
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\{HttpClientInterface, HttpRequest, HttpResponse, StreamInterface};

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
    throw new RuntimeException('Ожидался отказ: ' . $message);
}

/**
 * Контрактные крайние случаи; отдельный прогон ниже проверяет настоящий cURL.
 * Изменения:
 * 2026-10-23 — захват только синтетического запроса без внешнего доступа.
 */
final class AiQdrantHttpFixture implements HttpClientInterface
{
    public ?HttpRequest $last = null;
    public int $calls = 0;
    public bool $networkError = false;
    public function __construct(public HttpResponse $response) {}
    public function request(HttpRequest $request): HttpResponse
    {
        $this->calls++;
        $this->last = $request;
        if ($this->networkError) throw HttpException::networkError($request, 'fixture-secret-do-not-leak');
        return $this->response;
    }
    public function stream(HttpRequest $request): StreamInterface { throw new LogicException('Потоковый запрос Qdrant не предусмотрен.'); }
}

$fixture = sys_get_temp_dir() . '/mjd-ai-rag-' . bin2hex(random_bytes(6));
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
    $settings = new KnowledgeSettings($db, $vault);
    $models = new ModelCatalog($db, $vault);
    $http = new AiQdrantHttpFixture(new HttpResponse(200, '{"status":"ok","result":{"collections":[]}}'));
    $qdrant = new QdrantClient($http);
    $rag = new RagAvailability($settings, $models, $qdrant);
    $agents = new AgentCatalog($db, $models, $rag);
    check($rag->status()['reason'] === 'not_configured' && $http->calls === 0, 'Отсутствие Qdrant определяется без сетевого запроса.');
    $agent = $agents->all()[0];
    $agent['MODEL_ID'] = 0;
    check($agent['KNOWLEDGE_MODE'] === 'text', 'Стандартный агент не требует Qdrant.');
    $agentId = $agents->save(0, $agent);
    check($agentId > 0 && $http->calls === 0, 'Создание обычного агента не обращается к Qdrant.');
    $agent['KNOWLEDGE_MODE'] = 'rag';
    $before = count($agents->all());
    rejects(fn() => $agents->save(0, $agent), 'Qdrant не подключён');
    check(count($agents->all()) === $before, 'Отказ не оставляет частично созданного агента.');
    rejects(fn() => $agents->save(0, array_replace($agent, array('SOURCES' => array()))), 'выберите источники');
    rejects(fn() => $agents->save(0, array_replace($agent, array('KNOWLEDGE_MODE' => 'unknown'))), 'текстовый поиск или RAG');
    $input = array('QDRANT_URL' => 'http://127.0.0.1:4919/qdrant', 'TOKEN' => 'qdrant-fixture-secret', 'TIMEOUT' => 1, 'REVISION' => 1);
    $settings->save($input);
    check(!str_contains(json_encode($settings->get()), $input['TOKEN']) && !array_key_exists('TOKEN_CIPHER', $settings->get()), 'Безопасные настройки не выдают секрет.');
    check(!str_contains($pdo->query('SELECT TOKEN_CIPHER FROM ai_assistant_knowledge')->fetchColumn(), $input['TOKEN']), 'Ключ Qdrant зашифрован.');
    check($settings->connection()['token'] === $input['TOKEN'], 'Ключ доступен только серверному подключению.');
    check($rag->status()['reason'] === 'embeddings_missing', 'Один Qdrant без моделей эмбеддингов не разрешает RAG.');
    check($http->last?->headers['api-key'] === $input['TOKEN'] && $http->last?->timeout === 1.0, 'Контракт API-ключа и таймаута соблюдён.');
    rejects(fn() => $settings->save($input), 'уже изменены');
    $input['REVISION'] = 2;
    unset($input['TOKEN']);
    $settings->save($input);
    check($settings->connection()['token'] === 'qdrant-fixture-secret', 'Отсутствующее поле сохраняет ключ.');
    $input['REVISION'] = 3;
    rejects(fn() => $settings->save(array_replace($input, array('QDRANT_URL' => 'http://127.0.0.1:4919/other'))), 'задайте ключ заново');
    $input['TOKEN'] = '';
    $settings->save($input);
    check($settings->connection()['token'] === '', 'Явно пустое поле очищает ключ.');

    $providerInput = array('TITLE' => 'Локальный тест', 'BASE_URL' => 'http://127.0.0.1:4919/provider/v1', 'PROTOCOL' => 'openai_chat', 'TIMEOUT' => 1, 'ENABLED' => 1);
    $provider = $models->saveProvider(0, $providerInput);
    $modelInput = array('PROVIDER_ID' => $provider, 'TITLE' => 'Эмбеддинги', 'MODEL' => 'fixture-model', 'TOKEN' => '',
        'CAPABILITIES' => array('embeddings'), 'PARAMETERS' => array(), 'CONTEXT_WINDOW' => 8192, 'MAX_OUTPUT' => 512, 'ENABLED' => 1);
    $first = $models->saveModel(0, $modelInput + array('EMBEDDING_PRIORITY' => 20));
    $second = $models->saveModel(0, $modelInput + array('EMBEDDING_PRIORITY' => 10));
    $third = $models->saveModel(0, $modelInput + array('EMBEDDING_PRIORITY' => 10));
    check(array_column($models->embeddingModels(), 'ID') === array($second, $third, $first), 'Модели упорядочены по приоритету и ID.');
    $models->saveModel($second, array_replace($modelInput, array('ENABLED' => 0)));
    check(array_column($models->embeddingModels(), 'ID') === array($third, $first), 'Выключенная модель пропускается.');
    check((int)$models->model($second)['EMBEDDING_PRIORITY'] === 10, 'Правка без поля приоритета сохраняет порядок.');
    $models->saveProvider($provider, array_replace($providerInput, array('ENABLED' => 0)));
    check($models->embeddingModels() === array(), 'Выключенный провайдер исключает все свои модели.');
    $models->saveProvider($provider, $providerInput);
    rejects(fn() => $models->saveModel(0, $modelInput + array('EMBEDDING_PRIORITY' => 0)), 'EMBEDDING_PRIORITY');
    check($rag->status()['available'], 'Пустой доступный Qdrant и включённая embedding-модель разрешают создание RAG-агента до индексирования.');
    $application = new MajorDoMo\Core\Console\Application($fixture);
    $command = new MajorDoMo\Core\Console\Commands\AiRagCheckCommand(fn() => $rag);
    $stdout = fopen('php://temp', 'w+');
    $stderr = fopen('php://temp', 'w+');
    $output = new MajorDoMo\Core\Console\Output($stdout, $stderr);
    $output->setJson(true);
    $context = new MajorDoMo\Core\Console\Context($fixture, $application);
    $code = $command->execute(new MajorDoMo\Core\Console\Input(array('--json'), $command), $output, $context);
    $output->finish($code);
    rewind($stdout);
    check($code === 0 && str_contains(stream_get_contents($stdout), 'ready'), 'CLI возвращает JSON и успех через тот же сервис диагностики.');
    fclose($stdout);
    fclose($stderr);
    check(!array_key_exists('api-key', $http->last->headers), 'Без ключа не отправляется пустой заголовок авторизации.');
    $ragId = $agents->save(0, $agent);
    check($agents->get($ragId)['KNOWLEDGE_MODE'] === 'rag', 'Режим RAG сохранён после живой проверки доступности.');
    $http->networkError = true;
    rejects(fn() => $agents->copy($ragId, 'Копия RAG'), 'Не удалось соединиться');
    rejects(fn() => $rag->requireAvailable(), 'Не удалось соединиться');
    check(!str_contains(json_encode($rag->status()), 'fixture-secret'), 'Сетевая ошибка не выдаёт ключ или исходное исключение.');
    $http->networkError = false;
    foreach (array(401 => 'authentication', 403 => 'authentication', 302 => 'redirect', 404 => 'not_found', 429 => 'rate_limit', 500 => 'upstream') as $status => $reason) {
        $http->response = new HttpResponse($status, 'fixture-secret-do-not-leak');
        check($rag->status()['reason'] === $reason && !str_contains(json_encode($rag->status()), 'fixture-secret'), 'Безопасный отказ HTTP ' . $status);
    }
    foreach (array('<html>secret</html>', '{}', '{"status":"ok","result":{"collections":{}}}', '{"status":"ok","result":{"collections":[{}]}}', str_repeat('x', 1024 * 1024 + 1)) as $body) {
        $http->response = new HttpResponse(200, $body);
        check($rag->status()['reason'] === 'invalid_response', 'Некорректный контракт не считается доступным Qdrant.');
    }

    $http->response = new HttpResponse(200, '{"status":"ok","result":{"collections":[]}}');
    $input['REVISION'] = 4;
    $input['QDRANT_URL'] = '';
    $settings->save($input);
    check($rag->status()['reason'] === 'not_configured', 'Отключённый Qdrant блокирует ранее настроенный RAG.');
    $stdout = fopen('php://temp', 'w+');
    $stderr = fopen('php://temp', 'w+');
    $output = new MajorDoMo\Core\Console\Output($stdout, $stderr);
    check($command->execute(new MajorDoMo\Core\Console\Input(array(), $command), $output, $context) === 1, 'CLI возвращает код 1 при отсутствии Qdrant.');
    fclose($stdout);
    fclose($stderr);
    $stdout = fopen('php://temp', 'w+');
    $stderr = fopen('php://temp', 'w+');
    check($application->run(array('ai:rag-check', '--help', '--json'), $stdout, $stderr) === 0, 'Справка зарегистрированной команды не загружает БД или Qdrant.');
    fclose($stdout);
    fclose($stderr);
    $edit = $agents->get($ragId);
    $edit['MODEL_ID'] = 0;
    $edit['KNOWLEDGE_MODE'] = 'text';
    $agents->save($ragId, $edit);
    check($agents->get($ragId)['KNOWLEDGE_MODE'] === 'text', 'При отключённом Qdrant можно перевести агента на текстовый поиск.');
    $pdo->exec('UPDATE ai_assistant_settings SET SCHEMA_VERSION=3');
    Schema::install($db, true);
    check($settings->get()['QDRANT_URL'] === '' && (int)$models->model($first)['EMBEDDING_PRIORITY'] === 20,
        'Повтор после прерванной миграции не сбрасывает настройки и приоритеты.');

    if (isset($argv[1])) {
        $base = EndpointForTest($argv[1]);
        $real = new QdrantClient();
        $connection = array('url' => $base, 'token' => '', 'timeout' => 1);
        check($real->probe($connection)['available'], 'Настоящий cURL получил контракт Qdrant от локальной Postman simulation.');
        foreach (array('status-401' => 'authentication', 'status-500' => 'upstream', 'redirect' => 'redirect', 'timeout' => 'timeout', 'invalid-json' => 'invalid_response', 'invalid-shape' => 'invalid_response') as $path => $reason) {
            $result = $real->probe(array_replace($connection, array('url' => $base . '/' . $path)));
            check($result['reason'] === $reason && !str_contains(json_encode($result), 'fixture-secret'), 'HTTP simulation: ' . $path);
        }
    }
    echo 'OK: ' . $checks . " checks (SQLite, Qdrant " . (isset($argv[1]) ? 'HTTP simulation' : 'contract fixtures') . ").\n";
} finally {
    $resolved = realpath($fixture);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved === false || $temporaryRoot === false || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $temporaryRoot) . '/mjd-ai-rag-')) {
        throw new RuntimeException('Небезопасный путь очистки тестовой фикстуры.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($resolved);
}

function EndpointForTest(string $url): string
{
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'http' || ($parts['host'] ?? '') !== '127.0.0.1') throw new RuntimeException('HTTP-тест разрешён только на локальной simulation.');
    return MajorDoMo\Modules\AiAssistant\Endpoint::normalize($url);
}
