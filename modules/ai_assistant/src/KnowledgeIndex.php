<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use InvalidArgumentException;
use MajorDoMo\Core\Config;
use MajorDoMo\Core\Database;
use NeuronAI\RAG\Document;
use RuntimeException;
use Throwable;

/**
 * Сборка и поиск векторного индекса знаний на опциональном Qdrant.
 *
 * Каждый запуск пишет в отдельную staging-коллекцию: рабочий индекс не
 * заменяется, пока новый не готов. В общей установке Qdrant точки разделяются
 * ключом установки, а выдача — фильтром аудитории.
 *
 * Изменения:
 * 2026-10-23 — сверка коллекции по файлам, стабильные ID, подпись модели и блокировка обслуживания.
 * 2026-10-23 — параметры вектора и HNSW фиксируются в строке индекса.
 *
 * Изменения:
 * 2026-10-23 — пошаговая индексация, версия модели/размерности и поиск RAG.
 */
final class KnowledgeIndex
{
    public function __construct(
        private Database $db,
        private KnowledgeSettings $settings,
        private ModelCatalog $models,
        private ProviderFactory $providers,
        private QdrantClient $qdrant,
        private ?string $root = null
    ) {}

    public function rows(): array
    {
        $titles = array();
        foreach ($this->models->models() as $model) $titles[(int)$model['ID']] = $model['TITLE'];
        $rows = $this->db->select('SELECT * FROM ai_assistant_indexes ORDER BY ACTIVE DESC,ID');
        foreach ($rows as &$row) {
            $row['MODEL_TITLE'] = $titles[(int)$row['MODEL_ID']] ?? ('Модель #' . (int)$row['MODEL_ID']);
        }
        unset($row);
        return $rows;
    }

    /** Начать пересборку индекса выбранной модели; старый остаётся рабочим. */
    public function start(int $modelId): array
    {
        return $this->locked($modelId, fn(): array => $this->startBuild($modelId));
    }

    private function startBuild(int $modelId): array
    {
        $embedding = array_column($this->models->embeddingModels(), 'ID');
        if (!in_array($modelId, array_map('intval', $embedding), true)) {
            throw new InvalidArgumentException('Выберите включённую модель эмбеддингов у включённого провайдера.');
        }
        $this->settings->connection();
        $probe = $this->qdrant->probe($this->settings->connection());
        if (!$probe['available']) throw new RuntimeException($probe['hint']);
        $plan = $this->plan();
        $total = 0;
        foreach ($plan as $chunk) $total++;
        if ($total < 1) throw new RuntimeException('В базе знаний нет подходящих документов для индексации.');
        $hash = $this->planHash($plan);
        $this->storePlan($modelId, $hash, $plan);
        $suffix = bin2hex(random_bytes(4));
        $collection = 'mdai_' . substr($this->settings->installKey(), 0, 12) . '_' . $modelId . '_' . $suffix;
        $existing = $this->db->one('SELECT * FROM ai_assistant_indexes WHERE MODEL_ID=' . $modelId);
        if ($existing !== null && $existing['STATUS'] === 'building') {
            throw new RuntimeException('Сборка этого индекса уже идёт. Дождитесь завершения или остановите её.');
        }
        $previousActive = $existing !== null && (int)$existing['ACTIVE'] === 1 && (string)$existing['STATUS'] === 'ready';
        $vector = $this->settings->get();
        $record = array(
            'MODEL_ID' => $modelId,
            'MODEL_SIGNATURE' => $this->embeddingSignature($modelId),
            'COLLECTION' => $collection,
            'DIMENSION' => 0,
            'STATUS' => 'building',
            // Рабочий индекс продолжает обслуживать поиск из PREVIOUS_*,
            // пока новый не собран: ACTIVE остаётся включённым.
            'ACTIVE' => $previousActive ? 1 : 0,
            'DOCUMENTS' => count($this->documents()),
            'CHUNKS' => $total,
            'PROCESSED' => 0,
            'CHECKSUM' => $hash,
            'ERROR' => '',
            'VECTOR_NAME' => (string)$vector['VECTOR_NAME'],
            'VECTOR_SIZE' => (int)$vector['VECTOR_SIZE'],
            'DISTANCE' => (string)$vector['DISTANCE'],
            'HNSW_M' => (int)$vector['HNSW_M'],
            'HNSW_EF_CONSTRUCT' => (int)$vector['HNSW_EF_CONSTRUCT'],
            'PREVIOUS_COLLECTION' => $previousActive ? (string)$existing['COLLECTION'] : '',
            'PREVIOUS_STATUS' => $previousActive ? 'ready' : '',
            'PREVIOUS_ACTIVE' => $previousActive ? 1 : 0,
            'PREVIOUS_DIMENSION' => $previousActive ? (int)$existing['DIMENSION'] : 0,
            'PREVIOUS_VECTOR_NAME' => $previousActive ? (string)($existing['VECTOR_NAME'] ?? '') : '',
            'PREVIOUS_MODEL_SIGNATURE' => $previousActive ? (string)($existing['MODEL_SIGNATURE'] ?? '') : '',
            'CREATED_AT' => time(),
            'UPDATED_AT' => time(),
        );
        if ($existing !== null) {
            $record['ID'] = (int)$existing['ID'];
            $this->db->update('ai_assistant_indexes', $record);
        } else {
            $this->db->insert('ai_assistant_indexes', $record);
        }
        return $this->row($modelId);
    }

