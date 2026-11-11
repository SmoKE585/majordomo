<?php

/** 2026-11-11 — настоящий main с заглушками, готовность процессов, heartbeat и таймауты без рабочих сервисов. */
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('USE_REDIS=false');
require dirname(__DIR__) . '/lib/core.class.php';
use MajorDoMo\Core\{Config, Console\PhpToolRunner, Cycles\CycleReadiness};
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$root = sys_get_temp_dir() . '/mjd-startup-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$root = realpath($root);
if ($root === false || dirname($root) !== realpath(sys_get_temp_dir())) throw new RuntimeException('Isolation failed');
$project = dirname(__DIR__);
$remove = static function ($path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) $remove($item->getPathname());
    rmdir($path);
};
$checks = 0;
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
try {
    Config::boot($root);
    $mainReadiness = new CycleReadiness($root);
    $is_running = array();
    $threads = new class {
        public int $pid;
        public bool $running = true;
        public function getThreadInfo($id) { return array('pid' => $this->pid, 'running' => $this->running); }
    };
    $threads->pid = getmypid();
    $source = file_get_contents($project . '/cycle.php');
    $begin = strpos($source, '$mayStartCycle = static function');
    $end = strpos($source, "\nforeach (\$cycles as \$path)", $begin);
    if ($begin === false || $end === false) throw new RuntimeException('Не найдено правило запуска циклов');
    eval(substr($source, $begin, $end - $begin));
    check($mayStartCycle('cycle_main') && !$mayStartCycle('cycle_websockets'), 'Main запускается первым, WebSocket ожидает');
    $is_running['cycle_main'] = 1;
    check(!$mayStartCycle('cycle_websockets'), 'Само наличие процесса main не означает готовность');
    $mainReadiness->markReady();
    check($mayStartCycle('cycle_websockets'), 'Готовый main разрешает запуск дочернего цикла');
    $threads->pid++;
    check(!$mayStartCycle('cycle_websockets'), 'Маркер прошлого PID не разрешает новый запуск');
    $threads->pid = getmypid(); $threads->running = false;
    check(!$mayStartCycle('cycle_websockets'), 'Завершившийся main не разрешает запуск зависимых циклов');
    $mainReadiness->reset();
    $cycleSupervisor = new class { public function isDesiredRunning($title) { return true; } };
    $cycleReloadStarted = false; $cycleUpdate = null;
    $to_stop = array(); $to_start = array('cycle_websockets' => time() - 1);
    $startFailures = 0; $runtimeStatuses = array();
    function isRebootRequired() { return false; }
    function setCycleRuntimeStatus(...$args) { $GLOBALS['runtimeStatuses'][] = $args; }
    function reportCycleRuntimeFailure(...$args) { $GLOBALS['startFailures']++; return 5; }
    function DebMes(...$args) {}
    function addCycleRuntimeLog(...$args) {}
    function startCycleThread(...$args) { return 9; }
    $begin = strpos($source, 'foreach ($to_start as $title => $tm)');
    $end = strpos($source, 'if (!empty($result))', $begin);
    if ($begin === false || $end === false) throw new RuntimeException('Не найден обработчик запусков');
    $pendingLoop = substr($source, $begin, $end - $begin);
    for ($tick = 0; $tick < 5; $tick++) eval($pendingLoop);
    check($startFailures === 0 && !isset($is_running['cycle_websockets']) && isset($to_start['cycle_websockets']), 'Ожидание main не расходует повторы и сохраняет запрос запуска');
    $threads->running = true;
    $mainReadiness->markReady();
    $to_start['cycle_websockets'] = time() - 1;
    eval($pendingLoop);
    check(($is_running['cycle_websockets'] ?? null) === 9 && !isset($to_start['cycle_websockets']), 'Супервизор запускает ожидающий WebSocket после готовности main');
    $mainReadiness->reset();

    mkdir($root . '/scripts', 0700);
    mkdir($root . '/lib', 0700);
    mkdir($root . '/modules/control_modules', 0700, true);
    copy($project . '/scripts/cycle_main.php', $root . '/scripts/cycle_main.php');
    foreach (array('lib/loader.php', 'lib/threads.php', 'load_settings.php') as $file) file_put_contents($root . '/' . $file, '<?php');
    file_put_contents($root . '/modules/control_modules/control_modules.class.php', '<?php class control_modules {}');
    $config = <<<'PHP'
<?php
namespace MajorDoMo\Core {
    final class Core {
        public static function queue() {
            return new class {
                public function enqueueRuntime($kind, $params, $target) { \record('queue:' . $target); return true; }
            };
        }
    }
}
namespace {
    define('ROOT', __DIR__ . '/');
    define('DIR_MODULES', ROOT . 'modules/');
    $_GET['onetime'] = 1;
    function record($event) { file_put_contents(ROOT . 'events.log', $event . "\n", FILE_APPEND); }
    function setGlobal($key, $value, ...$args) { record('set:' . $key); }
    function sg(...$args) { return setGlobal(...$args); }
    function getGlobal($key) { return time() - 3600; }
    function processSubscriptionsSafe($event) { record('event:' . $event); return true; }
    function SQLSelectOne($sql) { return array(); }
    function SQLSelect($sql) { return array(array('TITLE' => 'FixtureTimer')); }
    function callMethodSafe(...$args) { throw new TypeError('fixture timer failure'); }
    function DebMes($message, ...$args) { record('log:' . $message); }
    require READER_PATH;
}
PHP;
    $config = str_replace('READER_PATH', var_export($project . '/lib/core/Cycles/CycleReadiness.php', true), $config);
    file_put_contents($root . '/config.php', $config);
    $runner = new PhpToolRunner();
    $result = $runner->run($root . '/scripts/cycle_main.php', array(), $root, 5);
    check($result['code'] === 0 && $result['stderr'] === '', 'Настоящий main стартует без класса timer и переживает TypeError обработчика');
    $events = file_get_contents($root . '/events.log');
    check(str_contains($events, 'queue:ThisComputer.StartUp') && str_contains($events, 'event:startup'), 'StartUp и startup передаются штатной очереди');
    check(str_contains($events, 'fixture timer failure'), 'Ошибка пользовательского обработчика зафиксирована');
    $ready = json_decode(file_get_contents($root . '/cms/cycles/main.ready.json'), true, 512, JSON_THROW_ON_ERROR);
    check((int)$ready['pid'] > 0, 'Main подтверждает готовность после инициализации');

    file_put_contents($root . '/wait.php', '<?php usleep(300000); echo "done";');
    $heartbeats = 0;
    $result = $runner->run($root . '/wait.php', array(), $root, 3, static function () use (&$heartbeats) { $heartbeats++; });
    check($result['code'] === 0 && $result['stdout'] === 'done' && $heartbeats > 1, 'Родитель обновляет heartbeat, пока дочерний процесс занят');
    file_put_contents($root . '/timeout.php', '<?php sleep(30);');
    $started = microtime(true);
    try { $runner->run($root . '/timeout.php', array(), $root, 1); throw new LogicException('Таймаут не сработал'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'таймаут'), 'Зависший этап останавливается по дедлайну'); }
    check(microtime(true) - $started < 5, 'Остановка дочернего процесса не зависает');
    if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_setsid')) {
        $descendant = <<<'PHP'
