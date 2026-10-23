<?php

declare(strict_types=1);

// 2026-10-23 — сборка, переключение и поиск индекса знаний без внешней сети.

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
use MajorDoMo\Modules\AiAssistant\{KnowledgeIndex, KnowledgeSettings, ModelCatalog, QdrantClient, Schema, SecretVault};
use NeuronAI\HttpClient\{HttpClientInterface, HttpRequest, HttpResponse, StreamInterface};

$checks = 0;
function check(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($message);
}
json_encode(array(), JSON_THROW_ON_ERROR);
const EXTRA_CHECKS = 0;

final class AiIndexHttp implements HttpClientInterface
{
    /** @var list<HttpRequest> */
    public array $requests = array();
    /** @var array<string,array> */
    public array $points = array();
    public bool $failEmbeddings = false;

    public function request(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $path = (string)parse_url($request->uri, PHP_URL_PATH);
        if (str_ends_with($path, '/embeddings')) {
            if ($this->failEmbeddings) return new HttpResponse(500, '{"error":"boom"}');
            $input = $request->body['input'] ?? array();
            $items = array();
            foreach ((array)$input as $index => $text) {
                $items[] = array('index' => $index, 'embedding' => array(1.0, 0.5, 0.25, 0.125));
            }
            return new HttpResponse(200, json_encode(array('data' => $items), JSON_THROW_ON_ERROR));
        }
        if (str_ends_with($path, '/points') && $request->method->value === 'PUT') {
            foreach (($request->body['points'] ?? array()) as $point) $this->points[(string)$point['id']] = $point;
            return new HttpResponse(200, '{"status":"ok","result":true}');
        }
        if (str_ends_with($path, '/points/search')) {
            $must = $request->body['filter']['must'] ?? array();
            $audience = array();
            $install = '';
            foreach ((array)$must as $condition) {
                if (($condition['key'] ?? '') === 'install') $install = (string)($condition['match']['value'] ?? '');
                if (($condition['key'] ?? '') === 'audience') $audience = (array)($condition['match']['any'] ?? array());
            }
            $result = array();
            foreach ($this->points as $point) {
                $payload = $point['payload'];
                if (($payload['install'] ?? '') !== $install) continue;
                if ($audience !== array() && !in_array($payload['audience'] ?? '', $audience, true)) continue;
                $result[] = array('score' => 0.9, 'payload' => $payload);
                if (count($result) >= (int)($request->body['limit'] ?? 5)) break;
            }
            return new HttpResponse(200, json_encode(array('status' => 'ok', 'result' => $result), JSON_THROW_ON_ERROR));
        }
        if (str_ends_with($path, '/collections') && $request->method->value === 'GET') {
            return new HttpResponse(200, '{"status":"ok","result":{"collections":[]}}');
        }
        return new HttpResponse(200, '{"status":"ok","result":true}');
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw new LogicException('Поток не нужен.');
    }
}

function isModuleInstalled(string $name): bool { return $name === 'demo'; }