    /** Обработать порцию фрагментов; вызывается повторно, пока статус building. */
    public function step(int $modelId, int $batch = 24): array
    {
        return $this->locked($modelId, fn(): array => $this->stepBuild($modelId, $batch));
    }

    private function stepBuild(int $modelId, int $batch): array
    {
        $row = $this->row($modelId);
        if ($row === null) throw new RuntimeException('Запуск индексации не найден. Начните пересборку.');
        if ($row['STATUS'] !== 'building') return $this->publicRow($row);
        $plan = $this->loadPlan($modelId, (string)$row['CHECKSUM']);
        if ($plan === null) {
            $plan = $this->plan();
            if ($this->planHash($plan) !== (string)$row['CHECKSUM']) {
                $this->markError($modelId, 'Источники или параметры разбиения изменились во время сборки. Запустите пересборку заново.');
                throw new RuntimeException('Источники изменились во время сборки. Запустите пересборку заново.');
            }
            $this->storePlan($modelId, (string)$row['CHECKSUM'], $plan);
        }
        $batch = max(1, min(40, $batch));
        $slice = array_slice($plan, (int)$row['PROCESSED'], $batch);
        if ($slice === array()) {
            return $this->finalize($row, $plan);
        }
        $connection = $this->settings->connection();
        if (($connection['url'] ?? '') === '') throw new RuntimeException('Qdrant не подключён. Укажите адрес API в настройках знаний.');
        try {
            $documents = array();
            foreach ($slice as $chunk) $documents[] = new Document($chunk['text']);
            $embedded = $this->providers->embeddings($modelId)->embedDocuments($documents);
            if (count($embedded) !== count($slice)) throw new ProviderFailure('invalid_response', 'Провайдер вернул неверное число эмбеддингов.');
            $dimension = 0;
            $points = array();
            foreach ($slice as $index => $chunk) {
                $vector = $embedded[$index]->getEmbedding() ?? array();
                if ($vector === array()) throw new ProviderFailure('invalid_response', 'Провайдер вернул пустой вектор.');
                $dimension = count($vector);
                if ((int)$row['VECTOR_SIZE'] > 0 && (int)$row['VECTOR_SIZE'] !== $dimension) {
                    throw new ProviderFailure('invalid_vector', 'Модель вернула ' . $dimension . ' измерений, а в настройках указано '
                        . (int)$row['VECTOR_SIZE'] . '. Исправьте размерность или укажите 0 для автоопределения.');
                }
                if ((int)$row['DIMENSION'] > 0 && (int)$row['DIMENSION'] !== $dimension) {
                    throw new ProviderFailure('invalid_vector', 'Размерность векторов изменилась. Запустите пересборку с новой моделью.');
                }
                $points[] = array('id' => $this->pointId((string)$row['COLLECTION'], $chunk['doc_id'] . ':' . $chunk['checksum'], (int)$chunk['chunk']),
                    'vector' => array_values(array_map('floatval', $vector)),
                    'payload' => array(
                        'install' => $this->settings->installKey(),
                        'doc_id' => $chunk['doc_id'],
                        'title' => $chunk['title'],
                        'section' => $chunk['section'],
                        'text' => $chunk['text'],
                        'audience' => $chunk['audience'],
                        'module' => $chunk['module'],
                        'url' => $chunk['url'],
                        'chunk' => $chunk['chunk'],
                        'checksum' => $chunk['checksum'],
                    ));
            }
            if ((int)$row['DIMENSION'] === 0) {
                $this->qdrant->createCollection($connection, (string)$row['COLLECTION'], $dimension,
                    (string)$row['VECTOR_NAME'], (string)$row['DISTANCE'],
                    array('m' => (int)$row['HNSW_M'], 'ef_construct' => (int)$row['HNSW_EF_CONSTRUCT']));
                $this->db->requireExec('UPDATE ai_assistant_indexes SET DIMENSION=' . $dimension
                    . ' WHERE MODEL_ID=' . $modelId);
            }
            $this->qdrant->upsert($connection, (string)$row['COLLECTION'], $points, (string)$row['VECTOR_NAME']);
            $processed = (int)$row['PROCESSED'] + count($slice);
            $this->db->requireExec('UPDATE ai_assistant_indexes SET PROCESSED=' . $processed
                . ',UPDATED_AT=' . time() . ' WHERE MODEL_ID=' . $modelId);
            $row = $this->row($modelId);
            if ($processed >= (int)$row['CHUNKS']) return $this->finalize($row, $plan);
            return $this->publicRow($row);
        } catch (Throwable $failure) {
            $message = $failure instanceof ProviderFailure || $failure instanceof RuntimeException
                ? $failure->getMessage()
                : 'Не удалось собрать индекс знаний.';
            $this->markError($modelId, $message);
            throw new RuntimeException($message);
        }
    }

