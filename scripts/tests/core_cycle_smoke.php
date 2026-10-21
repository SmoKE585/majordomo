<?php

// Execute a copy of the real cycle with isolated bootstrap fixtures only.
// The working config.php, database and Redis are never loaded.
$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mjd-core-cycle-' . bin2hex(random_bytes(8));
mkdir($fixture);
mkdir($fixture . '/scripts');
mkdir($fixture . '/lib');
copy(dirname(__DIR__) . '/cycle_core.php', $fixture . '/scripts/cycle_core.php');
file_put_contents($fixture . '/config.php', "<?php \$_GET['onetime'] = 1;\n");
file_put_contents($fixture . '/load_settings.php', "<?php\n");
$bootstrap = <<<'PHP'
<?php
namespace MajorDoMo\Core {
    class Core {
        public static function worker() {
            return new class {
                public function process() {
                    if (getenv('MJD_CYCLE_SCENARIO') === 'worker') throw new \RuntimeException('fixture worker failure');
                    echo "FIXTURE processed\n";
                    return 0;
                }
            };
        }
    }
}
namespace {
    if (getenv('MJD_CYCLE_SCENARIO') === 'boot') throw new \RuntimeException('fixture boot failure');
    function setGlobal($name, $value, $noLinked) {
        if (getenv('MJD_CYCLE_SCENARIO') === 'heartbeat') throw new \RuntimeException('fixture heartbeat failure');
        if ($name !== 'cycle_coreRun' || !is_int($value) || $noLinked !== 1) throw new \RuntimeException('Invalid heartbeat');
        echo "FIXTURE heartbeat\n";
    }
    function DebMes($message, $channel) { echo "FIXTURE log $channel: $message\n"; }
    function isRebootRequired() { return false; }
}
PHP;
file_put_contents($fixture . '/lib/loader.php', $bootstrap);
$checks = 0;
try {
    foreach (array('normal', 'worker', 'heartbeat', 'boot') as $scenario) {
        $environment = getenv();
        $environment['APP_ENV'] = 'testing';
        $environment['MJD_CYCLE_SCENARIO'] = $scenario;
        $process = proc_open(array(PHP_BINARY, '-n', $fixture . '/scripts/cycle_core.php'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $fixture, $environment);
        if (!is_resource($process)) throw new RuntimeException('Cannot start fixture process');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if (!str_contains($output, 'cycle_core starting') || !str_contains($output, 'cycle_core stopped')) throw new RuntimeException("Missing lifecycle output ($scenario): $output $errors");
        if ($scenario === 'normal') {
            if ($exitCode !== 0 || !str_contains($output, 'FIXTURE heartbeat') || !str_contains($output, 'cycle_core running') || !str_contains($output, 'FIXTURE processed')) throw new RuntimeException('Cycle did not become ready');
        } elseif ($scenario === 'boot') {
            if ($exitCode === 0 || !str_contains($output, 'fatal=') || !str_contains($output, 'fixture boot failure')) throw new RuntimeException('Bootstrap failure is not visible');
        } else {
            if ($exitCode !== 0 || !str_contains($output, "FIXTURE log cycle_core: Core worker: fixture $scenario failure")) throw new RuntimeException("Failure missing from cycle log ($scenario)");
            if ($scenario === 'heartbeat' && str_contains($output, 'cycle_core running')) throw new RuntimeException('Failed heartbeat must not report readiness');
        }
        echo "PASS $scenario\n";
        $checks++;
    }
} finally {
    foreach (array('/scripts/cycle_core.php', '/config.php', '/load_settings.php', '/lib/loader.php') as $file) unlink($fixture . $file);
    rmdir($fixture . '/scripts');
    rmdir($fixture . '/lib');
    rmdir($fixture);
}
echo "RESULT $checks isolated cycle scenarios passed\n";
