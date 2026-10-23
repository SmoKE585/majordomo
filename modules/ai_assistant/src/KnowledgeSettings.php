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
 * 2026-10-23 — модель эмбеддингов, разбиение и предел контекста индекса.
 * 2026-10-23 — настройки подключения без автоматического обращения к сети.
 */
final class KnowledgeSettings
{
    public function __construct(private Database $db, private SecretVault $vault) {}

    public function get(): array
    {
        return $this->db->one("SELECT ID,QDRANT_URL,TIMEOUT,REVISION,EMBEDDING_MODEL_ID,CHUNK_SIZE,CHUNK_OVERLAP,TOP_K,MAX_CONTEXT,INSTALL_KEY,"
            . "CASE WHEN TOKEN_CIPHER<>'' THEN 1 ELSE 0 END AS HAS_TOKEN FROM ai_assistant_knowledge WHERE ID=1")
            ?? throw new RuntimeException('Настройки знаний отсутствуют. Обновите модуль ИИ-помощника.');
    }

    /** Пустой адрес отключает Qdrant; отсутствие TOKEN сохраняет прежний ключ. */
    public function save(array $input): void
    {
        $current = $this->get();
        $url = $input['QDRANT_URL'] ?? null;
        if (!is_string($url)) throw new InvalidArgumentException('Укажите адрес Qdrant или оставьте его пустым.');
        $url = trim($url) === '' ? '' : Endpoint::normalize($url);
        $timeout = ModelCatalog::integer($input, 'TIMEOUT', 1, 30);
        $revision = ModelCatalog::integer($input, 'REVISION', 1, PHP_INT_MAX);
        if ($revision !== (int)$current['REVISION']) throw new RuntimeException('Настройки знаний уже изменены. Обновите страницу.');
        $secret = '';
        if (array_key_exists('TOKEN', $input)) {
            if (!is_string($input['TOKEN']) || strlen($input['TOKEN']) > 8192 || preg_match('/[\x00-\x20\x7f]/', $input['TOKEN'])) {
                throw new InvalidArgumentException('Ключ Qdrant должен быть строкой без пробелов и управляющих символов.');
            }
            $secret = ",TOKEN_CIPHER='" . $this->db->escape($this->vault->encrypt($input['TOKEN'])) . "'";
        } elseif ($url !== $current['QDRANT_URL'] && (bool)$current['HAS_TOKEN']) {
            // Смена сервера не должна отправить прежний ключ чужому адресу.
            throw new InvalidArgumentException('При смене адреса Qdrant задайте ключ заново или явно очистите его.');
        }
        $this->db->requireExec("UPDATE ai_assistant_knowledge SET QDRANT_URL='" . $this->db->escape($url)
            . "',TIMEOUT=$timeout,REVISION=REVISION+1" . $secret . " WHERE ID=1 AND REVISION=$revision");
        if ($this->db->affectedRows() !== 1) throw new RuntimeException('Настройки знаний уже изменены. Обновите страницу.');
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
        $revision = ModelCatalog::integer($input, 'REVISION', 1, PHP_INT_MAX);
        if ($revision !== (int)$current['REVISION']) throw new RuntimeException('Настройки знаний уже изменены. Обновите страницу.');
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
        $this->db->requireExec('UPDATE ai_assistant_knowledge SET EMBEDDING_MODEL_ID=' . $modelId
            . ',CHUNK_SIZE=' . $size . ',CHUNK_OVERLAP=' . $overlap . ',TOP_K=' . $top
            . ',MAX_CONTEXT=' . $context . ',REVISION=REVISION+1 WHERE ID=1 AND REVISION=' . $revision);
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
