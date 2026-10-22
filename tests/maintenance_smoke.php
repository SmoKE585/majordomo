<?php

/** Изолированные проверки режима обслуживания и HTTP-границы. */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

define('ROOTHTML', '/');
require dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Maintenance\MaintenanceGate;
use MajorDoMo\Core\Maintenance\MaintenanceMode;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    $checks++;
    echo 'PASS ' . $message . PHP_EOL;
}

$root = sys_get_temp_dir() . '/mjd-maintenance-mode-' . bin2hex(random_bytes(6));
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: array() as $item) {
        if ($item === '.' || $item === '..') continue;
        $remove($path . '/' . $item);
    }
    rmdir($path);
};

try {
    mkdir($root . '/cms', 0777, true);
    $mode = new MaintenanceMode($root);
    check(!$mode->isActive(), 'по умолчанию режим выключен');
    check(MaintenanceGate::response($root, 'site', '/') === null, 'без маркера сайт доступен');

    $status = $mode->enable('system_update', 60, 'tester', array('message' => 'Обновляем MajorDoMo'));
    check($status['active'] && $status['reason'] === 'system_update', 'режим включается с причиной');
    check(is_file($root . '/cms/maintenance.json'), 'маркер записан в cms');

    $html = MaintenanceGate::response($root, 'site', '/');
    check($html !== null && $html['status'] === 503, 'сайт получает 503');
    check(str_contains($html['body'], 'Обновляем MajorDoMo'), 'сообщение попадает на страницу');
    check($html['headers']['Retry-After'] === '60', 'Retry-After соответствует TTL');

    check(MaintenanceGate::response($root, 'admin', '/panel/') === null, 'админка не блокируется');
    check(MaintenanceGate::response($root, 'site', '/assets/css/admin.css') === null, 'статика не блокируется');
    check(MaintenanceGate::response($root, 'site', '/install.php') === null, 'мастер установки не блокируется');

    $api = MaintenanceGate::response($root, 'api', '/api/v1/objects');
    check($api !== null && $api['status'] === 503, 'API получает 503');
    $json = json_decode($api['body'], true);
    check(is_array($json) && $json['error'] === 'maintenance' && $json['retry_after'] === 60, 'API получает JSON с retry_after');

    $status = $mode->markFailed('Composer install failed');
    check($status['active'] && $status['failed'] && $status['expires_at'] === 0, 'ошибка удерживает режим без TTL');
    $failed = MaintenanceGate::response($root, 'site', '/');
    check($failed !== null && str_contains($failed['body'], 'Composer install failed'), 'ошибка видна на странице');

    $mode->update(array('expires_at' => time() - 1));
    $expired = $mode->status();
    check(!$expired['active'] && $expired['expired'], 'истёкший маркер не блокирует сайт');
    check(MaintenanceGate::response($root, 'site', '/') === null, 'после истечения сайт доступен');

    $mode->enable('manual', 60);
    check($mode->disable(), 'режим выключается');
    check(!$mode->isActive() && !is_file($root . '/cms/maintenance.json'), 'маркер удалён');

    $mode->enable('system_update', 60);
    mkdir($root . '/cms/deploy', 0777, true);
    file_put_contents($root . '/cms/deploy/last.json', json_encode(array('status' => 'success')));
    check($mode->completeIfDeployed() && !$mode->isActive(), 'успешный деплой снимает режим при старте');

    // Повреждённый маркер не должен валить сайт исключением.
    file_put_contents($root . '/cms/maintenance.json', '{broken');
    $broken = $mode->status();
    check(!$broken['active'] && $broken['error'] !== '', 'повреждённый маркер читается как ошибка, а не падение');

    echo 'Maintenance smoke tests passed: ' . $checks . ' checks.' . PHP_EOL;
} finally {
    $remove($root);
}
