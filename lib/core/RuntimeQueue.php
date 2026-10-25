<?php

namespace MajorDoMo\Core;

/**
 * Долговечный журнал заданий: Redis исполняет очередь, SQL сохраняет её до подтверждения.
 * 2026-10-25 — общая транзакция со свойством, порядок по объекту и ограничение повторов после аварий.
 */
final class RuntimeQueue
{
    private int $maintainedAt = 0;
    public function __construct(private Database $db) {}

    public static function lane(string $operation, array $params, string $target): string
    {
        if (!empty($params['_lane'])) return mb_substr((string)$params['_lane'], 0, 160, 'UTF-8');
        $object = $operation === 'method' ? explode('.', $target, 2)[0]
            : ($operation === 'modulepropertyset' ? (string)($params['object'] ?? '') : '');
        return $object !== '' ? 'object:' . mb_substr($object, 0, 150, 'UTF-8') : 'serial';
    }

    public function enqueue(string $operation, string $payload, int $expires, ?string $sourceKey = null): int
    {
        // Старый enqueue() допускает непрозрачную строку; без контекста
        // такое задание относится к последовательной полосе.
        $data = json_decode($payload, true);
        if (!is_array($data)) $data = array();
        $params = is_array($data['params'] ?? null) ? $data['params'] : array();
        $row = array('DATANAME' => $operation, 'DATAVALUE' => $payload,
            'LANE' => self::lane($operation, $params, (string)($data['target'] ?? '')),
            'EXPIRE' => date('Y-m-d H:i:s', $expires), 'AVAILABLE_AT' => date('Y-m-d H:i:s'),
            'CREATED' => date('Y-m-d H:i:s'));
        if ($sourceKey !== null) {
            $existing = $this->db->one("SELECT ID FROM core_runtime_queue WHERE SOURCE_KEY='" . $this->db->escape($sourceKey) . "'");
            if ($existing) return (int)$existing['ID'];
            $row['SOURCE_KEY'] = $sourceKey;
        }
        $id = $this->db->insert('core_runtime_queue', $row);
        if (!$id) throw new \RuntimeException('Не удалось сохранить задание ядра');
        return $id;
    }

