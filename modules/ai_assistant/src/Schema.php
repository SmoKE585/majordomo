<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use InvalidArgumentException;
use MajorDoMo\Core\Database;
use RuntimeException;

/**
 * Версия собственных таблиц ИИ-помощника и их безопасное обновление.
 *
 * Изменения:
 * 2026-10-23 — предложения действий, последний запуск и подпись эмбеддингов.
 * 2026-10-23 — параметры коллекции Qdrant: имя вектора, размерность, метрика и HNSW.
 * 2026-10-23 — диалоги, сообщения, запуски и обслуживаемый индекс знаний.
 * 2026-10-23 — опциональный Qdrant, режим знаний агента и порядок эмбеддингов.
 * 2026-10-23 — агенты, ревизии и начальные профили без перезаписи пользовательских данных.
 * 2026-10-23 — провайдеры, модели и ссылки, защищающие используемые модели от удаления.
 * 2026-10-23 — первая схема, повторная установка и явная очистка данных.
 */
final class Schema
{
    public const VERSION = 7;
    public const DELETE_CONFIRMATION = 'DELETE ai_assistant';

    public static function install(Database $db, bool $sqlite = false): void
    {
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->requireExec("CREATE TABLE IF NOT EXISTS ai_assistant_settings (
            ID int NOT NULL PRIMARY KEY,
            SCHEMA_VERSION int NOT NULL DEFAULT 0,
            INSTALLED int NOT NULL DEFAULT 1,
            ENABLED int NOT NULL DEFAULT 0,
            UPDATED_AT bigint NOT NULL DEFAULT 0
        )" . $tail);

        // Два одновременных открытия новой страницы не должны создать две
        // настройки или сбросить уже выбранное состояние модуля.
        $conflict = $sqlite ? ' ON CONFLICT(ID) DO NOTHING' : ' ON DUPLICATE KEY UPDATE ID=ID';
        $db->requireExec('INSERT INTO ai_assistant_settings (ID,SCHEMA_VERSION,INSTALLED,ENABLED,UPDATED_AT)'
            . ' VALUES (1,0,1,1,' . time() . ')' . $conflict);

        $record = $db->one('SELECT SCHEMA_VERSION FROM ai_assistant_settings WHERE ID=1');
        if ($record === null) {
            throw new RuntimeException('Не удалось создать настройки ИИ-помощника. Повторите установку модуля.');
        }
        $version = (int)$record['SCHEMA_VERSION'];
        if ($version > self::VERSION || $version < 0) {
            throw new RuntimeException('Схема ИИ-помощника несовместима с этой версией. Установите подходящую версию модуля.');
        }
        if ($version === 0) {
            $db->requireExec('UPDATE ai_assistant_settings SET SCHEMA_VERSION=1 WHERE ID=1 AND SCHEMA_VERSION=0');
        }
        if ($version < 2) {
            $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY';
            $reference = $sqlite ? 'INTEGER' : 'bigint unsigned';
            foreach (array(
                "CREATE TABLE IF NOT EXISTS ai_assistant_providers (
                    ID $id, TITLE varchar(190) NOT NULL, BASE_URL varchar(2048) NOT NULL,
                    PROTOCOL varchar(32) NOT NULL, TIMEOUT int NOT NULL DEFAULT 60,
                    ENABLED int NOT NULL DEFAULT 1
                )$tail",
                "CREATE TABLE IF NOT EXISTS ai_assistant_models (
                    ID $id, PROVIDER_ID $reference NOT NULL, TITLE varchar(190) NOT NULL,
                    MODEL varchar(190) NOT NULL, TOKEN_CIPHER text NOT NULL,
                    CAPABILITIES text NOT NULL, PARAMETERS text NOT NULL,
                    CONTEXT_WINDOW int NOT NULL DEFAULT 32768, MAX_OUTPUT int NOT NULL DEFAULT 4096,
                    ENABLED int NOT NULL DEFAULT 1,
                    FOREIGN KEY (PROVIDER_ID) REFERENCES ai_assistant_providers(ID)
                )$tail",
                "CREATE TABLE IF NOT EXISTS ai_assistant_model_links (
                    MODEL_ID $reference NOT NULL, OWNER_TYPE varchar(32) NOT NULL, OWNER_ID bigint NOT NULL,
                    PRIMARY KEY (OWNER_TYPE,OWNER_ID),
                    FOREIGN KEY (MODEL_ID) REFERENCES ai_assistant_models(ID)
                )$tail",
            ) as $sql) {
                $db->requireExec($sql);
            }
            $db->requireExec('UPDATE ai_assistant_settings SET SCHEMA_VERSION=2 WHERE ID=1 AND SCHEMA_VERSION<2');
        }
        if ($version < 3) {
            $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY';
            $reference = $sqlite ? 'INTEGER' : 'bigint unsigned';
            $text = $sqlite ? 'TEXT' : 'mediumtext';
            $db->requireExec("CREATE TABLE IF NOT EXISTS ai_assistant_agents (
                ID $id, SLUG varchar(100) NOT NULL UNIQUE, TITLE varchar(190) NOT NULL,
                PROFILE varchar(32) NOT NULL, MODEL_ID $reference NULL,
                INSTRUCTIONS $text NOT NULL, BASE_VERSION int NOT NULL DEFAULT 0,
                TOOLS text NOT NULL, SOURCES text NOT NULL,
                MAX_STEPS int NOT NULL DEFAULT 12, TIMEOUT int NOT NULL DEFAULT 120,
                ENABLED int NOT NULL DEFAULT 1, DELETED int NOT NULL DEFAULT 0,
                REVISION int NOT NULL DEFAULT 1,
                FOREIGN KEY (MODEL_ID) REFERENCES ai_assistant_models(ID)
            )$tail");
            StandardProfiles::seed($db, $sqlite);
            $db->requireExec('UPDATE ai_assistant_settings SET SCHEMA_VERSION=3 WHERE ID=1 AND SCHEMA_VERSION<3');
        }
        if ($version < 4) {
            $db->requireExec("CREATE TABLE IF NOT EXISTS ai_assistant_knowledge (
                ID int NOT NULL PRIMARY KEY, QDRANT_URL varchar(2048) NOT NULL DEFAULT '',
                TOKEN_CIPHER text NOT NULL, TIMEOUT int NOT NULL DEFAULT 5,
                REVISION int NOT NULL DEFAULT 1
            )$tail");
            $db->requireExec("INSERT INTO ai_assistant_knowledge (ID,TOKEN_CIPHER) VALUES (1,'')" . $conflict);
            self::addColumn($db, $sqlite, 'ai_assistant_models', 'EMBEDDING_PRIORITY', 'int NOT NULL DEFAULT 100');
            self::addColumn($db, $sqlite, 'ai_assistant_agents', 'KNOWLEDGE_MODE', "varchar(16) NOT NULL DEFAULT 'text'");
            $db->requireExec('UPDATE ai_assistant_settings SET SCHEMA_VERSION=4 WHERE ID=1 AND SCHEMA_VERSION<4');
        }
        if ($version < 5) {
            self::installConversations($db, $sqlite, $tail);
            self::installIndex($db, $sqlite, $tail);
            foreach (array(
                'EMBEDDING_MODEL_ID' => 'int NOT NULL DEFAULT 0',
                'CHUNK_SIZE' => 'int NOT NULL DEFAULT 1200',
                'CHUNK_OVERLAP' => 'int NOT NULL DEFAULT 150',
                'TOP_K' => 'int NOT NULL DEFAULT 5',
                'MAX_CONTEXT' => 'int NOT NULL DEFAULT 12000',
                'INSTALL_KEY' => "varchar(64) NOT NULL DEFAULT ''",
            ) as $field => $definition) {
                self::addColumn($db, $sqlite, 'ai_assistant_knowledge', $field, $definition);
            }
            // Общая установка Qdrant не должна видеть чужие фрагменты. Ключ
            // создаётся один раз и попадает в фильтр каждого запроса/точки.
            $db->requireExec("UPDATE ai_assistant_knowledge SET INSTALL_KEY='" . bin2hex(random_bytes(16))
                . "' WHERE ID=1 AND INSTALL_KEY=''");
            $db->requireExec('UPDATE ai_assistant_settings SET SCHEMA_VERSION=5 WHERE ID=1 AND SCHEMA_VERSION<5');
        }
        if ($version < 6) {
            // Параметры коллекции Qdrant хранятся рядом с настройками знаний:
            // имя вектора (пусто = безымянный), размерность и метрика, HNSW.
            foreach (array(
                'VECTOR_NAME' => "varchar(64) NOT NULL DEFAULT ''",
                'VECTOR_SIZE' => 'int NOT NULL DEFAULT 3072',
                'DISTANCE' => "varchar(16) NOT NULL DEFAULT 'Cosine'",
                'HNSW_M' => 'int NOT NULL DEFAULT 16',
                'HNSW_EF_CONSTRUCT' => 'int NOT NULL DEFAULT 100',
            ) as $field => $definition) {
                self::addColumn($db, $sqlite, 'ai_assistant_knowledge', $field, $definition);
            }
            $db->requireExec('UPDATE ai_assistant_settings SET SCHEMA_VERSION=6 WHERE ID=1 AND SCHEMA_VERSION<6');
        }
        // Ручного выключателя больше нет; старые установки включаются при обновлении.
        if ($version < 7) {
            self::addColumn($db, $sqlite, 'ai_assistant_dialogs', 'LAST_RUN', "varchar(32) NOT NULL DEFAULT ''");
            $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY';
            $db->requireExec("CREATE TABLE IF NOT EXISTS ai_assistant_actions (
                ID $id, DIALOG_ID bigint NOT NULL, USER_ID bigint NOT NULL, AGENT_ID bigint NOT NULL,
                PAYLOAD " . ($sqlite ? 'text' : 'mediumtext') . " NOT NULL, STATUS varchar(16) NOT NULL DEFAULT 'pending',
                CREATED_AT bigint NOT NULL, RESULT text NOT NULL
            )" . $tail);
            // Полный прежний набор штатного профиля дополняется предложением
            // создания; ограниченный пользователем набор остаётся без изменений.
            $previousTools = array_values(array_diff(AgentCatalog::TOOLS, array('create_entity')));
            sort($previousTools);
            foreach ($db->select("SELECT ID,TOOLS,REVISION FROM ai_assistant_agents WHERE PROFILE IN ('assistant','editor') AND DELETED=0") as $agent) {
                $tools = json_decode($agent['TOOLS'], true, 16, JSON_THROW_ON_ERROR);
                sort($tools);
                if ($tools !== $previousTools) continue;
                $db->update('ai_assistant_agents', array('ID' => (int)$agent['ID'],
                    'TOOLS' => json_encode(AgentCatalog::TOOLS, JSON_THROW_ON_ERROR), 'REVISION' => (int)$agent['REVISION'] + 1));
            }
            $db->requireExec('UPDATE ai_assistant_settings SET SCHEMA_VERSION=7 WHERE ID=1 AND SCHEMA_VERSION<7');
        }
        $db->requireExec('UPDATE ai_assistant_settings SET ENABLED=1 WHERE ID=1 AND INSTALLED=1 AND ENABLED<>1');
        // Колонки staging-индекса появились после первой версии схемы 5.
        // Проверяем их при каждом открытии, чтобы обновление не зависело от даты установки.
        foreach (array(
            'MODEL_SIGNATURE' => "varchar(40) NOT NULL DEFAULT ''",
            'PREVIOUS_MODEL_SIGNATURE' => "varchar(40) NOT NULL DEFAULT ''",
            'PREVIOUS_COLLECTION' => "varchar(190) NOT NULL DEFAULT ''",
            'PREVIOUS_STATUS' => "varchar(16) NOT NULL DEFAULT ''",
            'PREVIOUS_ACTIVE' => 'int NOT NULL DEFAULT 0',
            'PREVIOUS_DIMENSION' => 'int NOT NULL DEFAULT 0',
            'VECTOR_NAME' => "varchar(64) NOT NULL DEFAULT ''",
            'VECTOR_SIZE' => 'int NOT NULL DEFAULT 0',
            'DISTANCE' => "varchar(16) NOT NULL DEFAULT 'Cosine'",
            'HNSW_M' => 'int NOT NULL DEFAULT 16',
            'HNSW_EF_CONSTRUCT' => 'int NOT NULL DEFAULT 100',
            'PREVIOUS_VECTOR_NAME' => "varchar(64) NOT NULL DEFAULT ''",
        ) as $field => $definition) {
            self::addColumn($db, $sqlite, 'ai_assistant_indexes', $field, $definition);
        }
    }

    private static function installConversations(Database $db, bool $sqlite, string $tail): void
    {
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $reference = $sqlite ? 'INTEGER' : 'bigint unsigned';
        $text = $sqlite ? 'TEXT' : 'mediumtext';
        $indexes = $sqlite ? '' : ', KEY ai_assistant_dialogs_owner (USER_ID,DELETED,UPDATED_AT)';
        $db->requireExec("CREATE TABLE IF NOT EXISTS ai_assistant_dialogs (
            ID $id, USER_ID $reference NOT NULL, AGENT_ID $reference NULL,
            TITLE varchar(190) NOT NULL, CONTEXT_TYPE varchar(32) NOT NULL DEFAULT 'chat',
            CONTEXT $text NOT NULL, CREATED_AT bigint NOT NULL DEFAULT 0,
            UPDATED_AT bigint NOT NULL DEFAULT 0, DELETED int NOT NULL DEFAULT 0$indexes
        )$tail");
        if ($sqlite) $db->requireExec('CREATE INDEX IF NOT EXISTS ai_assistant_dialogs_owner ON ai_assistant_dialogs (USER_ID,DELETED,UPDATED_AT)');
        $indexes = $sqlite ? '' : ', KEY ai_assistant_messages_dialog (DIALOG_ID,ID)';
        $db->requireExec("CREATE TABLE IF NOT EXISTS ai_assistant_messages (
            ID $id, DIALOG_ID $reference NOT NULL, ROLE varchar(16) NOT NULL,
            CONTENT $text NOT NULL, META $text NOT NULL, CREATED_AT bigint NOT NULL DEFAULT 0$indexes
        )$tail");
        if ($sqlite) $db->requireExec('CREATE INDEX IF NOT EXISTS ai_assistant_messages_dialog ON ai_assistant_messages (DIALOG_ID,ID)');
        $indexes = $sqlite ? '' : ', KEY ai_assistant_runs_owner (USER_ID,STATUS)';
        $db->requireExec("CREATE TABLE IF NOT EXISTS ai_assistant_runs (
            ID varchar(40) NOT NULL PRIMARY KEY, DIALOG_ID $reference NOT NULL,
            USER_ID $reference NOT NULL, AGENT_ID $reference NULL,
            STATUS varchar(16) NOT NULL DEFAULT 'running', CANCEL_REQUESTED int NOT NULL DEFAULT 0,
            PROMPT $text NOT NULL, ERROR varchar(500) NOT NULL DEFAULT '',
            CREATED_AT bigint NOT NULL DEFAULT 0, UPDATED_AT bigint NOT NULL DEFAULT 0$indexes
        )$tail");
        if ($sqlite) $db->requireExec('CREATE INDEX IF NOT EXISTS ai_assistant_runs_owner ON ai_assistant_runs (USER_ID,STATUS)');
    }

    private static function installIndex(Database $db, bool $sqlite, string $tail): void
    {
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $reference = $sqlite ? 'INTEGER' : 'bigint unsigned';
        $db->requireExec("CREATE TABLE IF NOT EXISTS ai_assistant_indexes (
            ID $id, MODEL_ID $reference NOT NULL UNIQUE, COLLECTION varchar(190) NOT NULL,
            DIMENSION int NOT NULL DEFAULT 0, STATUS varchar(16) NOT NULL DEFAULT 'empty',
            ACTIVE int NOT NULL DEFAULT 0, DOCUMENTS int NOT NULL DEFAULT 0,
            CHUNKS int NOT NULL DEFAULT 0, PROCESSED int NOT NULL DEFAULT 0,
            CHECKSUM varchar(64) NOT NULL DEFAULT '', ERROR varchar(500) NOT NULL DEFAULT '',
            VECTOR_NAME varchar(64) NOT NULL DEFAULT '', VECTOR_SIZE int NOT NULL DEFAULT 0,
            DISTANCE varchar(16) NOT NULL DEFAULT 'Cosine', HNSW_M int NOT NULL DEFAULT 16,
            HNSW_EF_CONSTRUCT int NOT NULL DEFAULT 100,
            PREVIOUS_COLLECTION varchar(190) NOT NULL DEFAULT '', PREVIOUS_STATUS varchar(16) NOT NULL DEFAULT '',
            PREVIOUS_ACTIVE int NOT NULL DEFAULT 0, PREVIOUS_DIMENSION int NOT NULL DEFAULT 0,
            PREVIOUS_VECTOR_NAME varchar(64) NOT NULL DEFAULT '',
            CREATED_AT bigint NOT NULL DEFAULT 0, UPDATED_AT bigint NOT NULL DEFAULT 0
        )$tail");
        foreach (array(
            'PREVIOUS_COLLECTION' => "varchar(190) NOT NULL DEFAULT ''",
            'PREVIOUS_STATUS' => "varchar(16) NOT NULL DEFAULT ''",
            'PREVIOUS_ACTIVE' => 'int NOT NULL DEFAULT 0',
            'PREVIOUS_DIMENSION' => 'int NOT NULL DEFAULT 0',
            'VECTOR_NAME' => "varchar(64) NOT NULL DEFAULT ''",
            'VECTOR_SIZE' => 'int NOT NULL DEFAULT 0',
            'DISTANCE' => "varchar(16) NOT NULL DEFAULT 'Cosine'",
            'HNSW_M' => 'int NOT NULL DEFAULT 16',
            'HNSW_EF_CONSTRUCT' => 'int NOT NULL DEFAULT 100',
            'PREVIOUS_VECTOR_NAME' => "varchar(64) NOT NULL DEFAULT ''",
        ) as $field => $definition) {
            self::addColumn($db, $sqlite, 'ai_assistant_indexes', $field, $definition);
        }
        $db->requireExec("CREATE TABLE IF NOT EXISTS ai_assistant_index_sources (
            INDEX_ID $reference NOT NULL, DOC_ID varchar(512) NOT NULL,
            CHECKSUM varchar(64) NOT NULL, CHUNKS int NOT NULL DEFAULT 0,
            PRIMARY KEY (INDEX_ID,DOC_ID)
        )$tail");
    }

    /** Повтор после прерванного ALTER не должен блокировать установку. */
    private static function addColumn(Database $db, bool $sqlite, string $table, string $field, string $definition): void
    {
        $columns = $sqlite ? $db->select('PRAGMA table_info(' . $table . ')')
            : $db->select("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $table . "'");
        foreach ($columns as $column) {
            if (strcasecmp((string)($column[$sqlite ? 'name' : 'COLUMN_NAME'] ?? ''), $field) === 0) return;
        }
        $db->requireExec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $field . ' ' . $definition);
    }

    public static function confirmDeletion(mixed $confirmation): void
    {
        if ($confirmation !== self::DELETE_CONFIRMATION) {
            throw new InvalidArgumentException('Для очистки данных ИИ-помощника нужно отдельное подтверждение: ' . self::DELETE_CONFIRMATION);
        }
    }

    /** Удаляет только собственные таблицы уже отключённого модуля. */
    public static function remove(Database $db, string $confirmation): void
    {
        self::confirmDeletion($confirmation);
        $record = $db->one('SELECT INSTALLED FROM ai_assistant_settings WHERE ID=1');
        if ($record !== null && (int)$record['INSTALLED'] !== 0) {
            throw new RuntimeException('Перед очисткой данных удалите ИИ-помощника штатным способом.');
        }
        foreach (array('ai_assistant_actions', 'ai_assistant_runs', 'ai_assistant_messages', 'ai_assistant_dialogs',
            'ai_assistant_index_sources', 'ai_assistant_indexes', 'ai_assistant_model_links',
            'ai_assistant_agents', 'ai_assistant_models', 'ai_assistant_providers',
            'ai_assistant_knowledge', 'ai_assistant_settings') as $table) {
            $db->requireExec('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
