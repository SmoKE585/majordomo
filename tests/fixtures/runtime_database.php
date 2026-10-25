<?php
/** 2026-10-25 — адаптер SQLite для проверки настоящих служб ядра без внешних подключений. */
final class RuntimeTestConnection
{
    private int $affected = 0;
    private array $commit = array();
    private array $rollback = array();
    public function __construct(public PDO $pdo) {}
    public function inTransaction(): bool { return $this->pdo->inTransaction(); }
    public function afterTransaction(array $commit, array $rollback): void
    {
        $this->commit = array_merge($this->commit, $commit);
        $this->rollback = array_merge($this->rollback, $rollback);
    }
    public function Select($sql): array { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function SelectOne($sql): array { return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: array(); }
    public function DbSafe1($value): string { return str_replace("'", "''", (string)$value); }
    public function DbSafe($value): string { return $this->DbSafe1($value); }
    public function Exec($sql, $ignore = false): bool
    {
        if ($sql === 'START TRANSACTION') return $this->pdo->beginTransaction();
        if ($sql === 'COMMIT' || $sql === 'ROLLBACK') {
            $sql === 'COMMIT' ? $this->pdo->commit() : $this->pdo->rollBack();
            $callbacks = $sql === 'COMMIT' ? $this->commit : $this->rollback;
            $this->commit = $this->rollback = array();
            foreach ($callbacks as $callback) $callback();
            return true;
        }
        $this->affected = $this->pdo->exec($sql);
        return true;
    }
    public function AffectedRows(): int { return $this->affected; }
    public function Insert($table, &$row): int
    {
        $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES ('
            . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        return (int)$this->pdo->lastInsertId();
    }
    public function Update($table, $row, $key = 'ID'): bool
    {
        $id = $row[$key]; unset($row[$key]);
        return $this->pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(fn($field) => $field . '=?', array_keys($row)))
            . ' WHERE ' . $key . '=?')->execute(array_merge(array_values($row), array($id)));
    }
}
