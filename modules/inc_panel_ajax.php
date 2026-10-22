<?php

/**
 * AJAX панели администрирования.
 *
 * 2026-10-22 — обработчики переехали в lib/core/Api/{Panel,PanelSearch,
 * PanelConsole}. Здесь остаются заголовки и вывод старого формата, чтобы
 * существующие страницы и модули продолжали работать.
 */

global $op;

if (!headers_sent()) {
    header("HTTP/1.0 200 OK\n");
    header('Content-Type: text/html; charset=utf-8');
}

$panel = new \MajorDoMo\Core\Api\Panel(\MajorDoMo\Core\Core::database());

if ($op === 'websocket') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    try {
        echo json_encode($panel->websocketTicket(), JSON_THROW_ON_ERROR);
    } catch (\MajorDoMo\Core\Api\ApiException $error) {
        http_response_code($error->status);
        echo json_encode(array('error' => $error->errorCode));
    } catch (\Throwable $error) {
        error_log('WebSocket ticket unavailable: ' . get_class($error));
        http_response_code(503);
        echo json_encode(array('error' => 'authentication_unavailable'));
    }
    exit;
}

if ($op === 'dashboard') {
    $payload = $panel->dashboard((string)gr('fresh') === '1');
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($op === 'dismiss_notification') {
    try {
        $panel->dismiss(array('id' => (int)gr('id', 'int')));
        echo 'OK';
    } catch (\Throwable $error) {
        echo 'ERROR';
    }
    exit;
}

if ($op === 'dismiss_module_notifications') {
    $module_name = gr('module_name', 'trim');
    if ($module_name) {
        try {
            $panel->dismiss(array('module' => $module_name));
            echo 'OK';
        } catch (\Throwable $error) {
            echo 'ERROR';
        }
    } else {
        echo 'ERROR';
    }
    exit;
}

if ($op === 'console') {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL);
    echo (new \MajorDoMo\Core\Api\PanelConsole())->run((string)gr('command'));
    exit;
}

if ($op === 'filter') {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $title = trim((string)gr('title', 'trim'));
    $title = preg_replace('/\s+/u', ' ', $title);
    $limit = (int)gr('limit', 'int');
    $result = (new \MajorDoMo\Core\Api\PanelSearch())->search($title, $limit);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

exit;
