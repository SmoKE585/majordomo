<?php

/** Real supervisor runtime and owned child processes, with an isolated bootstrap and virtual time. */
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
require_once dirname(__DIR__) . '/lib/core/Cycles/CycleWatchdog.php';
use MajorDoMo\Core\Cycles\CycleWatchdog;

$checks = 0;
function check($condition, $message) {
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($message);
}
$policy = new CycleWatchdog();
check($policy->stalled('cycle_market', 1000, 0, 1179) === null, 'Startup must have a grace period');
check(str_contains($policy->stalled('cycle_market', 1000, 0, 1180), 'No heartbeat'), 'A cycle without its first heartbeat must be restarted');
check($policy->stalled('cycle_market', 1000, 1100, 1219) === null, 'Recent heartbeat must remain healthy');
check(str_contains($policy->stalled('cycle_market', 1000, 1100, 1220), 'Heartbeat stalled'), 'Hung cycles must time out after 120 seconds');
check(str_contains($policy->stalled('cycle_market', 1000, 999, 1180), 'No heartbeat'), 'A previous process heartbeat must not validate a replacement');
check(str_contains($policy->stalled('cycle_market', 1000, 99999, 1180), 'No heartbeat'), 'Future timestamps must not conceal a missing heartbeat');
$slow = new CycleWatchdog(120, 180, array('cycle_slow_market' => 900));
check($slow->stalled('cycle_slow_market', 1000, 1001, 1500) === null, 'Slow market module must respect its override');
check($policy->timeout('cycle_scheduler') === 360, 'Scheduler must allow the enforced job deadline plus cleanup');
check($policy->stalled('cycle_scheduler', 1000, 1000, 1300) === null, 'A legitimate long job must not restart the scheduler');
check(str_contains($policy->stalled('cycle_scheduler', 1000, 1000, 1360), 'Heartbeat stalled'), 'Scheduler must still recover when it exceeds the job deadline');
check((new CycleWatchdog(120, 180, array(), 600))->timeout('cycle_scheduler') === 660, 'Custom job deadlines must be respected');
check((new CycleWatchdog(120, 180, array('cycle_scheduler' => 900)))->timeout('cycle_scheduler') === 900, 'Explicit scheduler override must take precedence');
check($policy->failure('cycle_flapping', 1000)['delay'] === 2, 'First crash must restart promptly');
check(!$policy->failure('cycle_flapping', 1001)['critical'], 'Second crash must remain below the escalation threshold');
check($policy->failure('cycle_flapping', 1002)['critical'], 'Three crashes must raise a critical incident');
for ($i = 0; $i < 1000; $i++) $retry = $policy->failure('cycle_flapping', 1003);
check($retry['delay'] === 60 && $retry['count'] === 32, 'Restart backoff and diagnostic memory must stay bounded');
$policy->stalled('cycle_flapping', 1000, 1004, 1004);
$policy->stalled('cycle_flapping', 1000, 1604, 1604);
check($policy->failure('cycle_flapping', 1605)['count'] === 1, 'Ten healthy minutes must reset a crash streak');

$project = str_replace('\\', '/', dirname(__DIR__));
$source = str_replace("\r\n", "\n", file_get_contents($project . '/cycle.php'));
$begin = strpos($source, '$cycleWatchdog = new ');
$end = strrpos($source, '$mainCycleShutdownExpected = true;');
if ($begin === false || $end <= $begin) throw new RuntimeException('Supervisor runtime boundaries not found');
$runtime = substr($source, $begin, $end - $begin);
$runtime = preg_replace('/(?<![A-Za-z0-9_])time\(\)/', 'fixtureNow()', $runtime);
$runtime = str_replace('sleep(1);', 'usleep(10000);', $runtime);
$runtime = str_replace("while (true) {", "while (++\$fixtureIteration <= 40) {\n    \$fixtureTime += 5;\n    fixtureBeforeIteration();", $runtime, $replacements);
if ($replacements !== 1) throw new RuntimeException('Supervisor test loop was not bounded');
// Use the actual escalation/reporting helper; log and error sinks are fixture-only.
$reportStart = strpos($source, 'function reportCycleRuntimeFailure(');
$reportEnd = strpos($source, 'function startCycleThread(', $reportStart);
$report = preg_replace('/(?<![A-Za-z0-9_])time\(\)/', 'fixtureNow()', substr($source, $reportStart, $reportEnd - $reportStart));

