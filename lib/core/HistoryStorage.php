<?php

namespace MajorDoMo\Core;

final class HistoryStorage
{
    private array $checked = array();

    public function __construct(private Database $db) {}

    public function table($valueId): string
    {
        $valueId = (int)$valueId;
        if ($valueId < 1) throw new \InvalidArgumentException('Invalid history value ID');
        if (isset($this->checked[$valueId])) return $this->checked[$valueId];
        $table = 'phistory_value_' . $valueId;
        $this->db->requireExec("CREATE TABLE IF NOT EXISTS `$table` (
            ID int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            VALUE_ID int unsigned NOT NULL DEFAULT 0, ADDED datetime DEFAULT NULL,
            VALUE varchar(255) NOT NULL, SOURCE varchar(255) NOT NULL DEFAULT '',
            KEY idx_phistory_value_id (VALUE_ID,ID), KEY idx_phistory_value_added (VALUE_ID,ADDED)
        ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8");
        $source = $this->db->one("SHOW COLUMNS FROM `$table` LIKE 'SOURCE'");
        if (!$source) $this->db->requireExec("ALTER TABLE `$table` ADD SOURCE varchar(255) NOT NULL DEFAULT ''");
        elseif (preg_match('/^varchar\((\d+)\)/i', $source['Type'], $width) && (int)$width[1] < 255) $this->db->requireExec("ALTER TABLE `$table` MODIFY SOURCE varchar(255) NOT NULL DEFAULT ''");
        foreach (array('idx_phistory_value_id'=>'VALUE_ID,ID','idx_phistory_value_added'=>'VALUE_ID,ADDED') as $index=>$columns) {
            if (!$this->db->one("SHOW INDEX FROM `$table` WHERE Key_name='$index'")) $this->db->requireExec("ALTER TABLE `$table` ADD INDEX `$index` ($columns)");
        }
        return $this->checked[$valueId] = $table;
    }

    public function moveFromMain($valueId): bool
    {
        $valueId = (int)$valueId;
        $table = $this->table($valueId);
        return $this->move($valueId, 'phistory', $table, false);
    }

    public function moveToMain($valueId): bool
    {
        $valueId = (int)$valueId;
        if ($valueId < 1) throw new \InvalidArgumentException('Invalid history value ID');
        $table = 'phistory_value_' . $valueId;
        if (!$this->db->tableExists($table)) return false;
        return $this->move($valueId, $table, 'phistory', true);
    }

    private function move(int $valueId, string $source, string $target, bool $dropSource): bool
    {
        $lock = $this->db->one("SELECT GET_LOCK('mjd:history-writer',30) AS OK");
        if ((int)($lock['OK'] ?? 0) !== 1) throw new \RuntimeException('History writer is busy');
        try {
            $this->db->requireExec('START TRANSACTION');
            try {
                if ($dropSource) $this->db->requireExec("DELETE FROM `$target` WHERE VALUE_ID=$valueId");
                $this->db->requireExec("INSERT INTO `$target` (VALUE_ID,ADDED,VALUE,SOURCE) SELECT VALUE_ID,ADDED,VALUE,SOURCE FROM `$source` WHERE VALUE_ID=$valueId");
                if (!$dropSource) $this->db->requireExec("DELETE FROM `$source` WHERE VALUE_ID=$valueId");
                $this->db->requireExec('COMMIT');
            } catch (\Throwable $e) {
                $this->db->exec('ROLLBACK', true);
                throw $e;
            }
            if ($dropSource) {
                $this->db->requireExec("DROP TABLE `$source`");
                unset($this->checked[$valueId]);
            }
            return true;
        } finally {
            $this->db->one("SELECT RELEASE_LOCK('mjd:history-writer')");
        }
    }
}
