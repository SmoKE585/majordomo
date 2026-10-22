<?php

namespace MajorDoMo\Core\Api;

use MajorDoMo\Core\Database;

/**
 * DDL for API tokens and public webhook entries.
 * Изменения: 2026-10-22 — добавлена схема api_hooks.
 */
final class Schema
{
    public static function sql(bool $sqlite = false): string
    {
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4';
        return "CREATE TABLE IF NOT EXISTS api_tokens (
            ID $id, NAME varchar(190) NOT NULL, TOKEN_HASH char(64) NOT NULL UNIQUE,
            PREFIX varchar(16) NOT NULL, CREATED_AT bigint NOT NULL, EXPIRES_AT bigint DEFAULT NULL,
            REVOKED_AT bigint DEFAULT NULL, LAST_USED_AT bigint DEFAULT NULL
        )$tail";
    }

    /**
     * Public webhook entries. SECRET_HASH is the only stored form of the URL
     * secret; HEADER_SECRET_HASH stores the optional extra header secret.
     *
     * @return string[]
     */
    public static function hooksStatements(bool $sqlite = false): array
    {
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4';
        $moduleIndex = $sqlite ? '' : ",\n                KEY idx_api_hooks_module (MODULE)";
        $statements = array(
            "CREATE TABLE IF NOT EXISTS api_hooks (
                ID $id,
                NAME varchar(190) NOT NULL,
                MODULE varchar(100) NOT NULL,
                HANDLER varchar(255) NOT NULL,
                SECRET_HASH char(64) NOT NULL UNIQUE,
                SECRET_PREFIX varchar(16) NOT NULL,
                METHODS varchar(64) NOT NULL DEFAULT 'POST',
                HEADER_NAME varchar(100) DEFAULT NULL,
                HEADER_SECRET_HASH char(64) DEFAULT NULL,
                ENABLED tinyint unsigned NOT NULL DEFAULT 1,
                CREATED_AT bigint NOT NULL,
                UPDATED_AT bigint NOT NULL,
                LAST_USED_AT bigint DEFAULT NULL,
                LAST_STATUS int DEFAULT NULL,
                LAST_METHOD varchar(10) DEFAULT NULL$moduleIndex
            )$tail",
        );
        if ($sqlite) {
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_api_hooks_module ON api_hooks (MODULE)';
        }
        return $statements;
    }

    public static function install(Database $db): void
    {
        $db->requireExec(self::sql());
        foreach (self::hooksStatements() as $statement) {
            $db->requireExec($statement);
        }
    }

    public static function installHooks(Database $db): void
    {
        foreach (self::hooksStatements() as $statement) {
            $db->requireExec($statement);
        }
    }
}