    public function cancel(int $modelId): void
    {
        $this->locked($modelId, function () use ($modelId): array { $this->cancelBuild($modelId); return array(); });
    }

    private function cancelBuild(int $modelId): void
    {
        $row = $this->row($modelId);
        if ($row === null) return;
        if ($row['STATUS'] === 'building') {
            $this->dropPlan($modelId, (string)$row['CHECKSUM']);
            try {
                if ((string)$row['COLLECTION'] !== '') $this->qdrant->deleteCollection($this->settings->connection(), (string)$row['COLLECTION']);
            } catch (Throwable) {
                // Отмена не должна зависеть от доступности Qdrant.
            }
            if ((int)$row['PREVIOUS_ACTIVE'] === 1 && (string)$row['PREVIOUS_COLLECTION'] !== '') {
                $this->db->requireExec("UPDATE ai_assistant_indexes SET STATUS='ready',ACTIVE=1,COLLECTION='"
                    . $this->db->escape((string)$row['PREVIOUS_COLLECTION']) . "',DIMENSION=" . (int)$row['PREVIOUS_DIMENSION']
                    . ",MODEL_SIGNATURE='" . $this->db->escape((string)$row['PREVIOUS_MODEL_SIGNATURE'])
                    . "',VECTOR_NAME='" . $this->db->escape((string)$row['PREVIOUS_VECTOR_NAME'])
                    . "',ERROR='Сборка отменена; работает предыдущий индекс',PREVIOUS_COLLECTION='',PREVIOUS_STATUS='',PREVIOUS_ACTIVE=0,PREVIOUS_DIMENSION=0,PREVIOUS_VECTOR_NAME='',UPDATED_AT="
                    . time() . ' WHERE MODEL_ID=' . $modelId);
            } else {
                $this->db->requireExec("UPDATE ai_assistant_indexes SET STATUS='cancelled',ACTIVE=0,ERROR='Сборка отменена',UPDATED_AT="
                    . time() . ' WHERE MODEL_ID=' . $modelId);
            }
        }
    }

    public function delete(int $modelId): void
    {
        $this->locked($modelId, function () use ($modelId): array { $this->deleteIndex($modelId); return array(); });
    }