$fixture = sys_get_temp_dir() . '/mjd-ai-index-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
try {
    foreach (array('docs/user', 'modules/demo/docs', 'var') as $directory) mkdir($fixture . '/' . $directory, 0700, true);
    file_put_contents($fixture . '/docs/user/light.md', "---\ntitle: Свет\ncategory: user\n---\n# Свет\n\n"
        . "Свойство lightStatus хранит состояние лампы. Обработчик onLightChange меняет его.\n\n"
        . str_repeat('Дополнительный текст про освещение. ', 80));
    file_put_contents($fixture . '/docs/user/security.md', "---\ntitle: Охрана\n---\n# Охрана\nСценарий охраны и датчики движения.\n");
    file_put_contents($fixture . '/modules/demo/docs/guide.md', "---\ntitle: Модуль\n---\n# Модуль\nДокументация установленного модуля.\n");
    Config::boot($fixture);
    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->exec('PRAGMA foreign_keys=ON');
    $GLOBALS['db'] = new AiAssistantDatabaseFixture($pdo);
    $database = new Database($GLOBALS['db']);
    Schema::install($database, true);

    $vault = new SecretVault(base64_encode(random_bytes(32)));
    $settings = new KnowledgeSettings($database, $vault);
    $models = new ModelCatalog($database, $vault);
    $provider = $models->saveProvider(0, array('TITLE' => 'Локально', 'BASE_URL' => 'http://127.0.0.1:4919/v1',
        'PROTOCOL' => 'openai_chat', 'TIMEOUT' => 1, 'ENABLED' => 1));
    $model = $models->saveModel(0, array('PROVIDER_ID' => $provider, 'TITLE' => 'Векторы', 'MODEL' => 'embed',
        'CAPABILITIES' => array('embeddings'), 'PARAMETERS' => array(), 'CONTEXT_WINDOW' => 8192,
        'MAX_OUTPUT' => 512, 'ENABLED' => 1, 'EMBEDDING_PRIORITY' => 1));
    $settings->save(array('QDRANT_URL' => 'http://127.0.0.1:4919/qdrant', 'TIMEOUT' => 1,
        'REVISION' => $settings->get()['REVISION'], 'TOKEN' => ''));
    $settings->saveIndex(array('EMBEDDING_MODEL_ID' => $model, 'CHUNK_SIZE' => 300, 'CHUNK_OVERLAP' => 40,
        'TOP_K' => 3, 'MAX_CONTEXT' => 5000, 'VECTOR_NAME' => '', 'VECTOR_SIZE' => 0,
        'DISTANCE' => 'Cosine', 'HNSW_M' => 16, 'HNSW_EF_CONSTRUCT' => 100,
        'REVISION' => $settings->get()['REVISION']), $models);

    $http = new AiIndexHttp();
    $index = new KnowledgeIndex($database, $settings, $models,
        new MajorDoMo\Modules\AiAssistant\ProviderFactory($models, $http), new QdrantClient($http), $fixture);

    $start = $index->start($model);
    check($start['STATUS'] === 'building' && (int)$start['CHUNKS'] > 0, 'Сборка начата и план фрагментов посчитан.');
    $guard = 0;
    do {
        $progress = $index->step($model, 8);
        $guard++;
    } while ($progress['status'] === 'building' && $guard < 100);
    check($progress['status'] === 'ready' && $progress['active'] === true, 'Индекс собран и активен.');
    check($progress['dimension'] === 4, 'Размерность вектора зафиксирована.');
    $built = $index->rows()[0];
    check($built['DISTANCE'] === 'Cosine' && (int)$built['HNSW_M'] === 16 && (int)$built['HNSW_EF_CONSTRUCT'] === 100,
        'Параметры коллекции сохранены в строке индекса.');
    $createdRequests = array_values(array_filter($http->requests, static fn($request): bool =>
        $request->method->value === 'PUT' && str_contains($request->uri, '/collections/mdai_')
        && !str_contains($request->uri, '/points')));
    check(isset($createdRequests[0])
        && ($createdRequests[0]->body['hnsw_config']['m'] ?? 0) === 16
        && ($createdRequests[0]->body['hnsw_config']['ef_construct'] ?? 0) === 100,
        'HNSW передан в Qdrant при создании коллекции.');
    check(count($http->points) > 0, 'Точки записаны в Qdrant.');
    foreach ($http->points as $point) {
        check(($point['payload']['install'] ?? '') === $settings->installKey(), 'Каждая точка содержит ключ установки.');
        check(in_array($point['payload']['audience'] ?? '', array('core', 'modules'), true), 'Аудитория фрагмента указана.');
    }
    check(str_contains(implode(' ', array_map(static fn($r) => (string)$r->uri, $http->requests)), '/points/search') === false,
        'Во время сборки поиск не выполняется.');

    $found = $index->search('lightStatus', array('core'), 3);
    check($found['mode'] === 'rag' && count($found['items']) > 0, 'RAG-поиск вернул фрагменты.');
    $haystack = implode(' ', array_map(static fn(array $item): string => $item['title'] . ' ' . $item['text'], $found['items']));
    check(str_contains($haystack, 'lightStatus') || str_contains($haystack, 'Свет'), 'Фрагменты содержат текст статьи.');
    check(str_contains($found['context'], 'Источник:'), 'Контекст содержит ссылку на источник.');
    $moduleFound = $index->search('модуль', array('modules'), 3);
    check(count($moduleFound['items']) > 0 && $moduleFound['items'][0]['source'] === 'demo', 'Фильтр источников учитывает модули.');

    // Смена документа: новый запуск пишет в новую коллекцию, старая удаляется после успеха.
    $firstCollection = '';
    foreach ($index->rows() as $row) $firstCollection = $row['COLLECTION'];
    file_put_contents($fixture . '/docs/user/security.md', "---\ntitle: Охрана\n---\n# Охрана\nОбновлённый сценарий охраны и датчики.\n");
    $index->start($model);
    $guard = 0;
    do { $progress = $index->step($model, 8); $guard++; } while ($progress['status'] === 'building' && $guard < 100);
    $secondCollection = '';
    foreach ($index->rows() as $row) $secondCollection = $row['COLLECTION'];
    check($progress['status'] === 'ready' && $secondCollection !== $firstCollection, 'Новый индекс собран в отдельной коллекции.');
    $deleted = array_filter($http->requests, static fn(HttpRequest $request): bool =>
        $request->method->value === 'DELETE' && str_contains($request->uri, (string)$firstCollection));
    check($deleted !== array(), 'Старая коллекция удалена после переключения.');
    check($http->points !== array(), 'Точки нового индекса записаны.');

    // Ошибка сборки не должна выключать рабочий индекс.
    $http->failEmbeddings = true;
    file_put_contents($fixture . '/docs/user/light.md', "---\ntitle: Свет\n---\n# Свет\nЕщё одно изменение текста.\n");
    $index->start($model);
    $failed = false;
    try { $index->step($model, 8); } catch (RuntimeException) { $failed = true; }
    check($failed, 'Ошибка эмбеддингов останавливает сборку.');
    $active = array_values(array_filter($index->rows(), static fn(array $row): bool => (int)$row['ACTIVE'] === 1));
    check(count($active) === 1 && $active[0]['STATUS'] === 'ready', 'Рабочий индекс сохранён при ошибке.');
    $http->failEmbeddings = false;

    // Отмена сборки не трогает рабочий индекс.
    $index->start($model);
    $index->cancel($model);
    $active = array_values(array_filter($index->rows(), static fn(array $row): bool => (int)$row['ACTIVE'] === 1));
    check(count($active) === 1 && $active[0]['STATUS'] === 'ready', 'Отмена сборки не отключила рабочий индекс.');

    echo 'ИИ-помощник индекс: ' . $checks . ' проверок пройдено (SQLite, Qdrant simulation, без сети).' . PHP_EOL;
} finally {
    $resolved = realpath($fixture);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved === false || $temporaryRoot === false
        || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $temporaryRoot) . '/mjd-ai-index-')) {
        throw new RuntimeException('Небезопасный путь очистки тестовой фикстуры.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($resolved);
}
