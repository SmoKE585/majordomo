<?php
// 2026-10-25 — отдельная группа процессов и собственный дедлайн задания на случай гибели родителя.
// 2026-10-25 — преждевременный выход передаёт родителю причину для окончательного отчёта.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (function_exists('posix_setsid')) posix_setsid();
chdir(dirname(__DIR__));
require_once './config.php';
require_once './lib/loader.php';
$kind = $argv[1] ?? '';
$id = $argv[2] ?? '';
$token = $argv[3] ?? '';
$timeout = max(1, (int)($argv[4] ?? 300));
if ($kind === '--standby') {
    // EOF завершает осиротевший процесс, если родитель был остановлен.
    $line = fgets(STDIN, 65536);
    if ($line === false) exit(0);
    $request = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($request) || ($request['kind'] ?? '') !== 'operation'
        || !is_string($request['id'] ?? null) || !is_string($request['token'] ?? null)) {
        throw new InvalidArgumentException('Invalid prepared worker request');
    }
    $kind = $request['kind'];
    $id = $request['id'];
    $token = $request['token'];
    $timeout = max(1, (int)($request['timeout'] ?? 300));
}
if (function_exists('pcntl_async_signals') && function_exists('pcntl_alarm')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function (): void { exit(124); });
    pcntl_alarm($timeout);
}
require_once './load_settings.php';
// Discard user output in chunks; keep only the machine-readable completion record.
$workerFinished = false;
$workerBufferLevel = ob_get_level();
register_shutdown_function(static function () use (&$workerFinished, $workerBufferLevel): void {
    if ($workerFinished) return;
    while (ob_get_level() > $workerBufferLevel) ob_end_clean();
    $last = $GLOBALS['mjdLastFailure'] ?? '';
    $data = \MajorDoMo\Core\ErrorDetails::decode($last);
    $result = array('success' => false, 'error' => $data['message'] ?? 'Исполнитель завершился до получения результата; код exit сам по себе не объясняет причину.',
        'diagnostic' => $data);
    echo 'MJD_WORKER_RESULT ' . json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
});
ob_start(static fn(string $output): string => '', 8192);
$result = $kind === 'job' ? \MajorDoMo\Core\Core::scheduler()->execute($id, $token) : \MajorDoMo\Core\Core::worker()->execute($id, $token);
ob_end_clean();
$workerFinished = true;
echo 'MJD_WORKER_RESULT ' . json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit(!empty($result['success']) ? 0 : 1);
