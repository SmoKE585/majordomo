<?php

namespace MajorDoMo\Core;

/** Доступ к БД ядра. 2026-10-25 — транзакционные записи свойств и вложенные точки сохранения. */
final class Database
{
    private array $units = array();
    public function __construct(private object $connection) {}

    private function call(callable $call): mixed
    {
        return method_exists($this->connection, 'withExceptions')
            ? $this->connection->withExceptions($call) : $call();
    }
    public function select(string $sql): array { return $this->call(fn() => $this->connection->Select($sql)); }
    public function one(string $sql): ?array { return $this->call(fn() => $this->connection->SelectOne($sql)) ?: null; }
    public function exec(string $sql, bool $ignoreErrors = false): mixed
    {
        DatabaseGuard::assertAllowed($sql);
        return $this->call(fn() => $this->connection->Exec($sql, $ignoreErrors));
    }
    public function requireExec(string $sql, bool $deferErrors = false): mixed
    {
        // Transaction owners must roll back before handling an error. The legacy
        // error handler can terminate PHP and must not run inside such a batch.
        $result = $this->exec($sql, $deferErrors);
        if (!$result) {
            $error = method_exists($this->connection, 'getLastError') ? $this->connection->getLastError() : array('code' => 0, 'message' => '');
            throw new \RuntimeException('Core database operation failed: ' . $error['message'], (int)$error['code']);
        }
        return $result;
    }
    public function insert(string $table, array &$record): int { return (int)$this->call(function () use ($table, &$record) { return $this->connection->Insert($table, $record); }); }
    public function update(string $table, array $record, string $key = 'ID'): bool { return (bool)$this->call(fn() => $this->connection->Update($table, $record, $key)); }
    public function escape(mixed $value): string { return $this->call(fn() => $this->connection->DbSafe1($value)); }
    public function escapeLike(mixed $value): string { return $this->call(fn() => $this->connection->DbSafe($value)); }
    public function affectedRows(): int { return $this->connection->AffectedRows(); }
    public function tableExists(string $table): bool
    {
        return $this->one("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $this->escape($table) . "'") !== null;
    }

    public function beginUnit(): string
    {
        $name = 'mjd_' . bin2hex(random_bytes(8));
        $owned = !$this->units && !(method_exists($this->connection, 'inTransaction') && $this->connection->inTransaction());
        $this->requireExec($owned ? 'START TRANSACTION' : 'SAVEPOINT ' . $name, true);
        $this->units[] = array('name' => $name, 'owned' => $owned, 'commit' => array(), 'rollback' => array());
        return $name;
    }

    public function inTransaction(): bool
    {
        return (bool)$this->units || (method_exists($this->connection, 'inTransaction') && $this->connection->inTransaction());
    }

    public function afterTransaction(callable $commit, callable $rollback): void
    {
        if ($this->units) $this->afterUnit($commit, $rollback);
        elseif ($this->inTransaction()) $this->connection->afterTransaction(array($commit), array($rollback));
        else $commit();
    }

    public function afterUnit(callable $commit, callable $rollback): void
    {
        $index = count($this->units) - 1;
        if ($index < 0) throw new \LogicException('Транзакция записи не открыта');
        $this->units[$index]['commit'][] = $commit;
        $this->units[$index]['rollback'][] = $rollback;
    }

    public function hasUnit(string $name): bool
    {
        foreach ($this->units as $unit) if ($unit['name'] === $name) return true;
        return false;
    }

    public function commitUnit(string $name): void
    {
        $unit = $this->unit($name);
        $this->requireExec($unit['owned'] ? 'COMMIT' : 'RELEASE SAVEPOINT ' . $name, true);
        array_pop($this->units);
        if ($this->units) {
            $index = count($this->units) - 1;
            foreach (array('commit', 'rollback') as $kind) $this->units[$index][$kind] = array_merge($this->units[$index][$kind], $unit[$kind]);
        } elseif (!$unit['owned']) {
            if (!method_exists($this->connection, 'afterTransaction')) throw new \LogicException('Соединение не поддерживает завершение внешней транзакции');
            $this->connection->afterTransaction($unit['commit'], $unit['rollback']);
        } else foreach ($unit['commit'] as $callback) $callback();
    }

    public function rollbackUnit(string $name): void
    {
        $unit = $this->unit($name);
        try {
            $this->requireExec($unit['owned'] ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT ' . $name, true);
            if (!$unit['owned']) $this->requireExec('RELEASE SAVEPOINT ' . $name, true);
        } finally {
            array_pop($this->units);
            foreach (array_reverse($unit['rollback']) as $callback) $callback();
        }
    }

    private function unit(string $name): array
    {
        $unit = $this->units[count($this->units) - 1] ?? null;
        if (!$unit || $unit['name'] !== $name) throw new \LogicException('Нарушен порядок вложенных транзакций');
        return $unit;
    }
}
