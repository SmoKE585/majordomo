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
    public bool $emptyEmbeddings = false;

    public function request(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $path = (string)parse_url($request->uri, PHP_URL_PATH);
        if (str_ends_with($path, '/embeddings')) {
            if ($this->failEmbeddings) return new HttpResponse(500, '{"error":"boom"}');
            $input = $request->body['input'] ?? array();
            $items = array();
            foreach ((array)$input as $index => $text) {
                $items[] = array('index' => $index, 'embedding' => $this->emptyEmbeddings && is_array($input)
                    ? array() : array(1.0, 0.5, 0.25, 0.125));
            }
            return new HttpResponse(200, json_encode(array('data' => $items), JSON_THROW_ON_ERROR));
        }
        if (str_ends_with($path, '/points') && $request->method->value === 'PUT') {
            foreach (($request->body['points'] ?? array()) as $point) $this->points[(string)$point['id']] = $point;
            return new HttpResponse(200, '{"status":"ok","result":true}');
        }
        if (str_ends_with($path, '/points/scroll')) {
            $install = $request->body['filter']['must'][0]['match']['value'];
            $points = array_values(array_filter($this->points, static fn(array $point): bool => $point['payload']['install'] === $install));
            return new HttpResponse(200, json_encode(array('status' => 'ok', 'result' => array('points' => $points, 'next_page_offset' => null)), JSON_THROW_ON_ERROR));
        }
        if (str_ends_with($path, '/points/delete')) {
            $must = $request->body['filter']['must'];
            $keep = $request->body['filter']['must_not'][0]['match']['value'] ?? null;
            foreach ($this->points as $id => $point) {
                if ($point['payload']['install'] === $must[0]['match']['value'] && $point['payload']['doc_id'] === $must[1]['match']['value']
                    && ($keep === null || $point['payload']['checksum'] !== $keep)) unset($this->points[$id]);
            }
            return new HttpResponse(200, '{"status":"ok","result":{"status":"completed"}}');
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
function setTimeOut($title, $commands, $timeout = 0, $expire = 0) { $GLOBALS['ai_fixture_timers'][$title] = array($commands, $timeout); return 1; }

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

    // Неизменённая справка не отправляется на эмбеддинги повторно.
    $embeddingCount = static fn(): int => count(array_filter($http->requests, static fn(HttpRequest $r): bool => str_ends_with($r->uri, '/embeddings')));
    $before = $embeddingCount();
    $sync = $index->synchronize($model, 8);
    check($sync['changed'] === 0 && $embeddingCount() === $before, 'Сверка неизменённых документов не запрашивает эмбеддинги.');
    file_put_contents($fixture . '/docs/user/security.md', "---\ntitle: Охрана\n---\n# Охрана\nИзменённый текст охраны и датчиков движения.\n");
    $collectionBefore = $index->rows()[0]['COLLECTION'];
    $sync = $index->synchronize($model, 8);
    check($sync['changed'] === 1 && $index->rows()[0]['COLLECTION'] === $collectionBefore, 'Изменён только один файл, коллекция сохранена.');
    check($embeddingCount() > $before, 'Эмбеддинги изменённого файла пересчитаны.');
    $unchangedBefore = $embeddingCount();
    $index->synchronize($model, 8);
    check($embeddingCount() === $unchangedBefore, 'Повторная сверка не переиндексирует уже обновлённый файл.');
    $firstPoint = array_key_first($http->points);
    unset($http->points[$firstPoint]);
    check($index->synchronize($model, 8)['changed'] === 1, 'Потерянный в Qdrant фрагмент восстанавливается по фактической коллекции.');
    unlink($fixture . '/modules/demo/docs/guide.md');
    check($index->synchronize($model, 8)['removed'] === 1, 'Удалённая справка убрана из коллекции.');
    $http->failEmbeddings = true;
    file_put_contents($fixture . '/docs/user/security.md', "---\ntitle: Охрана\n---\n# Охрана\nНовая редакция справки охраны.\n");
    $countBeforeFailure = count($http->points);
    $failedSync = false;
    try { $index->synchronize($model, 8); } catch (RuntimeException) { $failedSync = true; }
    check($failedSync && count($http->points) === $countBeforeFailure, 'Сбой эмбеддингов не удаляет прежнюю версию файла.');
    check($index->rows()[0]['ERROR'] !== '', 'Ошибка автоматической синхронизации видна у индекса.');
    $http->failEmbeddings = false;
    check($index->synchronize($model, 8)['changed'] === 1, 'Повтор после сбоя завершает обновление файла.');

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

    // Пустой вектор не теряет уже записанную часть staging-коллекции.
    file_put_contents($fixture . '/docs/user/light.md', "---\ntitle: Свет\n---\n# Свет\nЕщё одно изменение текста.\n"
        . str_repeat('Дополнительный фрагмент для возобновляемой индексации. ', 40));
    $index->start($model);
    $index->step($model, 1);
    $partial = $index->rows()[0];
    $http->emptyEmbeddings = true;
    $failed = false;
    try { $index->step($model, 1); } catch (RuntimeException $failure) { $failed = $failure->getMessage() === 'API вернул некорректный вектор.'; }
    check($failed, 'Пустой вектор останавливает сборку с понятной ошибкой.');
    $interrupted = $index->rows()[0];
    check($interrupted['STATUS'] === 'error' && $interrupted['COLLECTION'] === $partial['COLLECTION']
        && (int)$interrupted['PROCESSED'] === (int)$partial['PROCESSED'], 'Ошибка сохраняет коллекцию и обработанный прогресс.');
    check((int)$interrupted['PREVIOUS_ACTIVE'] === 1 && $interrupted['PREVIOUS_COLLECTION'] !== '', 'Предыдущий рабочий индекс остаётся доступен во время ошибки.');
    $http->emptyEmbeddings = false;
    $resumed = $index->start($model);
    check($resumed['STATUS'] === 'building' && $resumed['COLLECTION'] === $partial['COLLECTION']
        && (int)$resumed['PROCESSED'] === (int)$partial['PROCESSED'], 'Повторный запуск продолжает сохранённую сборку без повторных фрагментов.');
    do { $progress = $index->step($model, 8); } while ($progress['status'] === 'building');
    check($progress['status'] === 'ready' && $index->rows()[0]['COLLECTION'] === $partial['COLLECTION'], 'Продолженная сборка переключает готовую staging-коллекцию.');

    // Отмена сборки не трогает рабочий индекс.
    $index->start($model);
    $index->cancel($model);
    $active = array_values(array_filter($index->rows(), static fn(array $row): bool => (int)$row['ACTIVE'] === 1));
    check(count($active) === 1 && $active[0]['STATUS'] === 'ready', 'Отмена сборки не отключила рабочий индекс.');

    $httpBase = $argv[1] ?? '';
    if ($httpBase !== '') {
        if (parse_url($httpBase, PHP_URL_HOST) !== '127.0.0.1' || parse_url($httpBase, PHP_URL_SCHEME) !== 'http') throw new RuntimeException('Только локальная simulation.');
        $providerId = $models->saveProvider(0, array('TITLE' => 'Simulation', 'BASE_URL' => $httpBase . '/provider/v1', 'PROTOCOL' => 'openai_chat', 'TIMEOUT' => 2, 'ENABLED' => 1));
        $httpModel = $models->saveModel(0, array('PROVIDER_ID' => $providerId, 'TITLE' => 'HTTP embeddings', 'MODEL' => 'fixture-model', 'CAPABILITIES' => array('embeddings'), 'PARAMETERS' => array(), 'CONTEXT_WINDOW' => 8192, 'MAX_OUTPUT' => 512, 'ENABLED' => 1));
        $settings->save(array('QDRANT_URL' => $httpBase . '/qdrant/index', 'TIMEOUT' => 2, 'REVISION' => $settings->get()['REVISION'], 'TOKEN' => ''));
        $settings->saveIndex(array('EMBEDDING_MODEL_ID' => $httpModel, 'CHUNK_SIZE' => 300, 'CHUNK_OVERLAP' => 40, 'TOP_K' => 3, 'MAX_CONTEXT' => 5000,
            'VECTOR_NAME' => '', 'VECTOR_SIZE' => 3, 'DISTANCE' => 'Cosine', 'HNSW_M' => 16, 'HNSW_EF_CONSTRUCT' => 100, 'REVISION' => $settings->get()['REVISION']), $models);
        $real = new KnowledgeIndex($database, $settings, $models, new MajorDoMo\Modules\AiAssistant\ProviderFactory($models), new QdrantClient(), $fixture);
        $real->start($httpModel);
        do { $row = $real->step($httpModel, 8); } while ($row['status'] === 'building');
        check($row['status'] === 'ready' && $real->synchronize($httpModel)['changed'] === 0, 'Реальный HTTP: сборка и сверка фактической коллекции.');
        file_put_contents($fixture . '/docs/user/security.md', "---\ntitle: Охрана\n---\n# Охрана\nHTTP-обновление только одного документа охраны.\n");
        check($real->synchronize($httpModel)['changed'] === 1, 'Реальный HTTP: изменённый документ обновлён отдельно.');
        check($real->synchronize($httpModel)['changed'] === 0, 'Реальный HTTP: после обновления лишних фрагментов нет.');
        unlink($fixture . '/docs/user/security.md');
        check($real->synchronize($httpModel)['removed'] === 1, 'Реальный HTTP: удаление документа ограничено фильтром установки и файла.');
        file_put_contents($fixture . '/docs/user/automatic.md', "---\ntitle: Автоматическая справка\n---\n# Автоматическая справка\nНовый документ после обновления модуля.\n");
        MajorDoMo\Modules\AiAssistant\KnowledgeSync::run();
        check($real->synchronize($httpModel)['changed'] === 0, 'Фоновая сверка самостоятельно проиндексировала новый файл.');
        MajorDoMo\Modules\AiAssistant\KnowledgeSync::schedule();
        check(($GLOBALS['ai_fixture_timers']['ai_assistant.knowledge_sync'][1] ?? 0) === 30, 'Сверка назначается на штатный именованный таймер.');
        $pdo->exec("UPDATE ai_assistant_models SET MODEL='changed-fixture' WHERE ID=" . $httpModel);
        check($real->needsRebuild($httpModel), 'Смена модели в той же записи требует пересборки, а не смешивания векторов.');
        $pdo->exec("UPDATE ai_assistant_models SET MODEL='fixture-model' WHERE ID=" . $httpModel);

    }

    echo 'ИИ-помощник индекс: ' . $checks . ' проверок пройдено (SQLite, Qdrant fixtures; HTTP ' . ($httpBase !== '' ? 'локальная Postman simulation' : 'не запускался') . ').' . PHP_EOL;
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
