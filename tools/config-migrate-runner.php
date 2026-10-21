<?php
/**
 * Isolated legacy config reader used by `php tools/config.php migrate`.
 *
 * Includes the old config.php in a separate PHP process and returns the
 * constants, timezone and legacy arrays it declared. Keeping this in a child
 * process protects the migration CLI from duplicate define() calls and from
 * side effects in the legacy file.
 */

$path = $argv[1] ?? '';
if (!is_file($path)) {
    fwrite(STDERR, 'Legacy config not found: ' . $path . PHP_EOL);
    exit(2);
}

$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

$before = get_defined_constants(true)['user'] ?? array();
$timezoneBefore = date_default_timezone_get();
$restartThreads = array();
$additionalGitUrls = array();

include $path;

$after = get_defined_constants(true)['user'] ?? array();
$constants = array_diff_key($after, $before);
$timezoneAfter = date_default_timezone_get();

$result = array(
    'constants' => $constants,
    'timezone' => $timezoneAfter !== $timezoneBefore ? $timezoneAfter : null,
    'restart_threads' => isset($restart_threads) && is_array($restart_threads) ? $restart_threads : array(),
    'aditional_git_urls' => isset($aditional_git_urls) && is_array($aditional_git_urls) ? $aditional_git_urls : array(),
);

$json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false) {
    fwrite(STDERR, 'Unable to encode legacy config: ' . json_last_error_msg() . PHP_EOL);
    exit(2);
}
echo $json;
