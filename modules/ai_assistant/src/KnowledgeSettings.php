<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use InvalidArgumentException;
use MajorDoMo\Core\Database;
use RuntimeException;

/**
 * Опциональное подключение Qdrant с шифрованием ключа и ревизией формы.
 *
 * Изменения:
 * 2026-10-23 — параметры коллекции Qdrant и единое сохранение подключения и индекса.
 * 2026-10-23 — модель эмбеддингов, разбиение и предел контекста индекса.
 * 2026-10-23 — настройки подключения без автоматического обращения к сети.
 */
final class KnowledgeSettings
{
    public function __construct(private Database $db, private SecretVault $vault) {}

    public function get(): array
    {
        return $this->db->one("SELECT ID,QDRANT_URL,TIMEOUT,REVISION,EMBEDDING_MODEL_ID,CHUNK_SIZE,CHUNK_OVERLAP,TOP_K,MAX_CONTEXT,INSTALL_KEY,"
            . "VECTOR_NAME,VECTOR_SIZE,DISTANCE,HNSW_M,HNSW_EF_CONSTRUCT,"
            . "CASE WHEN TOKEN_CIPHER<>'' THEN 1 ELSE 0 END AS HAS_TOKEN FROM ai_assistant_knowledge WHERE ID=1")
            ?? throw new RuntimeException('Настройки знаний отсутствуют. Обновите модуль ИИ-помощника.');
    }

    /** Пустой адрес отключает Qdrant; отсутствие TOKEN сохраняет прежний ключ. */
    public function save(array $input): void
    {
        $current = $this->get();
        $this->saveAll($input + array(
            'EMBEDDING_MODEL_ID' => $current['EMBEDDING_MODEL_ID'],
            'CHUNK_SIZE' => $current['CHUNK_SIZE'], 'CHUNK_OVERLAP' => $current['CHUNK_OVERLAP'],
            'TOP_K' => $current['TOP_K'], 'MAX_CONTEXT' => $current['MAX_CONTEXT'],
            'VECTOR_NAME' => $current['VECTOR_NAME'], 'VECTOR_SIZE' => $current['VECTOR_SIZE'],
            'DISTANCE' => $current['DISTANCE'], 'HNSW_M' => $current['HNSW_M'],
            'HNSW_EF_CONSTRUCT' => $current['HNSW_EF_CONSTRUCT'],
        ));
    }

    /** Только серверный транспорт; не передавать результат в ответ HTTP или модель. */
    public function connection(): array
    {
        $settings = $this->get();
        if ($settings['QDRANT_URL'] === '') return array('url' => '', 'token' => '', 'timeout' => (int)$settings['TIMEOUT']);
        $raw = $this->db->one('SELECT TOKEN_CIPHER FROM ai_assistant_knowledge WHERE ID=1');
        if ($raw === null) throw new RuntimeException('Настройки знаний были удалены. Обновите страницу.');
        return array('url' => Endpoint::normalize($settings['QDRANT_URL']), 'token' => $this->vault->decrypt($raw['TOKEN_CIPHER']), 'timeout' => (int)$settings['TIMEOUT']);
    }

    /** Параметры индексации и выбранная модель эмбеддингов. */
    public function saveIndex(array $input, ?ModelCatalog $models = null): void
    {
        $current = $this->get();
        $this->saveAll($input + array(
            'QDRANT_URL' => $current['QDRANT_URL'], 'TIMEOUT' => $current['TIMEOUT'],
            'VECTOR_NAME' => $current['VECTOR_NAME'], 'VECTOR_SIZE' => $current['VECTOR_SIZE'],
            'DISTANCE' => $current['DISTANCE'], 'HNSW_M' => $current['HNSW_M'],
            'HNSW_EF_CONSTRUCT' => $current['HNSW_EF_CONSTRUCT'],
        ), $models);
    }

