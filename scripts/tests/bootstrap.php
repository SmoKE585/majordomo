<?php
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
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'lib/mysqli.class.php';
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'lib/sqlFunctions.class.php';
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'lib/redis_helper.class.php';
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'lib/caching.class.php';
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'lib/objects.class.php';
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'lib/events.class.php';
require (defined('MJD_TEST_SOURCE_ROOT') ? MJD_TEST_SOURCE_ROOT : ROOT) . 'modules/objects/objects.class.php';
