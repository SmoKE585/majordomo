<?php

namespace MajorDoMo\Core;

use \Throwable;

final class HistoryWriter
{
    public function __construct(private Database $db, private HistoryStorage $historyStorage) {}

    public function process($limit = 200)
    {
        $lock = $this->db->one("SELECT GET_LOCK('mjd:history-writer',0) AS OK");
        if ((int)($lock['OK'] ?? 0) !== 1) return 0;
        try {
            $queue = $this->db->select('SELECT * FROM phistory_queue ORDER BY ID LIMIT ' . max(1, min(1000, (int)$limit)));
            if (!$queue) return 0;
            $tables = array();
            foreach ($queue as $row) {
                $id = (int)$row['VALUE_ID'];
                if (!isset($tables[$id])) $tables[$id] = defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1 ? $this->historyStorage->table($id) : 'phistory';
            }
            $tails = $inserts = $updates = array();
            $this->db->requireExec('START TRANSACTION');
            try {
                foreach ($queue as $row) {
                    $id = (int)$row['VALUE_ID'];
                    $table = $tables[$id];
                    if (!isset($tails[$id])) $tails[$id] = $this->db->select("SELECT ID,VALUE,SOURCE,ADDED FROM `$table` WHERE VALUE_ID=$id ORDER BY ID DESC LIMIT 2");
                    $tail = &$tails[$id];
                    $value = mb_strcut((string)$row['VALUE'], 0, 255, 'UTF-8');
                    $source = (string)$row['SOURCE'];
                    $same = $row['VALUE'] == $row['OLD_VALUE'] && !(defined('HISTORY_NO_OPTIMIZE') && HISTORY_NO_OPTIMIZE == 1);
                    $compress = $same && count($tail) >= 2 && $tail[0]['VALUE'] == $tail[1]['VALUE']
                    && preg_replace('/\d+/', 'N', $source) === preg_replace('/\d+/', 'N', $tail[0]['SOURCE']);
                    if ($compress) {
                        $tail[0]['ADDED'] = $row['ADDED'];
                        if (isset($tail[0]['_INSERT'])) $inserts[$table][$tail[0]['_INSERT']]['ADDED'] = $row['ADDED'];
                        else $updates[$table][(int)$tail[0]['ID']] = $row['ADDED'];
                    } else {
                        $entry = array('VALUE_ID' => $id, 'ADDED' => $row['ADDED'], 'VALUE' => $value, 'SOURCE' => $source);
                        if (!isset($inserts[$table])) $inserts[$table] = array();
                        $index = count($inserts[$table]);
                        $inserts[$table][] = $entry;
                        $entry['_INSERT'] = $index;
                        array_unshift($tail, $entry);
                        $tail = array_slice($tail, 0, 2);
                    }
                    unset($tail);
                }
                foreach ($inserts as $table => $rows) {
                    // Bound packet size even when an installation permits large source strings.
                    foreach (array_chunk($rows, 100) as $chunk) {
                        $values = array();
                        foreach ($chunk as $row) $values[] = '(' . (int)$row['VALUE_ID'] . ",'" . $this->db->escape($row['ADDED']) . "','" . $this->db->escape($row['VALUE']) . "','" . $this->db->escape($row['SOURCE']) . "')";
                        $this->db->requireExec("INSERT INTO `$table` (VALUE_ID,ADDED,VALUE,SOURCE) VALUES " . implode(',', $values));
                    }
                }
                foreach ($updates as $table => $rows) {
                    $cases = array();
                    foreach ($rows as $id => $added) $cases[] = 'WHEN ' . (int)$id . " THEN '" . $this->db->escape($added) . "'";
                    $this->db->requireExec("UPDATE `$table` SET ADDED=CASE ID " . implode(' ', $cases) . ' END WHERE ID IN (' . implode(',', array_keys($rows)) . ')');
                }
                $this->db->requireExec('DELETE FROM phistory_queue WHERE ID IN (' . implode(',', array_map('intval', array_column($queue, 'ID'))) . ')');
                $this->db->requireExec('COMMIT');
            } catch (Throwable $e) {
                $this->db->exec('ROLLBACK', true);
                throw $e;
            }
            return count($queue);
        } finally {
            $this->db->one("SELECT RELEASE_LOCK('mjd:history-writer')");
        }
    }

    public function cleanup($limit = 20)
    {
        static $cursor = 0;
        $rows = $this->db->select('SELECT pvalues.ID,properties.KEEP_HISTORY,properties.DATA_TYPE FROM pvalues JOIN properties ON properties.ID=pvalues.PROPERTY_ID WHERE pvalues.ID>' . (int)$cursor . ' AND properties.KEEP_HISTORY>0 ORDER BY pvalues.ID LIMIT ' . max(1, (int)$limit));
        foreach ($rows as $row) {
            cleanUpValueHistory($row['ID'], $row['KEEP_HISTORY'], $row['DATA_TYPE']);
            $cursor = (int)$row['ID'];
        }
        if (!$rows) $cursor = 0;
    }
}