    /**
     * Одна кнопка сохраняет подключение Qdrant, модель эмбеддингов и параметры
     * коллекции. Пустой TOKEN сохраняет прежний ключ; смена адреса требует ключ.
     */
    public function saveAll(array $input, ?ModelCatalog $models = null): void
    {
        $current = $this->get();
        $url = $input['QDRANT_URL'] ?? null;
        if (!is_string($url)) throw new InvalidArgumentException('Укажите адрес Qdrant или оставьте его пустым.');
        $url = trim($url) === '' ? '' : Endpoint::normalize($url);
        $timeout = ModelCatalog::integer($input, 'TIMEOUT', 1, 30);
        $revision = array_key_exists('REVISION', $input)
            ? ModelCatalog::integer($input, 'REVISION', 1, PHP_INT_MAX)
            : (int)$current['REVISION'];
        if ($revision !== (int)$current['REVISION']) throw new RuntimeException('Настройки знаний уже изменены. Обновите страницу.');
        $secret = '';
        if (array_key_exists('TOKEN', $input)) {
            if (!is_string($input['TOKEN']) || strlen($input['TOKEN']) > 8192 || preg_match('/[\x00-\x20\x7f]/', $input['TOKEN'])) {
                throw new InvalidArgumentException('Ключ Qdrant должен быть строкой без пробелов и управляющих символов.');
            }
            $secret = $input['TOKEN'] === ''
                ? ",TOKEN_CIPHER=''"
                : ",TOKEN_CIPHER='" . $this->db->escape($this->vault->encrypt($input['TOKEN'])) . "'";
        } elseif ($url !== $current['QDRANT_URL'] && (bool)$current['HAS_TOKEN']) {
            // Смена сервера не должна отправить прежний ключ чужому адресу.
            throw new InvalidArgumentException('При смене адреса Qdrant задайте ключ заново или явно очистите его.');
        }

        $modelId = ModelCatalog::integer($input, 'EMBEDDING_MODEL_ID', 0, PHP_INT_MAX);
        if ($models !== null && $modelId > 0) {
            $model = $models->model($modelId);
            if (!in_array('embeddings', $model['CAPABILITIES'], true)) {
                throw new InvalidArgumentException('Выбранная модель не поддерживает эмбеддинги.');
            }
        }
        $size = ModelCatalog::integer($input, 'CHUNK_SIZE', 300, 4000);
        $overlap = ModelCatalog::integer($input, 'CHUNK_OVERLAP', 0, 800);
        if ($overlap >= $size) throw new InvalidArgumentException('Перекрытие фрагментов должно быть меньше их размера.');
        $top = ModelCatalog::integer($input, 'TOP_K', 1, 20);
        $context = ModelCatalog::integer($input, 'MAX_CONTEXT', 1000, 60000);
        $vectorName = $input['VECTOR_NAME'] ?? '';
        if (!is_string($vectorName) || !preg_match('/^[A-Za-z0-9_-]{0,64}$/D', $vectorName)) {
            throw new InvalidArgumentException('Имя вектора: до 64 символов, латиница, цифры, дефис или подчёркивание. Пусто — безымянный вектор Qdrant.');
        }
        $vectorSize = ModelCatalog::integer($input, 'VECTOR_SIZE', 0, 65536);
        $distance = $input['DISTANCE'] ?? 'Cosine';
        if (!is_string($distance) || !in_array($distance, array('Cosine', 'Dot', 'Euclid', 'Manhattan'), true)) {
            throw new InvalidArgumentException('Метрика должна быть Cosine, Dot, Euclid или Manhattan.');
        }
        $hnswM = ModelCatalog::integer($input, 'HNSW_M', 4, 128);
        $efConstruct = ModelCatalog::integer($input, 'HNSW_EF_CONSTRUCT', 4, 1024);
        $this->db->requireExec("UPDATE ai_assistant_knowledge SET QDRANT_URL='" . $this->db->escape($url)
            . "',TIMEOUT=$timeout,EMBEDDING_MODEL_ID=$modelId,CHUNK_SIZE=$size,CHUNK_OVERLAP=$overlap,TOP_K=$top,"
            . "MAX_CONTEXT=$context,VECTOR_NAME='" . $this->db->escape($vectorName) . "',VECTOR_SIZE=$vectorSize,"
            . "DISTANCE='" . $this->db->escape($distance) . "',HNSW_M=$hnswM,HNSW_EF_CONSTRUCT=$efConstruct,"
            . 'REVISION=REVISION+1' . $secret . ' WHERE ID=1 AND REVISION=' . $revision);
        if ($this->db->affectedRows() !== 1) throw new RuntimeException('Настройки знаний уже изменены. Обновите страницу.');
    }

    public function installKey(): string
    {
        $key = (string)($this->get()['INSTALL_KEY'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            throw new RuntimeException('Ключ установки для индекса знаний повреждён. Переустановите модуль или восстановите настройки.');
        }
        return $key;
    }
}
