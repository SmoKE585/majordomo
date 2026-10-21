<?php

/** Real file logging and PHP handlers; temporary files and SQLite only. */
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('USE_REDIS=false');
date_default_timezone_set('UTC');
$pdo = new PDO('sqlite::memory:');
if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
    throw new RuntimeException('Test isolation was not established');
}

$sourceRoot = dirname(__DIR__) . '/';
$scenario = $argv[1] ?? '';
$fixture = $argv[2] ?? sys_get_temp_dir() . '/mjd-logging-' . bin2hex(random_bytes(6));

if ($scenario === 'config') {
    putenv('DEBUG_MODE=invalid');
    putenv('TIMEZONE=UTC');
    putenv('LOG_DIRECTORY=' . $fixture . '/config-logs');
    require $sourceRoot . 'lib/core.class.php';
    \MajorDoMo\Core\Config::boot($fixture . '/config-root');
    exit;
}

define('ROOT', $sourceRoot);
define('LOG_DIRECTORY', $fixture . '/logs');
define('LOG_MAX_SIZE', 1);
define('LOG_PHP_WARNINGS', false); // The unified log must not depend on this legacy flag.
define('SETTINGS_SYSTEM_DISABLE_DEBMES', $scenario === 'disabled' ? 1 : 0);
if ($scenario === 'custom-path') {
    define('SETTINGS_SYSTEM_DEBMES_PATH', $fixture . '/custom-logs');
}
$_SERVER['REQUEST_URI'] = '/fixture/logging';
require $sourceRoot . 'lib/perfmonitor.class.php';
require $sourceRoot . 'lib/general.class.php';
require $sourceRoot . 'lib/common.class.php';
// Avoid loading a cycle cache or connecting to any backend.
function saveCycleToCache($key, $value) { $GLOBALS['cycleErrors'][$key] = $value; }
require $sourceRoot . 'lib/errors.class.php';
error_reporting(E_ALL);

if ($scenario === 'exception') {
    throw new RuntimeException('fixture uncaught exception');
}
if ($scenario === 'fatal') {
    restore_exception_handler();
    fixtureMissingFunction();
}
if ($scenario === 'disabled' || $scenario === 'custom-path') {
    DebMes('fixture ' . $scenario, 'module', 'error');
    exit;
}
if ($scenario === 'parallel') {
    for ($i = 0; $i < 25; $i++) {
        DebMes('fixture parallel ' . getmypid() . ':' . $i . "\nfixture detail " . getmypid() . ':' . $i, 'parallel_fixture', 'error');
    }
    exit;
}

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
}
function runScenario(string $scenario, string $fixture): array
{
    $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=0', __FILE__, $scenario, $fixture),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated PHP fixture');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return array(proc_close($process), $output, $errors);
}

mkdir($fixture);
mkdir(LOG_DIRECTORY);
$directory = LOG_DIRECTORY . '/' . date('Y-m-d');
$errorsFile = $directory . '/errors.log';
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (scandir($path) as $item) {
            if ($item !== '.' && $item !== '..') $remove($path . '/' . $item);
        }
        rmdir($path);
    } else {
        unlink($path);
    }
};

