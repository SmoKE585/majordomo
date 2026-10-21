<?php

namespace MajorDoMo\Core;

final class Database
{
    public function __construct(private object $connection) {}

    public function select(string $sql): array { return $this->connection->Select($sql); }
    public function one(string $sql): ?array { return $this->connection->SelectOne($sql) ?: null; }
    public function exec(string $sql, bool $ignoreErrors = false): mixed { return $this->connection->Exec($sql, $ignoreErrors); }
    public function requireExec(string $sql): mixed
    {
        $result = $this->exec($sql);
        if (!$result) throw new \RuntimeException('Core database operation failed');
        return $result;
    }
    public function insert(string $table, array &$record): int { return (int)$this->connection->Insert($table, $record); }
    public function update(string $table, array $record, string $key = 'ID'): bool { return (bool)$this->connection->Update($table, $record, $key); }
    public function escape(mixed $value): string { return $this->connection->DbSafe1($value); }
    public function escapeLike(mixed $value): string { return $this->connection->DbSafe($value); }
    public function affectedRows(): int { return $this->connection->AffectedRows(); }
    public function tableExists(string $table): bool
    {
        return $this->one("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $this->escape($table) . "'") !== null;
    }
}
