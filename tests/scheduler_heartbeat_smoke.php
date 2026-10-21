<?php

// Real scheduler and cycle entry point with fake services and a virtual clock.
// The working application bootstrap and external services are never loaded.
if (PHP_SAPI !== 'cli') exit(1);
$project = str_replace('\\', '/', dirname(__DIR__));
$root = sys_get_temp_dir() . '/mjd-scheduler-heartbeat-' . bin2hex(random_bytes(8));
foreach (array('/scripts', '/lib', '/modules/control_modules', '/modules/scripts') as $directory) mkdir($root . $directory, 0700, true);
$source = file_get_contents($project . '/scripts/cycle_scheduler.php');
file_put_contents($root . '/scripts/cycle_scheduler.php', preg_replace('/\btime\(\)/', 'fixtureNow()', $source));
file_put_contents($root . '/config.php', '<?php define("DIR_MODULES", __DIR__ . "/modules/"); $_GET["onetime"] = 1;');
file_put_contents($root . '/load_settings.php', '<?php');
file_put_contents($root . '/lib/threads.php', '<?php');
file_put_contents($root . '/modules/control_modules/control_modules.class.php', '<?php class control_modules {}');
file_put_contents($root . '/modules/scripts/scripts.class.php', '<?php class scripts { public function checkScheduledScripts() {} }');
$loader = <<<'PHP'
<?php
namespace MajorDoMo\Core {
    function time() { return \fixtureNow(); }
    class Database {
        public int $completed = 0;
        public function requireExec($sql) { if (str_contains($sql, 'SET PROCESSED=1')) $this->completed++; }
        public function select($sql) { return array(array('ID' => 1), array('ID' => 2), array('ID' => 3)); }
        public function affectedRows() { return 1; }
        public function one($sql) { return array('ATTEMPTS' => 1); }
        public function escape($value) { return $value; }
    }
    class ProcessRunner {
        public function run($kind, $id, $token, $timeout) {
            if ($timeout !== 300) throw new \RuntimeException('Unexpected job deadline');
            $GLOBALS['now'] += $timeout;
            $reason = $GLOBALS['policy']->stalled('cycle_scheduler', 1000, end($GLOBALS['beats']), \fixtureNow());
            if ($reason !== null) throw new \RuntimeException('Healthy scheduler would be killed: ' . $reason);
            return array('success' => true);
        }
    }
    class Core {
        public static function scheduler() { return $GLOBALS['scheduler']; }
    }
}
namespace {
    $now = 1000;
    $beats = array();
    function fixtureNow() { return $GLOBALS['now']; }
    function setGlobal($name, $value, $noLinked) {
        if ($name !== 'cycle_schedulerRun' || $noLinked !== 1) throw new RuntimeException('Invalid heartbeat');
        $GLOBALS['beats'][] = $value;
    }
    function isRebootRequired() { return false; }
    function DebMes($message, $channel) { throw new RuntimeException($message); }
PHP;
$facade = file_get_contents($project . '/lib/common.class.php');
if (!preg_match('/function runScheduledJobs\([^}]+\}/', $facade, $match)) throw new RuntimeException('Scheduler facade not found');
$loader .= "\n" . $match[0] . "\n";
$loader .= 'require ' . var_export($project . '/lib/core/Scheduler.php', true) . ";\n";
$loader .= 'require ' . var_export($project . '/lib/core/Cycles/CycleWatchdog.php', true) . ";\n";
$loader .= <<<'PHP'
    $policy = new \MajorDoMo\Core\Cycles\CycleWatchdog();
    $database = new \MajorDoMo\Core\Database();
    $scheduler = new \MajorDoMo\Core\Scheduler($database, new \MajorDoMo\Core\ProcessRunner());
    register_shutdown_function(function () use ($database) {
        echo 'FIXTURE_RESULT ' . json_encode(array('completed' => $database->completed, 'beats' => $GLOBALS['beats'], 'now' => fixtureNow())) . PHP_EOL;
    });
}
PHP;
file_put_contents($root . '/lib/loader.php', $loader);
try {
    $process = proc_open(array(PHP_BINARY, '-n', $root . '/scripts/cycle_scheduler.php'), array(
        0 => array('pipe', 'r'), 1 => array('file', $root . '/stdout', 'w'), 2 => array('file', $root . '/stderr', 'w'),
    ), $pipes, $root);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated scheduler');
    fclose($pipes[0]);
    $deadline = microtime(true) + 10;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) break;
        usleep(10000);
    } while (microtime(true) < $deadline);
    if ($status['running']) {
        proc_terminate($process, 9);
        proc_close($process);
        throw new RuntimeException('Scheduler fixture timed out');
    }
    proc_close($process);
    $output = file_get_contents($root . '/stdout');
    $errors = file_get_contents($root . '/stderr');
    if ($status['exitcode'] !== 0 || $errors !== '' || !preg_match('/^FIXTURE_RESULT (.+)$/m', $output, $match)) throw new RuntimeException('Scheduler failed: ' . $output . $errors);
    $result = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    if ($result['completed'] !== 3 || $result['now'] !== 1900) throw new RuntimeException('Scheduler did not complete all long jobs');
    if (array_values(array_unique($result['beats'])) !== array(1000, 1300, 1600, 1900)) throw new RuntimeException('Job batch stopped reporting progress');
    echo 'OK: scheduler completed three 300-second jobs and refreshed heartbeat between jobs' . PHP_EOL;
} finally {
    $resolved = realpath($root);
    if ($resolved !== false && dirname($resolved) === realpath(sys_get_temp_dir()) && str_starts_with(basename($resolved), 'mjd-scheduler-heartbeat-')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