<?php
if (posix_setsid() < 0) throw new RuntimeException('setsid failed');
$child = proc_open(array(PHP_BINARY, '-r', 'sleep(30);'),
    array(0 => array('pipe', 'r'), 1 => array('file', __DIR__ . '/grandchild.log', 'a'), 2 => array('file', __DIR__ . '/grandchild.log', 'a')), $pipes);
if (!is_resource($child)) throw new RuntimeException('child failed');
fclose($pipes[0]);
file_put_contents(__DIR__ . '/grandchild.pid', (string)proc_get_status($child)['pid']);
sleep(30);
PHP;
        file_put_contents($root . '/descendant.php', $descendant);
        try { $runner->run($root . '/descendant.php', array(), $root, 1); throw new LogicException('Таймаут группы не сработал'); }
        catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'таймаут'), 'Дедлайн срабатывает для группы процессов'); }
        $pid = (int)file_get_contents($root . '/grandchild.pid');
        $alive = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $alive = posix_kill($pid, 0);
            if ($alive && is_file('/proc/' . $pid . '/stat')) {
                $stat = file_get_contents('/proc/' . $pid . '/stat');
                if (preg_match('/\) ([A-Z]) /', $stat, $match) && $match[1] === 'Z') $alive = false;
            }
            if (!$alive) break;
            usleep(20000);
        }
        check(!$alive, 'Таймаут не оставляет работающий дочерний mysqldump-подобный процесс');
    }
    echo 'OK: ' . $checks . ' cycle startup checks (' . PHP_OS_FAMILY . ")\n";
} finally { $remove($root); }