    private function deleteIndex(int $modelId): void
    {
        $row = $this->row($modelId);
        if ($row === null) return;
        $this->dropPlan($modelId, (string)$row['CHECKSUM']);
        if ((int)$row['ACTIVE'] === 1) throw new RuntimeException('Нельзя удалить рабочий индекс. Сначала соберите новый.');
        try {
            $this->qdrant->deleteCollection($this->settings->connection(), (string)$row['COLLECTION']);
        } catch (Throwable) {
            // Запись удаляем в любом случае: иначе она навсегда останется в списке.
        }
        $this->db->requireExec('DELETE FROM ai_assistant_index_sources WHERE INDEX_ID=' . (int)$row['ID']);
        $this->db->requireExec('DELETE FROM ai_assistant_indexes WHERE MODEL_ID=' . $modelId);
    }

    /** @return array{mode:string,model:?string,items:list<array>,context:string} */
    public function search(string $query, array $sources, int $topK = 5): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 500) throw new InvalidArgumentException('Поисковый запрос должен содержать от 1 до 500 символов.');
        $rows = $this->db->select("SELECT * FROM ai_assistant_indexes WHERE (STATUS='ready' AND DIMENSION>0)"
            . " OR (PREVIOUS_ACTIVE=1 AND PREVIOUS_COLLECTION<>'' AND PREVIOUS_DIMENSION>0)");
        if ($rows === array()) throw new RuntimeException('Индекс знаний ещё не собран. Запустите индексацию в настройках знаний.');
        $priority = array_flip(array_map('intval', array_column($this->models->embeddingModels(), 'ID')));
        usort($rows, static fn(array $a, array $b): int =>
            ((int)$b['ACTIVE'] <=> (int)$a['ACTIVE'])
            ?: (($priority[(int)$a['MODEL_ID']] ?? 10000) <=> ($priority[(int)$b['MODEL_ID']] ?? 10000)));
        $connection = $this->settings->connection();
        $errors = array();
        $all = array();
        foreach ($rows as $row) {
            try {
                $signature = (string)($row[$row['STATUS'] === 'ready' ? 'MODEL_SIGNATURE' : 'PREVIOUS_MODEL_SIGNATURE'] ?? '');
                if ($signature !== '' && $signature !== $this->embeddingSignature((int)$row['MODEL_ID'])) {
                    throw new ProviderFailure('embedding_changed', 'Модель эмбеддингов изменена. Дождитесь пересборки индекса.');
                }
                $vector = $this->providers->embeddings((int)$row['MODEL_ID'])->embedText($query);
                $collection = $row['STATUS'] === 'ready' ? (string)$row['COLLECTION'] : (string)$row['PREVIOUS_COLLECTION'];
                $vectorName = $row['STATUS'] === 'ready' ? (string)$row['VECTOR_NAME'] : (string)$row['PREVIOUS_VECTOR_NAME'];
                $found = $this->qdrant->search($connection, $collection, $vector,
                    max(1, min(20, $topK)), $this->settings->installKey(), $sources, $vectorName);
                foreach ($found as $item) {
                    $payload = $item['payload'];
                    $all[] = array(
                        'id' => (string)($payload['doc_id'] ?? ''),
                        'title' => SensitiveData::text((string)($payload['title'] ?? '')),
                        'section' => SensitiveData::text((string)($payload['section'] ?? '')),
                        'text' => SensitiveData::text((string)($payload['text'] ?? '')),
                        'url' => (string)($payload['url'] ?? ''),
                        'source' => (string)($payload['module'] ?? ''),
                        'score' => round((float)$item['score'], 4),
                    );
                }
                if ($all !== array()) {
                    usort($all, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
                    $all = array_slice($all, 0, $topK);
                    return array('mode' => 'rag', 'model' => $this->modelTitle((int)$row['MODEL_ID']),
                        'items' => $all, 'context' => $this->context($all, (int)$this->settings->get()['MAX_CONTEXT']));
                }
            } catch (Throwable $failure) {
                $errors[] = $failure instanceof ProviderFailure ? $failure->reason : 'failed';
            }
        }
        if ($errors !== array()) {
            throw new RuntimeException('Не удалось получить векторы или найти фрагменты. Проверьте Qdrant и модель эмбеддингов. Коды: ' . implode(', ', array_unique($errors)));
        }
        return array('mode' => 'rag', 'model' => null, 'items' => array(), 'context' => '');
    }

    private function finalize(array $row, array $plan): array
    {
        $modelId = (int)$row['MODEL_ID'];
        $this->dropPlan($modelId, (string)$row['CHECKSUM']);
        $previous = $this->db->select('SELECT COLLECTION,PREVIOUS_COLLECTION FROM ai_assistant_indexes WHERE MODEL_ID<>' . $modelId
            . " AND (ACTIVE=1 OR PREVIOUS_ACTIVE=1)");
        $this->db->requireExec('UPDATE ai_assistant_indexes SET ACTIVE=0 WHERE MODEL_ID<>' . $modelId);
        $this->db->requireExec("UPDATE ai_assistant_indexes SET STATUS='ready',ACTIVE=1,PROCESSED=CHUNKS,ERROR='',"
            . "PREVIOUS_COLLECTION='',PREVIOUS_STATUS='',PREVIOUS_ACTIVE=0,PREVIOUS_DIMENSION=0,PREVIOUS_VECTOR_NAME='',PREVIOUS_MODEL_SIGNATURE='',UPDATED_AT="
            . time() . ' WHERE MODEL_ID=' . $modelId);
        $this->saveSources((int)$row['ID'], $plan);
        try {
            $collections = array((string)($row['PREVIOUS_COLLECTION'] ?? ''));
            foreach ($previous as $old) {
                $collections[] = (string)($old['COLLECTION'] ?? '');
                $collections[] = (string)($old['PREVIOUS_COLLECTION'] ?? '');
            }
            foreach (array_unique(array_filter($collections)) as $oldCollection) {
                if ($oldCollection !== $row['COLLECTION']) {
                    $this->qdrant->deleteCollection($this->settings->connection(), $oldCollection);
                }
            }
        } catch (Throwable) {
            // Старый индекс мог уже исчезнуть; новый рабочий от этого не страдает.
        }
        return $this->publicRow($this->row($modelId));
    }

    private function markError(int $modelId, string $message): void
    {
        $row = $this->row($modelId);
        if ($row !== null) $this->dropPlan($modelId, (string)$row['CHECKSUM']);
        $safe = $this->db->escape(mb_substr(SensitiveData::text($message), 0, 480));
        if ($row !== null && (int)$row['PREVIOUS_ACTIVE'] === 1 && (string)$row['PREVIOUS_COLLECTION'] !== '') {
            // Неудачная пересборка возвращает прежний рабочий индекс; ошибка
            // остаётся видимой в строке, чтобы её можно было исправить.
            $this->db->requireExec("UPDATE ai_assistant_indexes SET STATUS='ready',ACTIVE=1,COLLECTION='"
                . $this->db->escape((string)$row['PREVIOUS_COLLECTION']) . "',DIMENSION=" . (int)$row['PREVIOUS_DIMENSION']
                . ",MODEL_SIGNATURE='" . $this->db->escape((string)$row['PREVIOUS_MODEL_SIGNATURE'])
                . "',VECTOR_NAME='" . $this->db->escape((string)$row['PREVIOUS_VECTOR_NAME']) . "',ERROR='" . $safe
                . "',PREVIOUS_COLLECTION='',PREVIOUS_STATUS='',PREVIOUS_ACTIVE=0,PREVIOUS_DIMENSION=0,PREVIOUS_VECTOR_NAME='',UPDATED_AT="
                . time() . ' WHERE MODEL_ID=' . $modelId);
            return;
        }
        $this->db->requireExec("UPDATE ai_assistant_indexes SET STATUS='error',ACTIVE=0,ERROR='"
            . $safe . "',UPDATED_AT=" . time() . ' WHERE MODEL_ID=' . $modelId);
    }

    private function saveSources(int $indexId, array $plan): void
    {
        $this->db->requireExec('DELETE FROM ai_assistant_index_sources WHERE INDEX_ID=' . $indexId);
        $sources = array();
        foreach ($plan as $chunk) {
            $id = (string)$chunk['doc_id'];
            if (!isset($sources[$id])) $sources[$id] = array('checksum' => (string)$chunk['checksum'], 'chunks' => 0);
            $sources[$id]['chunks']++;
        }
        foreach ($sources as $id => $source) {
            $this->db->requireExec("INSERT INTO ai_assistant_index_sources (INDEX_ID,DOC_ID,CHECKSUM,CHUNKS) VALUES ("
                . $indexId . ",'" . $this->db->escape($id) . "','" . $this->db->escape($source['checksum']) . "',"
                . (int)$source['chunks'] . ')');
        }
    }

    /** План фрагментов переиспользуется между шагами, чтобы не сканировать Markdown заново. */
    private function planFile(int $modelId, string $hash): string
    {
        $directory = Config::root() . '/var/ai_assistant';
        if (!is_dir($directory)) @mkdir($directory, 0700, true);
        return $directory . '/plan-' . $modelId . '-' . preg_replace('/[^a-f0-9]/', '', $hash) . '.json';
    }

    private function storePlan(int $modelId, string $hash, array $plan): void
    {
        $file = $this->planFile($modelId, $hash);
        $json = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($file, $json, LOCK_EX) === false) return;
        @chmod($file, 0600);
    }

    private function loadPlan(int $modelId, string $hash): ?array
    {
        if ($hash === '') return null;
        $file = $this->planFile($modelId, $hash);
        if (!is_file($file) || !is_readable($file)) return null;
        try {
            $data = json_decode((string)@file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    private function dropPlan(int $modelId, string $hash): void
    {
        if ($hash === '') return;
        @unlink($this->planFile($modelId, $hash));
    }

    private function plan(): array
    {        $settings = $this->settings->get();
        $size = max(300, min(4000, (int)$settings['CHUNK_SIZE']));
        $overlap = max(0, min(800, (int)$settings['CHUNK_OVERLAP']));
        if ($overlap >= $size) $overlap = (int)floor($size / 5);
        $chunks = array();
        foreach ($this->documents() as $document) {
            $checksum = sha1(json_encode(array($document['body'], $document['title'], $document['module'] ?? '',
                $document['is_module'], $size, $overlap), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $audience = (int)$document['is_module'] === 1 ? 'modules' : 'core';
            foreach ($this->split((string)$document['body'], $size, $overlap) as $index => $part) {
                $text = SensitiveData::text($part['text']);
                if (mb_strlen(trim($text)) < 20) continue;
                $chunks[] = array(
                    'doc_id' => (string)$document['id'],
                    'title' => SensitiveData::text((string)$document['title']),
                    'section' => SensitiveData::text($part['section']),
                    'text' => $text,
                    'audience' => $audience,
                    'module' => (string)($document['module'] ?? ''),
                    'url' => $this->prettyUrl((string)$document['id']),
                    'chunk' => $index,
                    'checksum' => $checksum,
                );
            }
        }
        return $chunks;
    }

    /** @return list<array{section:string,text:string}> */
    private function split(string $body, int $size, int $overlap): array
    {
        $body = str_replace(array("\r\n", "\r"), "\n", $body);
        $sections = preg_split('/\n(?=#{1,4}\s)/u', $body) ?: array($body);
        $result = array();
        foreach ($sections as $section) {
            $lines = explode("\n", trim($section));
            $sectionTitle = '';
            if (isset($lines[0]) && preg_match('/^#{1,4}\s+(.*)$/u', $lines[0], $match)) $sectionTitle = trim($match[1]);
            $buffer = '';
            foreach (preg_split('/\n\s*\n/u', trim($section)) ?: array($section) as $paragraph) {
                $paragraph = trim($paragraph);
                if ($paragraph === '') continue;
                if (mb_strlen($buffer) + mb_strlen($paragraph) + 2 <= $size) {
                    $buffer .= ($buffer === '' ? '' : "\n\n") . $paragraph;
                    continue;
                }
                if ($buffer !== '') $result[] = array('section' => $sectionTitle, 'text' => $buffer);
                while (mb_strlen($paragraph) > $size) {
                    $result[] = array('section' => $sectionTitle, 'text' => mb_substr($paragraph, 0, $size));
                    $paragraph = mb_substr($paragraph, $size - $overlap);
                }
                $buffer = $paragraph;
            }
            if ($buffer !== '') $result[] = array('section' => $sectionTitle, 'text' => $buffer);
        }
        // Наложение сохраняет связь между соседними фрагментами без дублей подряд.
        if ($overlap > 0 && count($result) > 1) {
            foreach ($result as $index => &$part) {
                if ($index === 0) continue;
                $tail = mb_substr($result[$index - 1]['text'], -$overlap);
                if ($tail !== '' && !str_starts_with($part['text'], $tail)) $part['text'] = $tail . "\n" . $part['text'];
            }
            unset($part);
        }
        return $result;
    }

    private function documents(): array
    {
        require_once dirname(__DIR__, 3) . '/lib/knowledge_base.php';
        $root = $this->root ?? Config::root();
        $installed = static fn(string $module): bool => (bool)\isModuleInstalled($module);
        $registry = new \MDKnowledgeBase($root . '/docs', defined('ROOTHTML') ? ROOTHTML : '/',
            \MDKnowledgeBase::moduleSources($root . '/modules', $installed));
        return $registry->documents();
    }

    private function prettyUrl(string $id): string
    {
        require_once dirname(__DIR__, 3) . '/lib/knowledge_base.php';
        $registry = new \MDKnowledgeBase(Config::root() . '/docs', defined('ROOTHTML') ? ROOTHTML : '/');
        return $registry->prettyUrl($id);
    }

    private function planHash(array $plan): string
    {
        $settings = $this->settings->get();
        $parts = array((string)$settings['CHUNK_SIZE'], (string)$settings['CHUNK_OVERLAP']);
        foreach ($plan as $chunk) $parts[] = $chunk['doc_id'] . ':' . $chunk['checksum'] . ':' . $chunk['chunk'];
        return sha1(implode('|', $parts));
    }

    private function pointId(string $collection, string $docId, int $chunk): string
    {
        $hex = sha1($this->settings->installKey() . '|' . $collection . '|' . $docId . '|' . $chunk);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    /** Только изменённые/пропавшие файлы, без пересоздания рабочей коллекции. */
    public function synchronize(int $modelId, int $batch = 16): array
    {
        return $this->locked($modelId, function () use ($modelId, $batch): array {
            $row = $this->row($modelId);
            if ($row === null || $row['STATUS'] !== 'ready' || (int)$row['ACTIVE'] !== 1) {
                throw new RuntimeException('Для синхронизации нужен готовый активный индекс. Сначала соберите базу знаний.');
            }
            if ($this->needsRebuild($modelId)) throw new RuntimeException('Модель эмбеддингов или параметры вектора изменились. Нужна полная пересборка индекса.');
            $connection = $this->settings->connection();
            $plan = $this->plan();
            $documents = array();
            foreach ($plan as $chunk) $documents[$chunk['doc_id']][] = $chunk;
            $changed = 0;
            $removed = 0;
            try {
                $manifest = $this->qdrant->sourceManifest($connection, $row['COLLECTION'], $this->settings->installKey());
                $provider = $this->providers->embeddings($modelId);
                foreach ($documents as $id => $chunks) {
                    $hash = $chunks[0]['checksum'];
                    if (($manifest[$id] ?? array()) === array($hash => count($chunks))) continue;
                    foreach (array_chunk($chunks, max(1, min(40, $batch))) as $slice) {
                        $vectors = $provider->embedDocuments(array_map(static fn(array $chunk): Document => new Document(content: $chunk['text']), $slice));
                        $points = array();
                        foreach ($slice as $i => $chunk) {
                            $vector = $vectors[$i]->getEmbedding();
                            if (count($vector) !== (int)$row['DIMENSION']) throw new RuntimeException('Размерность эмбеддингов изменилась. Нужна полная пересборка.');
                            $points[] = array('id' => $this->pointId($row['COLLECTION'], $id . ':' . $hash, (int)$chunk['chunk']),
                                'vector' => array_values(array_map('floatval', $vector)),
                                'payload' => array('install' => $this->settings->installKey()) + $chunk);
                        }
                        $this->qdrant->upsert($connection, $row['COLLECTION'], $points, $row['VECTOR_NAME']);
                    }
                    $this->qdrant->deleteSource($connection, $row['COLLECTION'], $this->settings->installKey(), $id, $hash);
                    $changed++;
                }
                foreach (array_diff(array_keys($manifest), array_keys($documents)) as $id) {
                    $this->qdrant->deleteSource($connection, $row['COLLECTION'], $this->settings->installKey(), $id);
                    $removed++;
                }
                $this->saveSources((int)$row['ID'], $plan);
                $this->db->requireExec('UPDATE ai_assistant_indexes SET DOCUMENTS=' . count($documents) . ',CHUNKS=' . count($plan)
                    . ',PROCESSED=' . count($plan) . ",CHECKSUM='" . $this->planHash($plan) . "',ERROR='',UPDATED_AT=" . time() . ' WHERE MODEL_ID=' . $modelId);
                return array('changed' => $changed, 'removed' => $removed, 'documents' => count($documents));
            } catch (Throwable $failure) {
                $this->db->requireExec("UPDATE ai_assistant_indexes SET ERROR='" . $this->db->escape(mb_substr(SensitiveData::text($failure->getMessage()), 0, 480))
                    . "',UPDATED_AT=" . time() . ' WHERE MODEL_ID=' . $modelId);
                throw $failure;
            }
        });
    }

    private function locked(int $modelId, callable $operation): array
    {
        $directory = ($this->root ?? Config::root()) . '/var/ai_assistant';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Не удалось создать каталог индекса.');
        $handle = fopen($directory . '/index-' . $modelId . '.lock', 'c');
        if ($handle === false) throw new RuntimeException('Не удалось открыть блокировку индекса.');
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) throw new RuntimeException('Индекс уже обслуживается другим запросом.');
            return $operation();
        } finally { fclose($handle); }
    }

    public function needsRebuild(int $modelId): bool
    {
        $row = $this->row($modelId);
        if ($row === null) return true;
        $signature = (string)($row['MODEL_SIGNATURE'] ?? '');
        if ($signature !== '' && $signature !== $this->embeddingSignature($modelId)) return true;
        $settings = $this->settings->get();
        foreach (array('VECTOR_NAME', 'VECTOR_SIZE', 'DISTANCE', 'HNSW_M', 'HNSW_EF_CONSTRUCT') as $key) {
            if ((string)$row[$key] !== (string)$settings[$key]) return true;
        }
        return false;
    }

    private function embeddingSignature(int $modelId): string
    {
        $connection = $this->models->connection($modelId, 'embeddings');
        return sha1(json_encode(array($connection['model']['MODEL'], $connection['provider']['BASE_URL']), JSON_THROW_ON_ERROR));
    }

    private function context(array $items, int $maximum): string
    {
        $parts = array();
        $used = 0;
        foreach ($items as $item) {
            $text = '### ' . $item['title'] . ($item['section'] !== '' ? ' — ' . $item['section'] : '')
                . "\n" . $item['text'] . "\nИсточник: " . $item['url'];
            if ($used + mb_strlen($text) > $maximum) break;
            $parts[] = $text;
            $used += mb_strlen($text);
        }
        return implode("\n\n", $parts);
    }

    private function modelTitle(int $modelId): string
    {
        try {
            return (string)$this->models->model($modelId)['TITLE'];
        } catch (Throwable) {
            return 'Модель #' . $modelId;
        }
    }

    private function row(int $modelId): ?array
    {
        return $this->db->one('SELECT * FROM ai_assistant_indexes WHERE MODEL_ID=' . $modelId);
    }

    private function publicRow(?array $row): array
    {
        if ($row === null) return array('status' => 'missing');
        return array(
            'status' => (string)$row['STATUS'],
            'model_id' => (int)$row['MODEL_ID'],
            'documents' => (int)$row['DOCUMENTS'],
            'chunks' => (int)$row['CHUNKS'],
            'processed' => (int)$row['PROCESSED'],
            'dimension' => (int)$row['DIMENSION'],
            'active' => (string)$row['STATUS'] === 'ready' && (int)$row['ACTIVE'] === 1,
            'error' => (string)$row['ERROR'],
            'percent' => (int)$row['CHUNKS'] > 0 ? min(100, (int)round((int)$row['PROCESSED'] / (int)$row['CHUNKS'] * 100)) : 0,
        );
    }
}
