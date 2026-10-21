<?php
// Tests define their own configuration as constants. Publish those values as
// process environment variables so MajorDoMo\Core\Config (used by core classes)
// cannot accidentally pick up the developer's real .env.
foreach (get_defined_constants(true)['user'] ?? array() as $constantName => $constantValue) {
    if (!is_scalar($constantValue) || getenv($constantName) !== false) {
        continue;
    }
    putenv($constantName . '=' . (is_bool($constantValue) ? ($constantValue ? '1' : '0') : (string)$constantValue));
}

if (!class_exists('Redis')) require __DIR__ . '/RedisTestClient.php';
#[AllowDynamicProperties]
class module { public function checkInstalled() {} }
class custom_error { public function __construct($message) { throw new RuntimeException($message); } }
function gr($key, $filter = '') { return $_REQUEST[$key] ?? ''; }
function dprint($value, $flag = false) {}
function DebMes($message, $channel = '') { $GLOBALS['test_logs'][] = $message; }
function registerError($code, $message) { if ($code === 'sql') $GLOBALS['test_sql_errors'][] = $message; }
function verbose_log($message) {}
function setEvalCode($code = '') {}
function startMeasure($name) {}
function endMeasure($name, $save = 0) {}
function IsWindowsOS() { return DIRECTORY_SEPARATOR === '\\'; }
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'lib/core.class.php';
// Boot Config against an empty fixture root: tests must not inherit the
// developer's .env (for example DISABLE_WEBSOCKETS=1 would silence the queue).
\MajorDoMo\Core\Config::boot(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mjd-test-config-' . bin2hex(random_bytes(4)));
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'lib/mysqli.class.php';
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'lib/sqlFunctions.class.php';
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'lib/redis_helper.class.php';
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'lib/caching.class.php';
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'lib/objects.class.php';
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'lib/events.class.php';
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'modules/objects/objects.class.php';
