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
                STATUS varchar(20) NOT NULL DEFAULT 'discovered', LOCATION_ID int NOT NULL DEFAULT 0,
                LAST_SEEN datetime NULL, METADATA $text NULL, HEALTH_CONFIG $text NULL,
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
            "CREATE TABLE IF NOT EXISTS zigbee_health_settings (
                ID int NOT NULL PRIMARY KEY, CONFIG $text NOT NULL
            )$tail",
            "CREATE TABLE IF NOT EXISTS zigbee_health_state (
                ID $id, SUBJECT varchar(100) NOT NULL UNIQUE, DATA $text NOT NULL
            )$tail",
        );
    }

    public static function install(Database $db): void
    {
        foreach (self::statements() as $sql) $db->requireExec($sql);
    }

    public static function upgrade(Database $db): void
    {
        $rows = $db->select('SHOW COLUMNS FROM zigbee_devices');
        $columns = array_column($rows, 'Field');
        if (!in_array('STATUS', $columns, true)) {
            // Existing installations already use these devices. Only future
            // discoveries should wait for explicit adoption.
            $db->requireExec("ALTER TABLE zigbee_devices ADD STATUS varchar(20) NOT NULL DEFAULT 'added'");
        }
        $defaults = array_column($rows, 'Default', 'Field');
        if (($defaults['STATUS'] ?? '') !== 'discovered') $db->requireExec("ALTER TABLE zigbee_devices ALTER COLUMN STATUS SET DEFAULT 'discovered'");
        if (!in_array('LOCATION_ID', $columns, true)) $db->requireExec('ALTER TABLE zigbee_devices ADD LOCATION_ID int NOT NULL DEFAULT 0');
        if (!in_array('HEALTH_CONFIG', $columns, true)) $db->requireExec('ALTER TABLE zigbee_devices ADD HEALTH_CONFIG mediumtext NULL');
        foreach (array_slice(self::statements(), -2) as $sql) $db->requireExec($sql);
    }
}
