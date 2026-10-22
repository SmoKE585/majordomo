<?php
/**
 * Backward-compatible adapter. New commands belong in lib/core/Console/Commands.
 * Preferred entry point: php majordomo install:<command>
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once dirname(__DIR__) . '/bootstrap.php';

$command = $argv[1] ?? 'help';
$arguments = in_array($command, array('help', '--help', '-h'), true)
    ? array('list', 'install')
    : array_merge(array('install:' . $command), array_slice($argv, 2));
exit((new \MajorDoMo\Core\Console\Application(dirname(__DIR__)))->run($arguments));
