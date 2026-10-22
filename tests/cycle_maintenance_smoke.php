<?php

/** Files and owned subprocesses only: no application bootstrap or production services. */
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
require_once dirname(__DIR__) . '/lib/core/Cycles/CycleMaintenance.php';

use MajorDoMo\Core\Cycles\CycleMaintenance;

$checks = 0;
function check(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($message);
}
function expectFailure(callable $action, string $message): void
{
    try { $action(); } catch (RuntimeException $error) { check(true, $message); return; }
    throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/mjd-maintenance-' . bin2hex(random_bytes(6));
mkdir($root);
$process = null;
try {
    $supervisor = new CycleMaintenance($root);
    $updater = new CycleMaintenance($root);
    $competitor = new CycleMaintenance($root);
    $updater->begin();
    $request = $supervisor->active();
    check($request !== null && $request['pid'] === getmypid(), 'Active updater must be detected');
    expectFailure(fn() => $competitor->begin(), 'Concurrent system updates must be rejected');
    expectFailure(fn() => $updater->waitUntilReady(0), 'A stale or missing acknowledgement must block file replacement');
    $supervisor->acknowledge($request);
    $updater->waitUntilReady(0);
    check(true, 'Matching supervisor acknowledgement allows replacement');
    $updater->finish();
    check($supervisor->active() === null, 'Finished update releases the pause');
    check(trim(file_get_contents($root . '/reboot')) === 'system_update_complete', 'Finished update requests normal cycle recovery');

    $updater->begin('cycle_core');
    check($supervisor->active()['owner_cycle'] === 'cycle_core', 'An automatic updater identifies its owning cycle');
    expectFailure(fn() => $updater->waitUntilReady(0), 'Previous update acknowledgement must not acknowledge a new update');
    $updater->finish();

    $supervisor->beginStartup();
    expectFailure(fn() => $competitor->begin(), 'File replacement must not overlap supervisor startup');
    $supervisor->endStartup();

    // A real process is killed without finally/destructor cleanup.
    $child = $root . '/updater.php';
    file_put_contents($child, '<?php require ' . var_export(dirname(__DIR__) . '/lib/core/Cycles/CycleMaintenance.php', true)
        . '; $update = new \\MajorDoMo\\Core\\Cycles\\CycleMaintenance(' . var_export($root, true)
        . '); $update->begin(); file_put_contents(' . var_export($root . '/started', true)
        . ', "ready"); while (true) usleep(10000);');
    $process = proc_open(array(PHP_BINARY, '-n', $child), array(0 => array('pipe', 'r'),
        1 => array('file', $root . '/stdout', 'w'), 2 => array('file', $root . '/stderr', 'w')), $pipes, $root);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated updater');
    fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    while (!is_file($root . '/started') && microtime(true) < $deadline) usleep(10000);
    check(is_file($root . '/started') && $supervisor->active() !== null, 'Real updater holds the pause');
    proc_terminate($process, 9);
    proc_close($process);
    $process = null;
    check($supervisor->active() === null, 'OS must release maintenance after a hard kill');
    check(trim(file_get_contents($root . '/reboot')) === 'system_update_recovery', 'An abandoned update must schedule cycle recovery');

    // A supervisor starting during an update confirms quiescence before loading config.
    $updater->begin();
    file_put_contents($child, '<?php require ' . var_export(dirname(__DIR__) . '/lib/core/Cycles/CycleMaintenance.php', true)
        . '; $supervisor = new \\MajorDoMo\\Core\\Cycles\\CycleMaintenance(' . var_export($root, true)
        . '); $supervisor->beginStartup(); file_put_contents(' . var_export($root . '/booted', true)
        . ', "booted"); $supervisor->endStartup();');
    $process = proc_open(array(PHP_BINARY, '-n', $child), array(0 => array('pipe', 'r'),
        1 => array('file', $root . '/stdout', 'w'), 2 => array('file', $root . '/stderr', 'w')), $pipes, $root);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated supervisor');
    fclose($pipes[0]);
    $updater->waitUntilReady(5);
    check(!is_file($root . '/booted'), 'Config/startup must wait until replacement finishes');
    $updater->finish();
    check(proc_close($process) === 0 && is_file($root . '/booted'), 'Supervisor must continue startup after the update');
    $process = null;

    // Exercise the actual saverestore pause helper without booting the application.
    class module {}
    require_once dirname(__DIR__) . '/modules/saverestore/saverestore.class.php';
    define('DOC_ROOT', $root);
    $_SERVER['SCRIPT_FILENAME'] = $root . '/scripts/core_worker.php';
    $module = (new ReflectionClass('saverestore'))->newInstanceWithoutConstructor();
    file_put_contents($child, '<?php require ' . var_export(dirname(__DIR__) . '/lib/core/Cycles/CycleMaintenance.php', true)
        . '; $supervisor = new \\MajorDoMo\\Core\\Cycles\\CycleMaintenance(' . var_export($root, true)
        . '); $deadline = microtime(true) + 5; do { $request = $supervisor->active();'
        . ' if ($request !== null) { $supervisor->acknowledge($request); exit(0); } usleep(10000);'
        . ' } while (microtime(true) < $deadline); exit(1);');
    $process = proc_open(array(PHP_BINARY, '-n', $child), array(0 => array('pipe', 'r'),
        1 => array('file', $root . '/stdout', 'w'), 2 => array('file', $root . '/stderr', 'w')), $pipes, $root);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated pause responder');
    fclose($pipes[0]);
    $pause = $module->stopCyclesBeforeSystemUpdate();
    try {
        check($pause instanceof CycleMaintenance && $supervisor->active()['owner_cycle'] === 'cycle_core',
            'Saverestore must wait for confirmation and protect its actual worker owner');
        check(!is_file($root . '/cms/cycles/state.json'), 'Update pause must not write persistent stop commands');
    } finally {
        $pause->finish();
    }
    check(proc_close($process) === 0 && $supervisor->active() === null, 'Saverestore pause must release for recovery');
    $process = null;
} finally {
    if (is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
    unset($updater, $competitor, $supervisor);
    $remove = static function (string $path) use (&$remove): void {
        if (!is_dir($path)) { unlink($path); return; }
        foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
        rmdir($path);
    };
    $resolved = str_replace('\\', '/', (string)realpath($root));
    if (str_starts_with($resolved, str_replace('\\', '/', sys_get_temp_dir()) . '/mjd-maintenance-')) $remove($root);
}
echo 'Cycle maintenance smoke tests passed: ' . $checks . ' checks.' . PHP_EOL;
