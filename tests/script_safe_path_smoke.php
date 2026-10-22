<?php

/**
 * runScriptSafe builds an API path that survives Api\Request decoding.
 *
 * Регрессия: urlencode() кодировал пробел как "+", а Api\Request разбирает
 * сегменты через rawurldecode. Сценарий «Мой сценарий» превращался в
 * «Мой+сценарий» и не находился — фоновая операция падала после 3 попыток.
 *
 * Run: php tests/script_safe_path_smoke.php
 */

function startMeasure($name) {}
function endMeasure($name, $flag = 0) {}
function DebMes($message, $folder = '', $level = '') {}

function callAPI($url, $method = 'GET', $params = 0, $wait = false)
{
    $GLOBALS['api_calls'][] = array($url, $method, $wait);
    return true;
}

require dirname(__DIR__) . '/lib/core/Helpers/Scripts.php';

unset($_SERVER['REQUEST_URI']);
$_GET = array();

$checks = 0;
function check($condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

$api_calls = array();
runScriptSafe('Мой сценарий');
check(count($api_calls) === 1, 'Script is queued through the API');
check($api_calls[0][0] === '/api/script/' . rawurlencode('Мой сценарий'), 'Script title is encoded with rawurlencode');
check($api_calls[0][1] === 'GET' && $api_calls[0][2] === false, 'Script is queued asynchronously');

$api_calls = array();
runScriptSafe('Test script');
check($api_calls[0][0] === '/api/script/Test%20script', 'Space becomes %20, not +');

$api_calls = array();
runScriptSafe(7);
check($api_calls[0][0] === '/api/script/7', 'Numeric script ID stays numeric');

echo 'Script queue path smoke tests passed: ' . $checks . " checks.\n";
