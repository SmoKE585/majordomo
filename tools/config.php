<?php
/**
 * Backward-compatible adapter. New commands belong in lib/core/Console/Commands.
 * Preferred entry point: php majordomo config:<command>
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once dirname(__DIR__) . '/bootstrap.php';

$command = $argv[1] ?? 'help';
$arguments = in_array($command, array('help', '--help', '-h'), true)
    ? array('list', 'config')
    : array_merge(array('config:' . $command), array_slice($argv, 2));
exit((new \MajorDoMo\Core\Console\Application(dirname(__DIR__)))->run($arguments));
