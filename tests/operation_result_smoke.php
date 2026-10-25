<?php

/** 2026-10-23 — реальный воркер различает отказ и пустые результаты; внешние службы подменены. */
namespace MajorDoMo\Core {
    final class Core
    {
        public static $result;
        public static function objects(): object { return new class { public function call($target, $params) { return Core::$result; } }; }
    }
    final class OperationsQueue
    {
        public string $operation = 'method';
        public function claimedRuntime(...$args): array
        {
            return array('DATANAME' => $this->operation, 'DATAVALUE' => json_encode(array('target' => 'Fixture', 'path' => '/fixture', 'params' => array())));
        }
    }
    final class ProcessRunner {}
}

namespace {
    putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('USE_REDIS=false');
    error_reporting(E_ALL);
    set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
    foreach (array('Database', 'RedisConnection', 'PropertyChain', 'ErrorDetails', 'OperationWorker') as $class) require dirname(__DIR__) . '/lib/core/' . $class . '.php';
    function runScript($id, $params) { return MajorDoMo\Core\Core::$result; }
    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Test isolation failed');
    $pdo->exec("CREATE TABLE scripts (ID INTEGER PRIMARY KEY, TITLE TEXT); INSERT INTO scripts VALUES (1,'Fixture')");
    $connection = new class($pdo) {
        public function __construct(private PDO $pdo) {}
        public function SelectOne($sql) { return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: array(); }
        public function DbSafe1($value) { return str_replace("'", "''", (string)$value); }
    };
    $queue = new MajorDoMo\Core\OperationsQueue();
    $worker = new MajorDoMo\Core\OperationWorker(new MajorDoMo\Core\Database($connection), new MajorDoMo\Core\RedisConnection(), $queue, new MajorDoMo\Core\ProcessRunner());
    $checks = 0;
    foreach (array('method', 'script') as $operation) {
        $queue->operation = $operation;
        foreach (array(false, null, 0, '', true, array('success' => false, 'error' => 'fixture'), array('success' => true)) as $value) {
            MajorDoMo\Core\Core::$result = $value;
            $result = $worker->execute(1, 'fixture-token');
            $expected = $value !== false && !(is_array($value) && $value['success'] === false);
            if ($result['success'] !== $expected) throw new RuntimeException('Incorrect worker result for ' . $operation . ': ' . var_export($value, true));
            $checks++;
            if ($value === false && !str_contains($result['error'], $operation)) throw new RuntimeException('Failed handler is missing from error');
        }
    }
    echo 'Operation result smoke: ' . $checks . " passed; SQLite :memory:, no external connections.\n";
}
