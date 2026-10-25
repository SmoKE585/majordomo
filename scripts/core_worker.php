<?php
// 2026-10-25 — предварительная загрузка ядра без повторного использования пользовательского процесса.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
require_once './config.php';
require_once './lib/loader.php';
$kind = $argv[1] ?? '';
$id = $argv[2] ?? '';
$token = $argv[3] ?? '';
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
}
require_once './load_settings.php';
// Discard user output in chunks; keep only the machine-readable completion record.
ob_start(static fn(string $output): string => '', 8192);
$result = $kind === 'job' ? \MajorDoMo\Core\Core::scheduler()->execute($id, $token) : \MajorDoMo\Core\Core::worker()->execute($id, $token);
ob_end_clean();
echo 'MJD_WORKER_RESULT ' . json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit(!empty($result['success']) ? 0 : 1);
