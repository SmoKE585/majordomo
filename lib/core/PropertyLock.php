<?php

namespace MajorDoMo\Core;

use \RuntimeException;

/**
 * Блокировка записи свойства на том же SQL-соединении, которое сохраняет значение.
 * 2026-11-11 — GET_LOCK используется и при включённом Redis: обрыв процесса
 * освобождает блокировку вместе с соединением, без двухминутной Redis-аренды.
 */
final class PropertyLock
{
    public function __construct(private Database $db, private RedisConnection $redis) {}

    public function acquire($object_id, $property)
    {
        $key = $this->redis->key('lock:property:') . (int)$object_id . ':' . hash('sha256', strtolower($property));
        // Хешируем весь ключ: длинный префикс не должен объединять разные свойства.
        $lock_name = 'mjd:property:' . substr(hash('sha256', $key), 0, 51);
        $row = $this->db->one("SELECT GET_LOCK('" . $this->db->escape($lock_name) . "',5) AS ACQUIRED");
        if ((int)($row['ACQUIRED'] ?? 0) !== 1) {
            throw new RuntimeException('Запись свойства ' . (int)$object_id . '.' . $property
                . ' занята другим соединением более 5 секунд; значение не записано');
        }
        return array($lock_name, '', 'sql');
    }

    public function check($lock)
    {
        $row = $this->db->one("SELECT IS_USED_LOCK('" . $this->db->escape($lock[0]) . "')=CONNECTION_ID() AS OWNED");
        if ((int)($row['OWNED'] ?? 0) !== 1) {
            throw new RuntimeException('Соединение потеряло блокировку записи свойства; значение не записано');
        }
    }

    public function release($lock)
    {
        $this->db->one("SELECT RELEASE_LOCK('" . $this->db->escape($lock[0]) . "')");
    }
}
