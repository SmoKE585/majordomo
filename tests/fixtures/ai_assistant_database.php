<?php

declare(strict_types=1);

/**
 * Адаптер тестовой SQLite к штатному Database без загрузки приложения.
 *
 * Изменения:
 * 2026-10-23 — общий адаптер изолированных тестов модуля ИИ-помощника.
 */
final class AiAssistantDatabaseFixture
{
    public function __construct(public PDO $pdo) {}
    public function Select(string $sql): array { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function SelectOne(string $sql): array|false { return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); }
    public function Exec(string $sql, bool $ignoreErrors = false): bool { $this->pdo->exec($sql); return true; }
    public function DbSafe1(mixed $value): string { return substr($this->pdo->quote((string)$value), 1, -1); }
    public function DbSafe(mixed $value): string { return $this->DbSafe1($value); }
    public function AffectedRows(): int { return (int)$this->pdo->query('SELECT changes()')->fetchColumn(); }
    public function Insert(string $table, array &$record): int
    {
        $this->checkRecord($table, $record);
        $fields = array_keys($record);
        $statement = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES ('
            . implode(',', array_fill(0, count($fields), '?')) . ')');
        // Воспроизводим Insert штатного mysqli: переданный null становится ''.
        $statement->execute(array_map(static fn($value) => $value === null ? '' : $value, array_values($record)));
        return (int)$this->pdo->lastInsertId();
    }
    public function Update(string $table, array $record, string $key = 'ID'): bool
    {
        $this->checkRecord($table, $record);
        if ($key !== 'ID' || !isset($record['ID'])) throw new RuntimeException('В тестовой записи требуется ID.');
        $id = $record['ID'];
        unset($record['ID']);
        $assignments = array_map(static fn(string $field): string => $field . '=?', array_keys($record));
        $statement = $this->pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', $assignments) . ' WHERE ID=?');
        return $statement->execute(array_merge(array_values($record), array($id)));
    }
    private function checkRecord(string $table, array $record): void
    {
        if (!in_array($table, array('project_modules', 'classes', 'objects', 'properties', 'scripts'), true)
            && !preg_match('/^ai_assistant_[a-z_]+$/D', $table)) throw new RuntimeException('Неожиданная таблица фикстуры ИИ-помощника.');
        foreach (array_keys($record) as $field) {
            if (!preg_match('/^[A-Z_]+$/D', $field)) throw new RuntimeException('Некорректное поле тестовой записи.');
        }
    }
}
