<?php

namespace MajorDoMo\Core\Api;

use MajorDoMo\Core\Database;

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

    public static function install(Database $db): void { $db->requireExec(self::sql()); }
}
