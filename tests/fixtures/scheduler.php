<?php
/** 2026-10-25 — таймеры и счётчик SQL на изолированном SQLite. */
require_once __DIR__ . '/runtime_database.php';

function createSchedulerFixture(PDO $pdo): void
{
    if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Нет изоляции SQLite');
    $pdo->exec("CREATE TABLE jobs (ID INTEGER PRIMARY KEY AUTOINCREMENT, TITLE TEXT NOT NULL, COMMANDS TEXT, RUNTIME TEXT, EXPIRE TEXT, STARTED TEXT,
        PROCESSED INTEGER DEFAULT 0, EXPIRED INTEGER DEFAULT 0, CLAIM_TOKEN TEXT DEFAULT '', ATTEMPTS INTEGER DEFAULT 0, LAST_ERROR TEXT,
        TRACE_ID TEXT DEFAULT '', PARENT_EVENT_ID INTEGER DEFAULT 0, DISPATCH_GENERATION TEXT DEFAULT '')");
}

final class SchedulerTestConnection
{
    public array $queries = array();
    private RuntimeTestConnection $inner;
    public function __construct(private PDO $pdo) { $this->inner = new RuntimeTestConnection($pdo); }
    public function __call($method, $args) { return $this->inner->$method(...$args); }
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
    public function afterTransaction(array $commit, array $rollback): void { $this->inner->afterTransaction($commit, $rollback); }
    public function Select($sql): array { $this->queries[] = $sql; return $this->inner->Select($sql); }
    public function SelectOne($sql): array
    {
        $this->queries[] = $sql;
        if (str_contains($sql, 'information_schema.TABLES')) {
            preg_match("/TABLE_NAME='([^']+)'/", $sql, $match);
            return $this->inner->SelectOne("SELECT name AS TABLE_NAME FROM sqlite_master WHERE type='table' AND name='" . ($match[1] ?? '') . "'");
        }
        return $this->inner->SelectOne($sql);
    }
    public function Exec($sql, $ignore = false): bool { $this->queries[] = $sql; return $this->inner->Exec($sql, $ignore); }
    public function Insert($table, &$row): int { return $this->inner->Insert($table, $row); }
}