$testExitCode = 0;
try {
    DebMes('fixture user debug');
    // Words in user messages must not silently change their destination.
    DebMes('fixture user text: error warning exception');
    foreach (array('threads', 'reinstall', 'core_runtime', 'maintenance', 'nested/service') as $channel) {
        DebMes('fixture service ' . $channel, $channel);
        check(str_contains(file_get_contents($directory . '/' . $channel . '.log'), 'fixture service ' . $channel), $channel . ' keeps its ordinary log');
    }
    check(!file_exists($errorsFile), 'ordinary diagnostics do not create errors.log');
    check(str_contains(file_get_contents($directory . '/debug.log'), 'fixture user text: error warning exception'), 'debug keeps original destination');

    foreach (array('php_warnings', 'php_warnings_shutdown', 'php_errors', 'php_errors_shutdown', 'php_exceptions', 'mqtt_error', 'geturl_error', 'api_error', 'errors') as $channel) {
        DebMes('fixture legacy ' . $channel, $channel);
        check(str_contains(file_get_contents($errorsFile), '[' . $channel . '] fixture legacy ' . $channel), $channel . ' records its source in errors.log');
        if ($channel !== 'errors') check(!file_exists($directory . '/' . $channel . '.log'), $channel . ' no longer creates a separate file');
    }
    foreach (array('warning', 'error', 'critical', 'danger', 'alert', 'emergency', 'warn', 'exception') as $severity) {
        DebMes('fixture severity ' . $severity, 'test_module', $severity);
        check(str_contains(file_get_contents($errorsFile), '[' . strtoupper($severity) . '] [test_module] fixture severity ' . $severity), 'explicit ' . $severity . ' reaches errors.log');
    }
    DebMes(array('message' => 'fixture structured error'), 'test_module', 'error');
    check(str_contains(file_get_contents($errorsFile), 'fixture structured error'), 'structured error messages are preserved');
    check(!file_exists($directory . '/test_module.log'), 'explicit problems do not leak into the source channel');
    registerError('sql', 'fixture registered SQL failure');
    check(str_contains(file_get_contents($errorsFile), 'fixture registered SQL failure'), 'registerError shares errors.log without a database connection');

    trigger_error('fixture PHP user warning', E_USER_WARNING);
    trigger_error('fixture PHP user notice', E_USER_NOTICE);
    trigger_error('fixture PHP user deprecation', E_USER_DEPRECATED);
    fopen($fixture . '/fixture-missing-warning', 'r');
    $content = file_get_contents($errorsFile);
    foreach (array('fixture PHP user warning', 'fixture PHP user notice', 'fixture PHP user deprecation', 'fixture-missing-warning') as $message) {
        check(substr_count($content, $message) === 1, 'PHP diagnostic appears once: ' . $message);
    }
    @fopen($fixture . '/fixture-suppressed-warning', 'r');
    phpShutDownFunction();
    check(file_get_contents($errorsFile) === $content, 'shutdown ignores suppressed/stale warnings');
    $mask = error_reporting(E_ERROR);
    trigger_error('fixture masked warning', E_USER_WARNING);
    error_reporting($mask);
    phpShutDownFunction();
    check(file_get_contents($errorsFile) === $content, 'error_reporting remains respected');
    error_clear_last();

    [$exitCode, $output, $stderr] = runScenario('exception', $fixture);
    check($exitCode === 0 && $output === '' && $stderr === '', 'uncaught exception uses the installed handler');
    $content = file_get_contents($errorsFile);
    check(substr_count($content, 'fixture uncaught exception') === 1 && str_contains($content, '[php_exceptions]'), 'uncaught exception is recorded once');
    [$exitCode] = runScenario('fatal', $fixture);
    $content = file_get_contents($errorsFile);
    check($exitCode !== 0 && str_contains($content, '[php_errors_shutdown]') && str_contains($content, 'fixtureMissingFunction'), 'fatal shutdown diagnostics reach errors.log');

    [$exitCode, $output, $stderr] = runScenario('disabled', $fixture);
    check($exitCode === 0 && $stderr === '' && file_get_contents($errorsFile) === $content, 'disable-DebMes setting remains respected');
    [$exitCode, $output, $stderr] = runScenario('custom-path', $fixture);
    $customFile = $fixture . '/custom-logs/' . date('Y-m-d') . '/errors.log';
    check($exitCode === 0 && $stderr === '' && is_file($customFile), 'custom DebMes path remains respected');

    [$exitCode, $output, $stderr] = runScenario('config', $fixture);
    $configFile = $fixture . '/config-logs/' . date('Y-m-d') . '/errors.log';
    check($exitCode === 0 && $stderr === '' && is_file($configFile), 'early configuration diagnostics use the resolved log directory');
    check(str_contains(file_get_contents($configFile), '[WARNING] [config] Invalid boolean value for DEBUG_MODE'), 'early config warning uses unified format before DebMes loads');

    $writers = array();
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open(array(PHP_BINARY, __FILE__, 'parallel', $fixture),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start parallel log writer');
        fclose($pipes[0]);
        $writers[] = array($process, $pipes);
    }
    foreach ($writers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 0 && $output === '' && $stderr === '', 'parallel writer completes without warnings');
    }
    $content = file_get_contents($errorsFile);
    check(preg_match_all('~^\d{2}:\d{2}:\d{2} 0\.\d+ \[ERROR\] \[parallel_fixture\] fixture parallel (\d+:\d+)\nfixture detail \1$~m', $content) === 50,
        'parallel writers preserve complete multiline entries');

    $limitedFile = $directory . '/size_limit.log';
    file_put_contents($limitedFile, str_repeat('x', 1024 * 1024 + 1));
    DebMes('fixture beyond limit', 'size_limit');
    clearstatcache(true, $limitedFile);
    check(filesize($limitedFile) === 1024 * 1024 + 1, 'existing log size limit remains respected');
    check(!str_contains(file_get_contents($directory . '/debug.log'), 'fixture registered SQL failure'), 'system errors do not enter debug.log');

    echo 'Logging smoke: ' . $checks . ' passed.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $testExitCode = 1;
} finally {
    error_clear_last();
    restore_error_handler();
    restore_exception_handler();
    $remove($fixture);
}
exit($testExitCode);