$tempRoot = str_replace('\\', '/', sys_get_temp_dir());
$fixture = $tempRoot . '/mjd-watchdog-' . bin2hex(random_bytes(6));
mkdir($fixture);
$fixtureProcesses = array();
$bootstrap = <<<'PHP'
<?php
namespace MajorDoMo\Core {
    class Config {
        public static function int($key, $default = 0) { return 10; }
        public static function arrayValue($key) { return array(); }
    }
    class Core {
        public static function cache() { return new class {
            public function cycles() {
                if ($GLOBALS['scenario'] === 'storage') throw new \RuntimeException('fixture Redis outage');
                $data = $GLOBALS['fixtureCache'];
                $data['cycle_healthyRun'] = \fixtureNow();
                if ($GLOBALS['scenario'] === 'hung') $data['cycle_marketRun'] = 1000;
                elseif (!in_array($GLOBALS['scenario'], array('silent','missing'), true)) $data['cycle_marketRun'] = \fixtureNow();
                $rows = array();
                foreach ($data as $key => $value) $rows[] = array('TITLE'=>$key,'VALUE'=>$value);
                return $rows;
            }
        }; }
    }
}
namespace {
    function fixtureNow() { return $GLOBALS['fixtureTime']; }
    function fixtureBeforeIteration() {
        if (in_array($GLOBALS['scenario'], array('disabled','stopped'), true) && $GLOBALS['fixtureIteration'] === 5) $GLOBALS['cycleStateStore']->set('cycle_market',$GLOBALS['scenario']);
        if ($GLOBALS['scenario'] === 'restart' && $GLOBALS['fixtureIteration'] === 5) {
            $GLOBALS['cycleStateStore']->set('cycle_market','running',true);
        }
    }
    function IsWindowsOS() { return PHP_OS_FAMILY === 'Windows'; }
    function DebMes($message, $channel = '') { $GLOBALS['fixtureLogs'][] = $message; }
    function saveCycleToCache($name, $value) { $GLOBALS['fixtureCache'][$name] = $value; }
    function setGlobal($name, $value, $skip = 0) { saveCycleToCache($name, $value); }
    function getGlobal($name) { return $GLOBALS['fixtureCache'][$name] ?? ''; }
    function checkCycleFromCache($name) {
        if ($GLOBALS['scenario'] === 'storage') throw new RuntimeException('fixture Redis outage');
        return getGlobal($name);
    }
    function setCycleRuntimeStatus($name, $status, $details = '') { $GLOBALS['fixtureStatus'][$name] = array($status,$details); }
    function addCycleRuntimeLog($name, $message) { $GLOBALS['fixtureLogs'][] = $message; }
    function registerError($code, $details) { $GLOBALS['fixtureErrors'][] = array($code,$details); }
    function getObject($name) { return null; }
    function isRebootRequired() {
        if ($GLOBALS['scenario'] === 'controller') throw new RuntimeException('fixture controller failure');
        return false;
    }
    function resetRebootRequired() {}
    function startCycleThread($threads, $title, $cmd) {
        if ($GLOBALS['scenario'] === 'spawnfail' && $title === 'cycle_market') return false;
        $id = $threads->newThread($cmd);
        if ($id && $GLOBALS['scenario'] === 'restart') saveCycleToCache($title.'Run',fixtureNow());
        return $id;
    }
    function buildCycleStopReason($title, $command, $exit, $signal, $stop, $restart, $error) { return 'Exit code: '.$exit.'; signal: '.$signal.'; '.$error; }
}
PHP;

