<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
require_once './config.php';
require_once './lib/loader.php';
require_once './load_settings.php';
$kind = $argv[1] ?? '';
$id = $argv[2] ?? '';
$token = $argv[3] ?? '';
// Keep user output separate from the machine-readable completion record.
ob_start();
$result = $kind === 'job' ? \MajorDoMo\Core\Core::scheduler()->execute($id, $token) : \MajorDoMo\Core\Core::worker()->execute($id, $token);
ob_end_clean();
echo 'MJD_WORKER_RESULT ' . json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit(!empty($result['success']) ? 0 : 1);
