<?php

/** 2026-10-22 — маршрутизатор разработки не запускает системные PHP-файлы. */
$root = dirname(__DIR__);
$source = file_get_contents($root . '/dev_server.php');
// Загружаются только определения функций: CLI-стартер и загрузка БД не выполняются.
eval(substr($source, strpos($source, 'function devServerRoute(')));
$checks = 0;
foreach (array('/scripts', '/scripts/cycle_main.php', '/scripts/core_worker.php/extra', '/%73cripts/cycle_main.php',
    '/objects/', '/objects/index.php', '/3rdparty/php-mailer/PHPMailer.php', '/vendor/autoload.php', '/packages/phpmqtt/src/phpMQTT.php') as $path) {
    $_SERVER['REQUEST_URI'] = $path;
    ob_start();
    $route = devServerRoute($root);
    $output = ob_get_clean();
    if ($route !== true || http_response_code() !== 403 || $output !== 'Forbidden') throw new RuntimeException('Private route exposed: ' . $path);
    $checks++;
}
echo 'Private paths smoke: ' . $checks . " passed\n";
