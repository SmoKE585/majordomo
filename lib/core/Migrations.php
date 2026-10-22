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
            $this->mergeRows($keep, $rows);
        }
    }

    /**
     * Consolidates one or more pvalues rows into $keep.
     *
     * Used by duplicate cleanup and by startup maintenance when an
     * object-specific property becomes a class property. The newest value wins,
     * LINKED_MODULES is unioned (a subscription must never disappear on a
     * property move) and history rows are retargeted instead of deleted.
     */
    public function mergeRows(array $keep, array $rows): void
    {
        $keepId = (int)($keep['ID'] ?? 0);
        if ($keepId < 1) throw new RuntimeException('Cannot merge property values without a target');
        $rows = array_values(array_filter($rows, static fn($row) => (int)($row['ID'] ?? 0) > 0 && (int)($row['ID'] ?? 0) !== $keepId));
        if (!$rows) return;

        $newest = $keep;
        $modules = $this->moduleTokens($keep);
        foreach ($rows as $row) {
            $modules = array_merge($modules, $this->moduleTokens($row));
            if ($this->newerValueRow($row, $newest)) $newest = $row;
        }

        // CREATE TABLE is DDL and implicitly commits; prepare it before the batch.
        foreach ($rows as $row) {
            if ($this->db->tableExists('phistory_value_' . (int)$row['ID'])) $this->historyStorage->table($keepId);
        }

        $this->db->requireExec('START TRANSACTION');
        try {
            foreach ($rows as $row) {
                $old = (int)$row['ID'];
                foreach (array('phistory', 'phistory_queue', 'history') as $table) {
                    if ($this->db->tableExists($table)) {
                        $this->db->requireExec("UPDATE `$table` SET VALUE_ID=$keepId WHERE VALUE_ID=$old");
                    }
                }
                // Preserve separate history too. Old tables stay as a recovery archive;
                // a merge must not silently destroy the only copy of historic data.
                if ($this->db->tableExists('phistory_value_' . $old)) {
                    $target_table = 'phistory_value_' . $keepId;
                    $this->db->requireExec("INSERT INTO `$target_table` (VALUE_ID,ADDED,VALUE,SOURCE) SELECT $keepId,ADDED,VALUE,SOURCE FROM `phistory_value_$old`");
                }
                $this->db->requireExec('DELETE FROM pvalues WHERE ID=' . $old);
            }
            $record = array('ID' => $keepId,
                'LINKED_MODULES' => implode(',', array_values(array_unique($modules))));
            if ((int)($newest['ID'] ?? 0) !== $keepId) {
                $record['VALUE'] = (string)($newest['VALUE'] ?? '');
                $record['SOURCE'] = (string)($newest['SOURCE'] ?? '');
                $record['UPDATED'] = $newest['UPDATED'] ?? date('Y-m-d H:i:s');
            }
            if (!$this->db->update('pvalues', $record)) throw new RuntimeException('Cannot consolidate property values and bindings');
            $this->db->requireExec('COMMIT');
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK', true);
            throw $e;
        }
    }

    /** @return string[] */
    private function moduleTokens(array $row): array
    {
        $tokens = array();
        foreach (explode(',', (string)($row['LINKED_MODULES'] ?? '')) as $token) {
            $token = trim($token);
            if ($token !== '' && !in_array($token, $tokens, true)) $tokens[] = $token;
        }
        return $tokens;
    }

    private function newerValueRow(array $candidate, array $current): bool
    {
        $candidateTime = !empty($candidate['UPDATED']) ? (int)strtotime((string)$candidate['UPDATED']) : 0;
        $currentTime = !empty($current['UPDATED']) ? (int)strtotime((string)$current['UPDATED']) : 0;
        if ($candidateTime !== $currentTime) return $candidateTime > $currentTime;
        return (int)($candidate['ID'] ?? 0) > (int)($current['ID'] ?? 0);
    }

    public function run()
    {
        $lock = $this->db->one("SELECT GET_LOCK('mjd:core-migrations',30) AS OK");
        if ((int)($lock['OK'] ?? 0) !== 1) throw new RuntimeException('Core migration is already running');
        try {
            $this->db->requireExec("CREATE TABLE IF NOT EXISTS core_migrations (VERSION varchar(100) NOT NULL PRIMARY KEY, APPLIED datetime NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8");
            $this->apply('20261021_core_runtime_v1', fn() => $this->upgradeRuntime());
            $this->apply('20261022_system_events_v1', fn() => $this->ensureSystemEventsSchema());
            $this->apply('20261023_property_audit_v1', fn() => $this->ensurePropertyAuditSchema());
            $this->apply('20261024_core_api_v1', function () {
                Api\Schema::install($this->db);
                $table = $this->db->one("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='locations'");
                if ($table && strcasecmp($table['ENGINE'], 'InnoDB') !== 0) $this->db->requireExec('ALTER TABLE locations ENGINE=InnoDB');
            });
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

    /**
     * Persistent, always-on property audit journal.
     *
     * `property_audit_runs` is the run header (trigger, actor, input params,
     * final result); `property_audit_events` stores the timeline: jobs,
     * methods, scripts, module handlers and property writes. Keeping property
     * changes in the same event stream means the UI can render the complete
     * causal chain without reconstructing it from several histories.
     */
    public function ensurePropertyAuditSchema(): void
    {
        $this->db->requireExec(
            "CREATE TABLE IF NOT EXISTS property_audit_runs (" .
            "ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY," .
            "TRACE_ID char(32) NOT NULL," .
            "STARTED datetime NOT NULL," .
            "LAST_SEEN datetime NOT NULL," .
            "FINISHED datetime NULL," .
            "STATUS varchar(16) NOT NULL DEFAULT 'running'," .
            "TRIGGER_TYPE varchar(32) NOT NULL DEFAULT ''," .
            "TRIGGER_TITLE varchar(255) NOT NULL DEFAULT ''," .
            "SOURCE varchar(255) NOT NULL DEFAULT ''," .
            "ACTOR varchar(255) NOT NULL DEFAULT ''," .
            "IP varchar(45) NOT NULL DEFAULT ''," .
            "REQUEST_URI varchar(255) NOT NULL DEFAULT ''," .
            "PARAMS mediumtext NULL," .
            "RESULT mediumtext NULL," .
            "ERROR text NULL," .
            "CHAIN text NULL," .
            "EVENTS_COUNT int unsigned NOT NULL DEFAULT 0," .
            "UNIQUE KEY uq_property_audit_trace (TRACE_ID)," .
            "KEY idx_property_audit_started (STARTED)," .
            "KEY idx_property_audit_status (STATUS,STARTED)" .
            ") ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8"
        );
        $this->db->requireExec(
            "CREATE TABLE IF NOT EXISTS property_audit_events (" .
            "ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY," .
            "RUN_ID bigint unsigned NOT NULL DEFAULT 0," .
            "TRACE_ID char(32) NOT NULL," .
            "ADDED datetime NOT NULL," .
            "FINISHED datetime NULL," .
            "DURATION_MS int unsigned NOT NULL DEFAULT 0," .
            "TYPE varchar(32) NOT NULL DEFAULT ''," .
            "STATUS varchar(16) NOT NULL DEFAULT 'running'," .
            "TITLE varchar(255) NOT NULL DEFAULT ''," .
            "SUBTITLE varchar(255) NOT NULL DEFAULT ''," .
            "OBJECT_ID int NOT NULL DEFAULT 0," .
            "PROPERTY_ID int NOT NULL DEFAULT 0," .
            "PROPERTY_NAME varchar(255) NOT NULL DEFAULT ''," .
            "METHOD_ID int NOT NULL DEFAULT 0," .
            "CLASS_ID int NOT NULL DEFAULT 0," .
            "SCRIPT_ID int NOT NULL DEFAULT 0," .
            "MODULE varchar(100) NOT NULL DEFAULT ''," .
            "OLD_VALUE mediumtext NULL," .
            "NEW_VALUE mediumtext NULL," .
            "SOURCE varchar(255) NOT NULL DEFAULT ''," .
            "PARAMS mediumtext NULL," .
            "OUT_PARAMS mediumtext NULL," .
            "RESULT mediumtext NULL," .
            "ERROR text NULL," .
            "CHAIN text NULL," .
            "KEY idx_property_audit_events_trace (TRACE_ID,ID)," .
            "KEY idx_property_audit_events_property (PROPERTY_ID,OBJECT_ID,ADDED)," .
            "KEY idx_property_audit_events_added (ADDED)," .
            "KEY idx_property_audit_events_type (TYPE,ADDED)" .
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
