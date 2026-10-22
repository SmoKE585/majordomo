<?php

namespace MajorDoMo\Modules\Zigbee;

use MajorDoMo\Core\Database;

final class Schema
{
    public static function statements(bool $sqlite = false): array
    {
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $text = $sqlite ? 'TEXT' : 'mediumtext';
        $ref = $sqlite ? 'INTEGER' : 'bigint unsigned';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        return array(
            "CREATE TABLE IF NOT EXISTS zigbee_bridges (
                ID $id, TITLE varchar(190) NOT NULL, BASE_TOPIC varchar(190) NOT NULL UNIQUE,
                ENABLED int NOT NULL DEFAULT 1, STATE varchar(30) NOT NULL DEFAULT 'unknown',
                LAST_SEEN datetime NULL, INFO $text NULL
            )$tail",
            "CREATE TABLE IF NOT EXISTS zigbee_devices (
                ID $id, BRIDGE_ID $ref NOT NULL, IEEE_ADDRESS varchar(64) NULL,
                FRIENDLY_NAME varchar(190) NOT NULL, TITLE varchar(190) NOT NULL DEFAULT '',
                MODEL varchar(190) NOT NULL DEFAULT '', VENDOR varchar(190) NOT NULL DEFAULT '',
                DESCRIPTION text NULL, AVAILABLE varchar(30) NOT NULL DEFAULT 'unknown',
                LAST_SEEN datetime NULL, METADATA $text NULL,
                UNIQUE (BRIDGE_ID, IEEE_ADDRESS), UNIQUE (BRIDGE_ID, FRIENDLY_NAME),
                FOREIGN KEY (BRIDGE_ID) REFERENCES zigbee_bridges(ID) ON DELETE CASCADE
            )$tail",
            "CREATE TABLE IF NOT EXISTS zigbee_properties (
                ID $id, DEVICE_ID $ref NOT NULL, PATH varchar(190) NOT NULL,
                LABEL varchar(190) NOT NULL DEFAULT '', EXPOSE $text NULL,
                RAW_VALUE $text NULL, VALUE $text NULL, UPDATED datetime NULL,
                UNIQUE (DEVICE_ID, PATH), FOREIGN KEY (DEVICE_ID) REFERENCES zigbee_devices(ID) ON DELETE CASCADE
            )$tail",
            "CREATE TABLE IF NOT EXISTS zigbee_bindings (
                ID $id, PROPERTY_ID $ref NOT NULL UNIQUE,
                LINKED_OBJECT varchar(190) NOT NULL DEFAULT '', LINKED_PROPERTY varchar(190) NOT NULL DEFAULT '',
                LINKED_METHOD varchar(190) NOT NULL DEFAULT '', ENABLED int NOT NULL DEFAULT 1,
                WRITE_ENABLED int NOT NULL DEFAULT 0, EVENT_MODE varchar(20) NOT NULL DEFAULT 'always',
                INCOMING $text NULL, OUTGOING $text NULL,
                LAST_DELIVERED $text NULL, LAST_ERROR text NULL,
                FOREIGN KEY (PROPERTY_ID) REFERENCES zigbee_properties(ID) ON DELETE CASCADE
            )$tail",
            "CREATE TABLE IF NOT EXISTS zigbee_events (
                ID $id, BRIDGE_ID $ref NOT NULL, DEVICE_ID $ref NULL, ADDED datetime NOT NULL,
                LEVEL varchar(20) NOT NULL, TOPIC varchar(512) NOT NULL, MESSAGE text NOT NULL,
                FOREIGN KEY (BRIDGE_ID) REFERENCES zigbee_bridges(ID) ON DELETE CASCADE,
                FOREIGN KEY (DEVICE_ID) REFERENCES zigbee_devices(ID) ON DELETE SET NULL
            )$tail",
        );
    }

    public static function install(Database $db): void
    {
        foreach (self::statements() as $sql) $db->requireExec($sql);
    }
}
