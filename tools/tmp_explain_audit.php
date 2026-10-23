<?php
// Временный диагностический скрипт: план запроса свойства аудита. Удаляется после проверки.
$env = array();
foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$port = $env['DB_PORT'] ?? '';
$dsn = 'mysql:host=' . ($env['DB_HOST'] ?? 'localhost') . ($port !== '' ? ';port=' . $port : '') . ';dbname=' . ($env['DB_NAME'] ?? '') . ';charset=utf8';
try {
    $pdo = new PDO($dsn, $env['DB_USER'] ?? '', $env['DB_PASSWORD'] ?? '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
} catch (Throwable $e) {
    echo 'DB unavailable: ' . $e->getMessage() . PHP_EOL;
    exit(0);
}
$tables = $pdo->query("SHOW TABLES LIKE 'property_audit_events'")->fetchAll();
if (!$tables) { echo "property_audit_events: нет таблицы в локальной БД\n"; exit(0); }
echo 'rows: ' . $pdo->query('SELECT COUNT(*) FROM property_audit_events')->fetchColumn() . PHP_EOL;
echo 'runs: ' . $pdo->query('SELECT COUNT(*) FROM property_audit_runs')->fetchColumn() . PHP_EOL;
foreach ($pdo->query('SHOW INDEX FROM property_audit_events') as $row) {
    echo 'index: ' . $row['Key_name'] . ' (' . $row['Column_name'] . ')' . PHP_EOL;
}
$trace = (string)$pdo->query('SELECT TRACE_ID FROM property_audit_events ORDER BY ID DESC LIMIT 1')->fetchColumn();
if ($trace === '') { echo "нет событий для EXPLAIN\n"; exit(0); }
echo "--- EXPLAIN by RUN_ID ---\n";
foreach ($pdo->query('EXPLAIN SELECT ERROR FROM property_audit_events WHERE RUN_ID=(SELECT ID FROM property_audit_runs ORDER BY ID DESC LIMIT 1) AND STATUS=\'error\' ORDER BY ID LIMIT 1') as $row) {
    echo json_encode(array_intersect_key($row, array_flip(array('type', 'key', 'rows', 'Extra', 'select_type', 'table'))), JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
echo "--- EXPLAIN by TRACE_ID ---\n";
$stmt = $pdo->prepare('EXPLAIN SELECT ERROR FROM property_audit_events WHERE TRACE_ID=? AND STATUS=\'error\' ORDER BY ID LIMIT 1');
$stmt->execute(array($trace));
foreach ($stmt as $row) {
    echo json_encode(array_intersect_key($row, array_flip(array('type', 'key', 'rows', 'Extra', 'select_type', 'table'))), JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
