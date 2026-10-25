<?php
/** 2026-10-25 — CLI очереди: JSON, ограничения аргументов и маскирование причин отказа без рабочих сервисов. */
putenv('APP_ENV=testing'); putenv('USE_REDIS=false');
require dirname(__DIR__) . '/lib/core.class.php';
require __DIR__ . '/fixtures/runtime_queue.php';
require __DIR__ . '/fixtures/runtime_database.php';
$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
createRuntimeQueueFixture($pdo);
$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
$queue = new MajorDoMo\Core\RuntimeQueue(new MajorDoMo\Core\Database(new RuntimeTestConnection($pdo)));
$queue->enqueue('method', '{"target":"Lamp.on","params":{"password":"payload-secret"}}', time() + 60);
$pdo->exec("UPDATE core_runtime_queue SET STATUS='failed',LAST_ERROR='password=error-secret'");
$application = new MajorDoMo\Core\Console\Application(dirname(__DIR__));
$property = new ReflectionProperty($application, 'commands');
$commands = $property->getValue($application);
$commands['queue:status'] = new MajorDoMo\Core\Console\Commands\QueueStatusCommand(fn() => $queue);
$property->setValue($application, $commands);
$checks = 0;
foreach (array(array('queue:status','--failed','--json'), array('queue:status','--limit=0','--json'), array('queue:status','--help','--json')) as $index => $arguments) {
    $out = fopen('php://memory', 'w+'); $err = fopen('php://memory', 'w+');
    $code = $application->run($arguments, $out, $err);
    rewind($out); $text = stream_get_contents($out);
    $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    if ($code !== ($index === 1 ? 2 : 0) || !is_array($data)) throw new RuntimeException('Неверный код CLI очереди');
    if ($index === 0 && ((int)$data['data']['status']['failed'] !== 1 || str_contains($text, 'error-secret') || str_contains($text, 'payload-secret'))) throw new RuntimeException('Неверная диагностика или утечка параметров');
    fclose($out); fclose($err); $checks++;
}
echo 'Queue status smoke: ' . $checks . " passed; SQLite, no application boot.\n";
