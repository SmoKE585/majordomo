<?php

namespace MajorDoMo\Core;

/**
 * Сроки таймеров в Redis; jobs остаётся долговечным журналом и владельцем аренды.
 * 2026-10-25 — восстановление индекса после потери Redis без ежесекундного SQL-опроса.
 */
final class SchedulerQueue
{
    private string $generation = '';
    private int $syncedAt = 0;
    private int $reportedAt = 0;

    public function __construct(private Database $db, private RedisConnection $connection) {}

    /** Публикация только после COMMIT: откат транзакции не оставляет таймер в Redis. */
    public function changed(int $id): void
    {
        $this->db->afterTransaction(function () use ($id): void {
            try {
                if (!$this->connection->get()) return;
                $redis = $this->ready();
                $row = $this->db->one('SELECT * FROM jobs WHERE ID=' . $id);
                if (!$row || (int)$row['PROCESSED'] || (int)$row['EXPIRED']) {
                    $redis->zRem($this->connection->key('scheduler:due'), self::member($id));
                    return;
                }
                $this->publish($redis, $row, false);
            } catch (\Throwable $error) { $this->report($error); }
        }, static function (): void {});
    }

    /** null включает SQL-режим, пустой массив означает здоровый простой Redis. */
    public function due(int $limit, int $lease): ?array
    {
        if (!$this->connection->get()) return null;
        try {
            $redis = $this->ready();
            // Подбираем записи, у которых процесс оборвался между COMMIT и Redis.
            // Во время обычного простоя SQL читается раз в 30 секунд.
            if (time() - $this->syncedAt >= 30) {
                $rows = $this->db->select("SELECT * FROM jobs WHERE PROCESSED=0 AND EXPIRED=0 AND DISPATCH_GENERATION<>'"
                    . $this->generation . "' ORDER BY ID LIMIT 500");
                foreach ($rows as $row) $this->publish($redis, $row, true);
                if (count($rows) < 500) $this->syncedAt = time();
            }
            // Переносим срок, а не удаляем ID: смерть планировщика не теряет задание.
            $ids = $redis->eval("local ids=redis.call('ZRANGEBYSCORE',KEYS[1],'-inf',ARGV[1],'LIMIT',0,ARGV[3]); "
                . "for _,id in ipairs(ids) do redis.call('ZADD',KEYS[1],ARGV[2],id) end; return ids",
                array($this->connection->key('scheduler:due'), time(), time() + $lease, $limit), 1);
            return array_map(static fn($id) => (string)(int)$id, $ids);
        } catch (\Throwable $error) {
            $this->report($error);
            return null;
        }
    }

    private function ready(): object
    {
        $redis = $this->connection->requireConnection();
        // Маркер внутри самого sorted set обнаруживает вытеснение только индекса,
        // даже если отдельный ключ поколения пережил нехватку памяти Redis.
        $generation = (string)$redis->eval("local g=redis.call('GET',KEYS[1]); "
            . "if not g or not redis.call('ZSCORE',KEYS[2],'@ready') then "
            . "g=ARGV[1]; redis.call('DEL',KEYS[2]); redis.call('ZADD',KEYS[2],'+inf','@ready'); redis.call('SET',KEYS[1],g) end; return g",
            array($this->connection->key('scheduler:generation'), $this->connection->key('scheduler:due'), bin2hex(random_bytes(16))), 2);
        if (!preg_match('/^[a-f0-9]{32}$/D', $generation)) throw new \RuntimeException('Повреждён индекс планировщика Redis');
        if ($this->generation !== $generation) { $this->generation = $generation; $this->syncedAt = 0; }
        return $redis;
    }

    private function publish(object $redis, array $row, bool $recover): void
    {
        $score = (int)strtotime((string)$row['RUNTIME']);
        if (!empty($row['CLAIM_TOKEN']) && !empty($row['STARTED'])) {
            $score = max($score, (int)strtotime($row['STARTED']) + Scheduler::timeout() + 60);
        }
        $key = $this->connection->key('scheduler:due');
        // Восстановление не сокращает уже выданную Redis-аренду другого процесса.
        $redis->eval("if ARGV[3]=='0' or not redis.call('ZSCORE',KEYS[1],ARGV[1]) then redis.call('ZADD',KEYS[1],ARGV[2],ARGV[1]) end; return 1",
            array($key, self::member((int)$row['ID']), $score, $recover ? '1' : '0'), 1);
        $this->db->requireExec("UPDATE jobs SET DISPATCH_GENERATION='" . $this->generation . "' WHERE ID=" . (int)$row['ID']);
    }

    private function report(\Throwable $error): void
    {
        $this->connection->discard();
        if (time() - $this->reportedAt < 60) return;
        $this->reportedAt = time();
        \DebMes('Планировщик временно использует SQL: ' . $error->getMessage(), 'scheduled_jobs', 'warning');
    }

    private static function member(int $id): string
    {
        // При одинаковом сроке Redis сравнивает строки: 2 должно предшествовать 10.
        return str_pad((string)$id, 20, '0', STR_PAD_LEFT);
    }
}
