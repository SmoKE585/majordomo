<?php

namespace MajorDoMo\Core;

use \Throwable;
use \RuntimeException;

final class Migrations
{
    public function __construct(
        private Database $db,
        private Cache $cache,
        private OperationsQueue $queue,
        private HistoryStorage $historyStorage,
        private WebSocketQueue $websockets
    ) {}

    public function ensureOperationsSchema()
    {
        $this->db->requireExec("CREATE TABLE IF NOT EXISTS operations_queue (
        ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
        TOPIC varchar(255) NOT NULL, DATANAME varchar(1024) NOT NULL,
        DATAVALUE mediumtext NOT NULL, EXPIRE datetime NOT NULL,
        CLAIM_TOKEN char(32) NOT NULL DEFAULT '', CLAIM_UNTIL datetime DEFAULT NULL, ATTEMPTS int unsigned NOT NULL DEFAULT 0,
        KEY idx_queue_topic_id (TOPIC,ID), KEY idx_queue_expire (EXPIRE)
    ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8");
        $columns = array_column($this->db->select('SHOW COLUMNS FROM operations_queue'), 'Field');
        foreach (array(
            'ID' => 'bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'CLAIM_TOKEN' => "char(32) NOT NULL DEFAULT ''",
            'CLAIM_UNTIL' => 'datetime DEFAULT NULL',
            'ATTEMPTS' => 'int unsigned NOT NULL DEFAULT 0'
        ) as $name => $definition) {
            if (!in_array($name, $columns, true)) $this->db->requireExec("ALTER TABLE operations_queue ADD `$name` $definition");
        }
        $engine = $this->db->one("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='operations_queue'");
        $value_column = $this->db->one("SHOW COLUMNS FROM operations_queue LIKE 'DATAVALUE'");
        if (strcasecmp($engine['ENGINE'], 'InnoDB') !== 0 || strcasecmp($value_column['Type'], 'mediumtext') !== 0) {
            $this->db->requireExec('ALTER TABLE operations_queue ENGINE=InnoDB, ROW_FORMAT=DYNAMIC, MODIFY DATAVALUE mediumtext NOT NULL, MODIFY DATANAME varchar(1024) NOT NULL');
        }
        $this->db->requireExec("CREATE TABLE IF NOT EXISTS operations_failed (ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,TOPIC varchar(255) NOT NULL,PAYLOAD mediumtext NOT NULL,LAST_ERROR text NOT NULL,ADDED datetime NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8");
    }

    public function ensureIndex($table, $name, $columns, $unique = false)
    {
        $indexes = $this->db->select("SHOW INDEX FROM `$table`");
        $existing = array();
        foreach ($indexes as $index) {
            $existing[$index['Key_name']]['columns'][(int)$index['Seq_in_index']] = $index['Column_name'];
            $existing[$index['Key_name']]['unique'] = !(int)$index['Non_unique'];
        }
        foreach ($existing as $index) {
            ksort($index['columns']);
            if (implode(',', $index['columns']) === $columns && (!$unique || $index['unique'])) return;
        }
        $this->db->requireExec("ALTER TABLE `$table` ADD " . ($unique ? 'UNIQUE ' : '') . "INDEX `$name` ($columns)");
    }

    public function removeLegacyConnect()
    {
        // Ownership is explicit. 'connected' and 'connectionStatus' belong to users.
        $this->db->requireExec("DELETE FROM project_modules WHERE NAME='connect'");
        $this->db->requireExec("DELETE FROM settings WHERE NAME='MODULE_CONNECT'");
        // Confirmed from the removed module's dbInstall(): no remaining core consumers.
        $this->db->requireExec('DROP TABLE IF EXISTS public_calls');
        foreach ($this->db->select("SELECT * FROM settings WHERE NAME LIKE 'HOOK_EVENT_%'") as $setting) {
            $data = json_decode($setting['VALUE'], true);
            if (is_array($data) && array_key_exists('connect', $data)) {
                unset($data['connect']);
                $record = array('ID' => $setting['ID'], 'VALUE' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                if (!$this->db->update('settings', $record)) throw new RuntimeException('Cannot remove Connect subscription');
            }
        }
        // These exact system properties were used only by the removed cycle.
        $properties = $this->db->select("SELECT ID FROM properties WHERE TITLE IN ('cycle_connectRun','cycle_connectControl','cycle_connectDisabled','cycle_connectAutoRestart','cycle_connectLastError')");
        foreach ($properties as $property) {
            foreach ($this->db->select('SELECT ID FROM pvalues WHERE PROPERTY_ID=' . (int)$property['ID']) as $value) {
                $id = (int)$value['ID'];
                $this->db->requireExec('DELETE FROM phistory WHERE VALUE_ID=' . $id);
                $this->db->requireExec('DELETE FROM phistory_queue WHERE VALUE_ID=' . $id);
                $this->db->requireExec('DELETE FROM history WHERE VALUE_ID=' . $id);
                if ($this->db->tableExists('phistory_value_' . $id)) $this->db->requireExec('DROP TABLE `phistory_value_' . $id . '`');
                $this->db->requireExec('DELETE FROM pvalues WHERE ID=' . $id);
            }
            $this->db->requireExec('DELETE FROM properties WHERE ID=' . (int)$property['ID']);
        }
    }

    public function mergeDuplicateValues()
    {
        $duplicates = $this->db->select('SELECT PROPERTY_ID,OBJECT_ID FROM pvalues GROUP BY PROPERTY_ID,OBJECT_ID HAVING COUNT(*)>1');
        foreach ($duplicates as $duplicate) {
            $rows = $this->db->select('SELECT * FROM pvalues WHERE PROPERTY_ID=' . (int)$duplicate['PROPERTY_ID'] . ' AND OBJECT_ID=' . (int)$duplicate['OBJECT_ID'] . ' ORDER BY UPDATED DESC,ID DESC');
            $keep = array_shift($rows);
            $modules = array_filter(explode(',', $keep['LINKED_MODULES']));
            foreach ($rows as $row) if ($this->db->tableExists('phistory_value_' . (int)$row['ID'])) $this->historyStorage->table((int)$keep['ID']);
            $this->db->requireExec('START TRANSACTION');
            try {
                foreach ($rows as $row) {
                    $old = (int)$row['ID'];
                    $target = (int)$keep['ID'];
                    $modules = array_merge($modules, array_filter(explode(',', $row['LINKED_MODULES'])));
                    foreach (array('phistory', 'phistory_queue', 'history') as $table) $this->db->requireExec("UPDATE `$table` SET VALUE_ID=$target WHERE VALUE_ID=$old");
                    // Preserve separate history too. Old tables stay as a recovery archive;
                    // a migration must not silently destroy the only copy of historic data.
                    if ($this->db->tableExists('phistory_value_' . $old)) {
                        $target_table = 'phistory_value_' . $target;
                        $this->db->requireExec("INSERT INTO `$target_table` (VALUE_ID,ADDED,VALUE,SOURCE) SELECT $target,ADDED,VALUE,SOURCE FROM `phistory_value_$old`");
                    }
                    $this->db->requireExec('DELETE FROM pvalues WHERE ID=' . $old);
                }
                $record = array('ID' => $keep['ID'], 'LINKED_MODULES' => implode(',', array_unique($modules)));
                if (!$this->db->update('pvalues', $record)) throw new RuntimeException('Cannot consolidate property bindings');
                $this->db->requireExec('COMMIT');
            } catch (Throwable $e) {
                $this->db->exec('ROLLBACK', true);
                throw $e;
            }
        }
    }

    public function run()
    {
        $lock = $this->db->one("SELECT GET_LOCK('mjd:core-migrations',30) AS OK");
        if ((int)($lock['OK'] ?? 0) !== 1) throw new RuntimeException('Core migration is already running');
        try {
            $this->db->requireExec("CREATE TABLE IF NOT EXISTS core_migrations (VERSION varchar(100) NOT NULL PRIMARY KEY, APPLIED datetime NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8");
            $this->apply('20261021_core_runtime_v1', fn() => $this->upgradeRuntime());
            $this->apply('20261022_system_events_v1', fn() => $this->ensureSystemEventsSchema());
        } finally {
            $this->db->one("SELECT RELEASE_LOCK('mjd:core-migrations')");
        }
    }

    /** Runs one versioned migration exactly once. */
    private function apply(string $version, callable $migration): void
    {
        if ($this->db->one("SELECT VERSION FROM core_migrations WHERE VERSION='" . $this->db->escape($version) . "'")) return;
        $migration();
        $this->db->requireExec("INSERT INTO core_migrations (VERSION,APPLIED) VALUES ('" . $this->db->escape($version) . "',NOW())");
    }

    /**
     * Append-only system event log used by MajorDoMo\Core\Events\EventBus.
     * `PROCESSED` is the queue flag for module subscribers; history retention is
     * applied by EventBus::prune() on maintenance runs.
     */
    private function ensureSystemEventsSchema(): void
    {
        $this->db->requireExec(
            "CREATE TABLE IF NOT EXISTS system_events (" .
            "ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY," .
            "NAME varchar(100) NOT NULL," .
            "PAYLOAD mediumtext NULL," .
            "SOURCE varchar(100) NOT NULL DEFAULT ''," .
            "CREATED datetime NOT NULL," .
            "PROCESSED tinyint unsigned NOT NULL DEFAULT 0," .
            "PROCESSED_AT datetime NULL," .
            "ERROR text NULL," .
            "KEY idx_system_events_created (CREATED)," .
            "KEY idx_system_events_name (NAME,CREATED)," .
            "KEY idx_system_events_queue (PROCESSED,ID)" .
            ") ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8"
        );
    }
    private function upgradeRuntime(): void
    {
        $this->ensureOperationsSchema();
        $tables = array('objects', 'classes', 'properties', 'methods', 'pvalues', 'phistory', 'phistory_queue', 'history', 'jobs', 'operations_queue');
        foreach ($this->db->select("SHOW TABLES LIKE 'phistory_value_%'") as $row) {
            $name = current($row);
            if (preg_match('/^phistory_value_\d+$/D', $name)) $tables[] = $name;
        }
        foreach ($tables as $table) {
            $status = $this->db->one("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $table . "'");
            if ($status && strcasecmp($status['ENGINE'], 'InnoDB') !== 0) $this->db->requireExec("ALTER TABLE `$table` ENGINE=InnoDB, ROW_FORMAT=DYNAMIC");
        }
        $job_columns = array_column($this->db->select('SHOW COLUMNS FROM jobs'), 'Field');
        foreach (array('CLAIM_TOKEN' => "char(32) NOT NULL DEFAULT ''", 'ATTEMPTS' => 'int unsigned NOT NULL DEFAULT 0', 'LAST_ERROR' => 'text NULL') as $column => $definition) {
            if (!in_array($column, $job_columns, true)) $this->db->requireExec("ALTER TABLE jobs ADD `$column` $definition");
        }
        $ws_columns = array_column($this->db->select('SHOW COLUMNS FROM cached_ws'), 'Field');
        if (!in_array('REVISION', $ws_columns, true)) $this->db->requireExec("ALTER TABLE cached_ws ADD REVISION char(32) NOT NULL DEFAULT ''");
        $this->mergeDuplicateValues();
        foreach (array(
            array('pvalues', 'uq_pvalues_property_object', 'PROPERTY_ID,OBJECT_ID', true),
            array('classes', 'idx_classes_title', 'TITLE', false),
            array('objects', 'idx_objects_title', 'TITLE', false),
            array('objects', 'idx_objects_class', 'CLASS_ID', false),
            array('properties', 'idx_properties_object_title', 'OBJECT_ID,TITLE', false),
            array('properties', 'idx_properties_class_title', 'CLASS_ID,TITLE', false),
            array('methods', 'idx_methods_object_title', 'OBJECT_ID,TITLE', false),
            array('methods', 'idx_methods_class_title', 'CLASS_ID,TITLE', false),
            array('jobs', 'idx_jobs_due', 'PROCESSED,EXPIRED,RUNTIME,ID', false),
            array('jobs', 'idx_jobs_title', 'TITLE', false),
            array('jobs', 'idx_jobs_expire', 'EXPIRE', false),
            array('operations_queue', 'idx_queue_topic_id', 'TOPIC,ID', false),
            array('operations_queue', 'idx_queue_expire', 'EXPIRE', false),
            array('phistory', 'idx_phistory_value_added', 'VALUE_ID,ADDED', false),
            array('phistory', 'idx_phistory_value_id', 'VALUE_ID,ID', false)
        ) as $index) $this->ensureIndex(...$index);
        $this->removeLegacyConnect();
        if (Config::get('USE_REDIS')) {
            foreach ($this->db->select('SELECT * FROM operations_queue ORDER BY ID') as $row) {
                $ttl = strtotime($row['EXPIRE']) - time();
                if ($ttl > 0 && !$this->queue->enqueue($row['TOPIC'], $row['DATANAME'], $row['DATAVALUE'], false, $ttl)) throw new RuntimeException('Cannot migrate pending operation');
                $this->db->requireExec('DELETE FROM operations_queue WHERE ID=' . (int)$row['ID']);
            }
            if (!Config::bool('DISABLE_WEBSOCKETS')) {
                foreach ($this->db->select('SELECT * FROM cached_ws') as $row) {
                    if (!$this->websockets->post($row['PROPERTY'], $row['DATAVALUE'], $row['POST_ACTION'])) throw new RuntimeException('Cannot migrate pending WebSocket update');
                    $this->db->requireExec("DELETE FROM cached_ws WHERE PROPERTY='" . $this->db->escape($row['PROPERTY']) . "' AND REVISION='" . $this->db->escape($row['REVISION']) . "'");
                }
            }
            $this->cache->clear();
        }
    }

}