    /** Только короткое резервирование; пользовательский код не держит блокировку планировщика. */
    public function claim(int $limit, int $lease, bool $parallel): array
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->db->one("SELECT ID FROM core_runtime_queue WHERE STATUS='pending'"
            . " AND (CLAIM_TOKEN='' OR CLAIM_UNTIL<='$now') AND AVAILABLE_AT<='$now' LIMIT 1")) return array();
        $lock = 'mjd:runtime:' . substr(hash('sha256', (string)Config::get('DB_NAME', '')), 0, 40);
        $acquired = $this->db->one("SELECT GET_LOCK('" . $lock . "',5) AS OK");
        if ((int)($acquired['OK'] ?? 0) !== 1) return array();
        try {
            $this->db->requireExec("UPDATE core_runtime_queue SET STATUS='failed',LAST_ERROR=CASE WHEN EXPIRE<='$now'"
                . " THEN 'Истёк срок ожидания задания' ELSE 'Исполнитель аварийно завершился три раза' END,"
                . " FINISHED='$now',CLAIM_TOKEN='',CLAIM_UNTIL=NULL WHERE STATUS='pending'"
                . " AND (CLAIM_TOKEN='' OR CLAIM_UNTIL<='$now') AND (EXPIRE<='$now' OR ATTEMPTS>=3)");
            $active = $this->db->select("SELECT LANE FROM core_runtime_queue WHERE STATUS='pending' AND CLAIM_TOKEN<>'' AND CLAIM_UNTIL>'$now'");
            $busy = array_fill_keys(array_column($active, 'LANE'), true);
            if (isset($busy['serial']) || (!$parallel && $busy)) return array();
            $sameLane = $parallel ? ' AND older.LANE=q.LANE' : '';
            $rows = $this->db->select("SELECT q.* FROM core_runtime_queue q WHERE q.STATUS='pending' AND q.EXPIRE>'$now'"
                . " AND q.AVAILABLE_AT<='$now' AND (q.CLAIM_TOKEN='' OR q.CLAIM_UNTIL<='$now')"
                . " AND NOT EXISTS (SELECT 1 FROM core_runtime_queue older WHERE older.STATUS='pending'"
                . ' AND older.ID<q.ID' . $sameLane . ') ORDER BY q.ID LIMIT 1000');
            $result = array();
            foreach ($rows as $row) {
                $lane = $row['LANE'];
                // Готовая serial останавливает выдачу новых объектов, пока
                // закончатся уже работающие. Новые события её не обгоняют.
                if ($lane === 'serial' && ($busy || $result)) break;
                if (isset($busy[$lane])) continue;
                $token = bin2hex(random_bytes(16));
                $until = date('Y-m-d H:i:s', time() + max(5, $lease));
                $id = (int)$row['ID'];
                $this->db->requireExec("UPDATE core_runtime_queue SET CLAIM_TOKEN='$token',CLAIM_UNTIL='$until',ATTEMPTS=ATTEMPTS+1"
                    . " WHERE ID=$id AND STATUS='pending' AND (CLAIM_TOKEN='' OR CLAIM_UNTIL<='$now')");
                if ($this->db->affectedRows() !== 1) continue;
                $row['CLAIM_TOKEN'] = $token;
                $row['ATTEMPTS'] = (int)$row['ATTEMPTS'] + 1;
                $row['CLAIM_UNTIL'] = $until;
                $result[] = $this->present($row);
                $busy[$lane] = true;
                if (!$parallel || $lane === 'serial' || count($result) >= max(1, min(1000, $limit))) break;
            }
            return $result;
        } finally {
            $this->db->one("SELECT RELEASE_LOCK('" . $lock . "')");
        }
    }

    public function claimed(string $id, string $token): array
    {
        $row = $this->db->one("SELECT * FROM core_runtime_queue WHERE ID=" . (int)$id
            . " AND STATUS='pending' AND CLAIM_TOKEN='" . $this->db->escape($token) . "' AND CLAIM_UNTIL>NOW()");
        if (!$row || $token === '') throw new \RuntimeException('Владение заданием ядра истекло');
        return $this->present($row);
    }

    /** SQL-аренда сохраняет владение старого исполнителя даже после потери Redis. */
    public function reserve(array $item, int $lease, bool $parallel): ?array
    {
        $id = (int)$item['QUEUE_ID'];
        $now = date('Y-m-d H:i:s');
        $lock = 'mjd:runtime:' . substr(hash('sha256', (string)Config::get('DB_NAME', '')), 0, 40);
        $acquired = $this->db->one("SELECT GET_LOCK('$lock',5) AS OK");
        if ((int)($acquired['OK'] ?? 0) !== 1) return null;
        try {
            $row = $this->db->one("SELECT * FROM core_runtime_queue WHERE ID=$id AND STATUS='pending'"
                . " AND EXPIRE>'$now' AND AVAILABLE_AT<='$now' AND ATTEMPTS<3"
                . " AND (CLAIM_TOKEN='' OR CLAIM_UNTIL<='$now')");
            if (!$row) return null;
            $active = $this->db->select("SELECT LANE FROM core_runtime_queue WHERE STATUS='pending' AND CLAIM_TOKEN<>'' AND CLAIM_UNTIL>'$now'");
            $busy = array_fill_keys(array_column($active, 'LANE'), true);
            if (isset($busy['serial']) || isset($busy[$row['LANE']]) || (($row['LANE'] === 'serial' || !$parallel) && $busy)) return null;
            // Порядком готовых событий владеет Redis. Меньший SQL ID другой
            // транзакции может стать видимым позже и не должен запереть голову полосы.
            $token = $this->db->escape((string)$item['CLAIM_TOKEN']);
            $until = date('Y-m-d H:i:s', time() + max(5, $lease));
            $this->db->requireExec("UPDATE core_runtime_queue SET CLAIM_TOKEN='$token',CLAIM_UNTIL='$until',ATTEMPTS=ATTEMPTS+1 WHERE ID=$id"
                . " AND STATUS='pending' AND (CLAIM_TOKEN='' OR CLAIM_UNTIL<='$now')");
            if ($this->db->affectedRows() !== 1) return null;
            $row['CLAIM_TOKEN'] = (string)$item['CLAIM_TOKEN'];
            $row['CLAIM_UNTIL'] = $until;
            $row['ATTEMPTS'] = (int)$row['ATTEMPTS'] + 1;
            $row['LANE'] = $item['LANE'];
            $row = $this->present($row);
            $row['RUNTIME_REDIS'] = true;
            return $row;
        } finally { $this->db->one("SELECT RELEASE_LOCK('$lock')"); }
    }

    public function terminal(string $id): bool
    {
        $row = $this->db->one('SELECT STATUS FROM core_runtime_queue WHERE ID=' . (int)$id);
        return !$row || $row['STATUS'] !== 'pending';
    }

    /** Просроченные и трижды прерванные задания видимы, но больше не исполняются. */
    public function maintain(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->requireExec("UPDATE core_runtime_queue SET STATUS='failed',LAST_ERROR=CASE WHEN EXPIRE<='$now'"
            . " THEN 'Истёк срок ожидания задания' ELSE COALESCE(LAST_ERROR,'Исполнитель аварийно завершился три раза') END,"
            . "FINISHED='$now',CLAIM_TOKEN='',CLAIM_UNTIL=NULL WHERE STATUS='pending'"
            . " AND (CLAIM_TOKEN='' OR CLAIM_UNTIL<='$now') AND (EXPIRE<='$now' OR ATTEMPTS>=3)");
    }

    /** Передача SQL → Redis: повторная отправка того же ID не создаёт дубль. */
    public function dispatch(RedisConnection $connection, LaneEventQueue $lanes, bool $parallel, int $limit = 200): int
    {
        $redis = $connection->requireConnection();
        $generationKey = $connection->key('runtime:dispatch:generation');
        $generation = (string)$redis->get($generationKey);
        if ($generation === '') {
            $redis->set($generationKey, bin2hex(random_bytes(16)), array('nx'));
            $generation = (string)$redis->get($generationKey);
        }
        if (!preg_match('/^[a-f0-9]{32}$/D', $generation)) throw new \RuntimeException('Повреждена версия очереди Redis');
        if (time() - $this->maintainedAt >= 5) { $this->maintain(); $this->maintainedAt = time(); }
        if (!$this->db->one("SELECT ID FROM core_runtime_queue WHERE STATUS='pending' AND DISPATCH_GENERATION<>'$generation' LIMIT 1")) return 0;
        $lock = 'mjd:dispatch:' . substr(hash('sha256', (string)Config::get('DB_NAME', '')), 0, 40);
        $acquired = $this->db->one("SELECT GET_LOCK('$lock',5) AS OK");
        if ((int)($acquired['OK'] ?? 0) !== 1) return 0;
        try {
            $rows = $this->db->select("SELECT * FROM core_runtime_queue WHERE STATUS='pending' AND DISPATCH_GENERATION<>'$generation'"
                . ' ORDER BY ID LIMIT ' . max(1, min(1000, $limit)));
            foreach ($rows as $row) {
                $lanes->push(array('TOPIC' => 'core_runtime', 'DATANAME' => $row['DATANAME'], 'DATAVALUE' => $row['DATAVALUE']),
                    $parallel ? $row['LANE'] : 'serial', 0, (string)$row['ID'], (int)strtotime($row['EXPIRE']));
                $this->db->requireExec("UPDATE core_runtime_queue SET DISPATCH_GENERATION='$generation' WHERE ID=" . (int)$row['ID']);
            }
            return count($rows);
        } finally { $this->db->one("SELECT RELEASE_LOCK('$lock')"); }
    }

    public function acknowledge(array $item): bool
    {
        $this->db->requireExec('DELETE FROM core_runtime_queue WHERE ' . $this->owner($item));
        return $this->db->affectedRows() === 1;
    }

    public function release(array $item, int $delay, string $error = ''): bool
    {
        $available = date('Y-m-d H:i:s', time() + max(0, $delay));
        $this->db->requireExec("UPDATE core_runtime_queue SET AVAILABLE_AT='$available',CLAIM_TOKEN='',CLAIM_UNTIL=NULL,LAST_ERROR='"
            . $this->db->escape(mb_strcut($error, 0, 4096, 'UTF-8')) . "' WHERE " . $this->owner($item));
        return $this->db->affectedRows() === 1;
    }

    public function fail(array $item, string $error): bool
    {
        $this->db->requireExec("UPDATE core_runtime_queue SET STATUS='failed',FINISHED=NOW(),CLAIM_TOKEN='',CLAIM_UNTIL=NULL,LAST_ERROR='"
            . $this->db->escape(mb_strcut($error, 0, 4096, 'UTF-8')) . "' WHERE " . $this->owner($item));
        return $this->db->affectedRows() === 1;
    }

    /** Диагностика не выводит значения свойств, параметры методов и секреты модулей. */
    public function status(): array
    {
        return $this->db->one("SELECT COUNT(*) AS total,COALESCE(SUM(STATUS='failed'),0) AS failed,"
            . "COALESCE(SUM(STATUS='pending' AND CLAIM_TOKEN<>'' AND CLAIM_UNTIL>NOW()),0) AS running,"
            . "COALESCE(SUM(STATUS='pending'),0) AS pending,MIN(CASE WHEN STATUS='pending' THEN CREATED END) AS oldest"
            . ' FROM core_runtime_queue') ?? array();
    }

    public function failed(int $limit = 20): array
    {
        $rows = $this->db->select("SELECT ID,DATANAME,LANE,ATTEMPTS,CREATED,FINISHED,LAST_ERROR FROM core_runtime_queue"
            . " WHERE STATUS='failed' ORDER BY ID DESC LIMIT " . max(1, min(200, $limit)));
        foreach ($rows as &$row) $row['LAST_ERROR'] = Inspection\SensitiveText::clean((string)$row['LAST_ERROR']);
        return $rows;
    }

    private function owner(array $item): string
    {
        return 'ID=' . (int)$item['QUEUE_ID'] . " AND STATUS='pending' AND CLAIM_TOKEN<>'' AND CLAIM_TOKEN='"
            . $this->db->escape((string)$item['CLAIM_TOKEN']) . "' AND CLAIM_UNTIL>NOW()";
    }

    private function present(array $row): array
    {
        $row['QUEUE_ID'] = (string)$row['ID'];
        $row['TOPIC'] = 'core_runtime';
        $row['RUNTIME_SQL'] = true;
        return $row;
    }
}