try {
    foreach (array('crash','silent','hung','disabled','stopped','restart','storage','missing','spawnfail','filelog','controller') as $scenario) {
        if (isset($argv[1]) && $argv[1] !== $scenario) continue;
        $directory = $fixture . '/' . $scenario;
        mkdir($directory . '/scripts', 0777, true);
        mkdir($directory . '/logs');
        $supervisorSource = file_get_contents($project . '/lib/core/Cycles/CycleSupervisor.php');
        $supervisorSource = preg_replace('/(?<![A-Za-z0-9_])time\(\)/', '\\fixtureNow()', $supervisorSource);
        file_put_contents($directory . '/CycleSupervisor.php', $supervisorSource);
        file_put_contents($directory . '/scripts/cycle_healthy.php', '<?php while (true) usleep(100000);');
        if ($scenario !== 'missing') {
            $child = in_array($scenario, array('crash','storage'), true)
                ? '<?php fwrite(STDERR,"fixture sensor failure\n"); exit(7);'
                : ($scenario === 'filelog' ? '<?php invalid fixture syntax {' : '<?php echo "partial output"; fflush(STDOUT); while (true) usleep(100000);');
            file_put_contents($directory . '/scripts/cycle_market.php', $child);
        }
        $setup = "\nnamespace {\n"
            . 'require_once ' . var_export($project . '/lib/threads.php', true) . ";\n"
            . 'require_once ' . var_export($project . '/lib/core/Cycles/CycleWatchdog.php', true) . ";\n"
            . 'require_once ' . var_export($project . '/lib/core/Cycles/CycleStateStore.php', true) . ";\n"
            . 'require_once ' . var_export($directory . '/CycleSupervisor.php', true) . ";\n"
            . 'define("ROOT",' . var_export($directory . '/', true) . ");\n"
            . ($scenario === 'filelog' ? 'define("LOG_CYCLES",1); define("LOG_DIRECTORY",'.var_export($directory.'/logs',true).");\n" : '')
            . '$scenario=' . var_export($scenario, true) . ";\n"
            . '$fixtureCache=$fixtureLogs=$fixtureErrors=$fixtureStatus=array(); $fixtureTime=1000; $fixtureIteration=0; $pipes=array();' . "\n"
            . '$cycles=array(ROOT."scripts/cycle_market.php",ROOT."scripts/cycle_healthy.php"); $cycleTitles=array("cycle_market","cycle_healthy");' . "\n"
            . '$cycleStateStore=new \MajorDoMo\Core\Cycles\CycleStateStore(ROOT); $cycleSupervisor=new \MajorDoMo\Core\Cycles\CycleSupervisor($cycleStateStore); $cycleSupervisor->bootstrap($cycleTitles);' . "\n"
            . '$threads=new Threads(); $threads->phpPath=PHP_BINARY;' . "\n"
            . 'register_shutdown_function(function() use ($threads) { foreach(array_keys($threads->handles) as $id) $threads->closeThread($id); });' . "\n"
            . $report . "\n" . $runtime
            . 'echo "FIXTURE_RESULT ".json_encode(array("errors"=>$fixtureErrors,"logs"=>$fixtureLogs,"beats"=>$fixtureCache,"active"=>array_values($threads->commandLines),"iterations"=>$fixtureIteration,"desired"=>$cycleStateStore->desired("cycle_market"))).PHP_EOL;' . "\n}\n";
        file_put_contents($directory . '/controller.php', $bootstrap . $setup);
        $started = microtime(true);
        $outputPath = $directory . '/stdout.txt';
        $errorPath = $directory . '/stderr.txt';
        $process = proc_open(array(PHP_BINARY, '-n', $directory . '/controller.php'), array(0=>array('pipe','r'),1=>array('file',$outputPath,'w'),2=>array('file',$errorPath,'w')), $pipes, $directory);
        if (!is_resource($process)) throw new RuntimeException('Cannot start isolated supervisor fixture');
        $fixtureProcesses[] = $process;
        fclose($pipes[0]);
        do {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) - $started > 25) { proc_terminate($process); throw new RuntimeException('Supervisor blocked in fixture ' . $scenario); }
            usleep(20000);
        } while (true);
        $code = proc_close($process);
        $output = file_get_contents($outputPath);
        $errors = file_get_contents($errorPath);
        array_pop($fixtureProcesses);
        if ($scenario === 'controller') {
            check($code === 1 && substr_count($output, 'CRITICAL: supervisor iteration failed') === 3
                && str_contains($errors, 'exiting for service-manager recovery'), 'Repeated supervisor failures must be explicit and exit for service recovery');
            echo 'PASS isolated supervisor: controller' . PHP_EOL;
            continue;
        }
        if (!preg_match('/^FIXTURE_RESULT (.+)$/m', $output, $match)) throw new RuntimeException("Fixture failed ($scenario), exit=$code: $output $errors");
        $result = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        check($code === 0 && microtime(true) - $started < 15, 'Supervisor must stay responsive in ' . $scenario);
        check(substr_count($output, '/scripts/cycle_healthy.php" "--params"') <= 1 || PHP_OS_FAMILY !== 'Windows', 'A healthy cycle must not be repeatedly restarted');
        if (in_array($scenario, array('disabled','stopped'), true)) {
            check($result['desired'] === $scenario && !$result['errors'], 'Administrator-stopped cycle must stay off without false alarms');
            check(substr_count($output, 'Starting thread:') === 2, 'Disabled cycle must not be respawned');
            check(!str_contains(json_encode($result['active']), 'cycle_market.php'), 'Stopped cycle process must actually be gone');
        } elseif ($scenario === 'restart') {
            check(!$result['errors'] && substr_count($output, 'Starting thread:') === 3, 'Explicit restart must start exactly one replacement without false crash errors: ' . json_encode($result['errors']) . "\n" . $output);
            check(!empty($result['beats']['cycle_marketRun']), 'Delayed close notification must not clear the replacement heartbeat');
        } else {
            check(count($result['errors']) >= 3 && str_contains(json_encode($result['errors']), 'CRITICAL'), 'Repeated failure must escalate in ' . $scenario);
            check(substr_count($output, 'Starting thread:') > 2 || in_array($scenario, array('missing','spawnfail'), true), 'Cycle must restart even while another cycle stays healthy: ' . $scenario);
            if ($scenario === 'crash') check(str_contains(json_encode($result['errors']), 'fixture sensor failure') && str_contains(json_encode($result['errors']), 'Exit code: 7'), 'Crash diagnostics must include stderr and exit code');
            if ($scenario === 'silent') check(str_contains(json_encode($result['errors']), 'No heartbeat'), 'First-heartbeat failure must be visible');
            if ($scenario === 'hung') check(str_contains(json_encode($result['errors']), 'Heartbeat stalled'), 'Existing stale heartbeat must trigger recovery');
            if ($scenario === 'filelog') check(str_contains(json_encode($result['errors']), 'Parse error'), 'Fatal error in redirected file log must remain diagnosable');
            if ($scenario === 'storage') check(str_contains($output, 'heartbeat storage is unavailable'), 'Storage outage must be visible without stopping process recovery');
        }
        echo 'PASS isolated supervisor: ' . $scenario . PHP_EOL;
    }
} finally {
    foreach ($fixtureProcesses as $process) if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $resolved = str_replace('\\', '/', (string)realpath($fixture));
    if (str_starts_with($resolved, $tempRoot . '/mjd-watchdog-')) {
        $remove = static function($path) use (&$remove) {
            if (!is_dir($path)) { unlink($path); return; }
            foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $remove($path.'/'.$name);
            rmdir($path);
        };
        $remove($fixture);
    }
}
// Exercise the real bounded SQL log writer too; no application/config bootstrap is loaded.
eval('namespace MajorDoMo\\Core; class Core { public static $fixtureDatabase; public static function database() { return self::$fixtureDatabase; } }');
$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
check(getenv('APP_ENV') === 'testing' && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Log writer must run on isolated SQLite');
$pdo->exec('CREATE TABLE cached_cycle_logs (ID INTEGER PRIMARY KEY AUTOINCREMENT,CYCLE TEXT,ADDED INTEGER,MESSAGE TEXT)');
$logDatabase = new class($pdo) {
    public int $queries = 0;
    public function __construct(private PDO $pdo) {}
    public function escape($value) { return str_replace("'", "''", (string)$value); }
    public function requireExec($sql, $defer) {
        check($defer === true, 'Runtime logging must not invoke the terminating legacy SQL error handler');
        $this->queries++;
        return $this->pdo->exec($sql);
    }
    public function one($sql) { $this->queries++; return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: null; }
};
\MajorDoMo\Core\Core::$fixtureDatabase = $logDatabase;
$logFunctionStart = strpos($source, 'function addCycleRuntimeLog(');
$logFunctionEnd = strpos($source, 'function reportCycleRuntimeFailure(', $logFunctionStart);
eval(substr($source, $logFunctionStart, $logFunctionEnd - $logFunctionStart));
addCycleRuntimeLog('cycle_market', implode("\n", array_map(fn($n) => "sensor's output " . $n, range(1, 500))));
check((int)$pdo->query('SELECT COUNT(*) FROM cached_cycle_logs')->fetchColumn() === 80 && $logDatabase->queries === 2, 'A large output chunk must use one batch and retain at most 80 rows');
addCycleRuntimeLog('cycle_market', 'last message');
check((int)$pdo->query('SELECT COUNT(*) FROM cached_cycle_logs')->fetchColumn() === 80
    && $pdo->query('SELECT MESSAGE FROM cached_cycle_logs ORDER BY ID DESC LIMIT 1')->fetchColumn() === 'last message', 'Runtime log ring must retain the newest diagnostics');
echo 'Cycle watchdog smoke tests passed: ' . $checks . ' checks.' . PHP_EOL;
