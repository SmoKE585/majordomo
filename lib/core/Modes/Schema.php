<?php

namespace MajorDoMo\Core\Modes;

use MajorDoMo\Core\Database;

final class Schema
{
    public static function statements(bool $sqlite = false): array
    {
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $text = $sqlite ? 'TEXT' : 'mediumtext';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        return array(
            "CREATE TABLE IF NOT EXISTS modes (
                ID $id, NAME varchar(100) NOT NULL UNIQUE, TITLE varchar(190) NOT NULL,
                DESCRIPTION text NOT NULL, ACTIVE int NOT NULL DEFAULT 0, ENABLED int NOT NULL DEFAULT 1,
                RULES $text NOT NULL, RUNTIME $text NOT NULL, ON_ACTION $text NOT NULL, OFF_ACTION $text NOT NULL,
                CHANGED_AT bigint NOT NULL DEFAULT 0, SOURCE varchar(190) NOT NULL DEFAULT '',
                LAST_ERROR text NOT NULL
            )$tail",
            "CREATE TABLE IF NOT EXISTS mode_history (
                ID $id, MODE_ID bigint NOT NULL, TITLE varchar(190) NOT NULL,
                ACTIVE int NOT NULL, SOURCE varchar(190) NOT NULL, ADDED bigint NOT NULL,
                ERROR text NOT NULL
            )$tail",
        );
    }

    public static function install(Database $db): void
    {
        foreach (self::statements() as $sql) $db->requireExec($sql);
    }
}
