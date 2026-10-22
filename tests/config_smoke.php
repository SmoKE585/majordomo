<?php
/**
 * Configuration layer smoke test (no database required).
 *
 * Run: php tests/config_smoke.php
 */

require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\ConfigCache;
use MajorDoMo\Core\ConfigSchema;
use MajorDoMo\Core\Env;

$failures = 0;
function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo 'FAIL: ' . $message . PHP_EOL;
        return;
    }
    echo 'ok: ' . $message . PHP_EOL;
}

// --- Env parser ------------------------------------------------------------
$parsed = Env::parse("\xEF\xBB\xBF# comment\r\nDB_HOST=localhost\r\nEMPTY=\r\n"
    . "PASSWORD=\"p#ss word\" # trailing comment\r\n"
    . "LITERAL='a\\nb'\r\n"
    . "WINDOWS=C:\\Program Files\\MySQL\\bin\r\n"
    . "INLINE=value # comment\r\n"
    . "HASH=p#ss\r\n"
    . "export EXPORTED=yes\r\n");
check($parsed['DB_HOST'] === 'localhost', 'parses plain values');
check($parsed['EMPTY'] === '', 'parses empty values');
check($parsed['PASSWORD'] === 'p#ss word', 'keeps # and spaces inside double quotes');
check($parsed['LITERAL'] === 'a\\nb', 'single quotes are literal');
check($parsed['WINDOWS'] === 'C:\\Program Files\\MySQL\\bin', 'keeps Windows backslashes');
check($parsed['INLINE'] === 'value', 'strips whitespace-prefixed inline comments');
check($parsed['HASH'] === 'p#ss', 'keeps # without preceding whitespace');
check($parsed['EXPORTED'] === 'yes', 'supports export prefix');

// --- Config boot -----------------------------------------------------------
$fixture = sys_get_temp_dir() . '/mjd-config-' . bin2hex(random_bytes(6));
mkdir($fixture, 0777, true);
mkdir($fixture . '/cms/cached', 0777, true);
mkdir($fixture . '/cms/debmes', 0777, true);
file_put_contents($fixture . '/.env', implode("\n", array(
    'DB_HOST=db.local',
    'DB_NAME=fixture_db',
    'DB_USER=fixture_user',
    'DB_PASSWORD="p#ss word"',
    'DEBUG_MODE=0',
    'USE_REDIS=false',
    'LOG_MAX_SIZE=9000',
    'TIMEZONE=Europe/Moscow',
    'RESTART_THREADS=cycle_main.php, cycle_states.php',
    'ADITIONAL_GIT_URLS={"https://example.com/alpha.tar.gz":"alpha"}',
    '',
)));

define('UPDATES_REPOSITORY_NAME', 'from_legacy_constant');

Config::boot($fixture);

check(Config::string('DB_HOST') === 'db.local', 'reads values from .env');
check(Config::string('DB_PASSWORD') === 'p#ss word', 'keeps quoted password intact');
check(Config::bool('DEBUG_MODE') === false, 'casts DEBUG_MODE=0 to false');
check(Config::int('LOG_MAX_SIZE') === 9000, 'casts integer values');
check(Config::has('UPDATES_REPOSITORY_NAME') === true, 'keeps a value from a legacy constant');
check(Config::string('UPDATES_REPOSITORY_NAME') === 'from_legacy_constant', 'legacy constant wins over the schema default');
check(Config::has('USE_REDIS'), 'optional key is present when set');
check(Config::get('USE_REDIS') === false, 'host_or_false turns "false" into boolean false');
check(defined('USE_REDIS') && USE_REDIS === false, 'defines USE_REDIS as false');
check(!Config::has('HOME_NETWORK') && !defined('HOME_NETWORK'), 'unset optional key stays absent');
$redis = new \MajorDoMo\Core\RedisConnection();
check($redis->get() === null, 'RedisConnection does not try to connect to host "false"');
check(defined('DEBUG_MODE') && DEBUG_MODE === false, 'defines a legacy constant with the typed value');
check(DOC_ROOT === $fixture, 'computes DOC_ROOT from the project path');
check(ROOT === $fixture . '/', 'computes ROOT with a trailing slash');
check(LOG_MAX_SIZE === 9000, 'defines a typed legacy constant');
check(Config::arrayValue('RESTART_THREADS') === array('cycle_main.php', 'cycle_states.php'), 'parses comma-separated lists');
check(Config::arrayValue('ADITIONAL_GIT_URLS') === array('https://example.com/alpha.tar.gz' => 'alpha'), 'parses JSON objects');

$cachePath = ConfigCache::path($fixture);
check(is_file($cachePath), 'writes the compiled cache');
$fingerprint = ConfigCache::fingerprint($fixture, ConfigSchema::all());
check(ConfigCache::read($fixture, $fingerprint) !== null, 'reads a fresh cache');

// Touch .env so the fingerprint changes; the old cache must be rejected.
file_put_contents($fixture . '/.env', "\n# changed\n", FILE_APPEND);
clearstatcache(true, $fixture . '/.env');
$newFingerprint = ConfigCache::fingerprint($fixture, ConfigSchema::all());
check($newFingerprint !== $fingerprint, 'fingerprint changes when .env changes');
check(ConfigCache::read($fixture, $newFingerprint) === null, 'stale cache is rejected');

// --- Cleanup ---------------------------------------------------------------
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: array() as $item) {
            if ($item === '.' || $item === '..') continue;
            $remove($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};
$remove($fixture);

echo PHP_EOL . ($failures === 0 ? 'Config smoke tests passed' : $failures . ' config smoke test(s) failed') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
