<?php
// 2026-10-25 — предварительная загрузка, изоляция процессов, отказ и таймаут без приложения и внешних сервисов.

putenv('APP_ENV=testing'); putenv('USE_REDIS=false');
if (PHP_OS_FAMILY !== 'Linux') {
    echo "SKIP: process runner targets Linux; run this test in a Linux container.\n";
    exit(0);
}
require dirname(__DIR__) . '/lib/core/ProcessRunner.php';
$root = str_replace('\\', '/', sys_get_temp_dir()) . '/mjd-runner-' . bin2hex(random_bytes(6)) . '/';
mkdir($root . 'scripts', 0700, true);
mkdir($root . 'lib', 0700, true);
$checks = 0; $logs = array(); $runner = null;
function DebMes($message, ...$args) { $GLOBALS['logs'][] = $message; }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
register_shutdown_function(static function () use ($root) {
    $GLOBALS['runner'] = null;
    $resolved = str_replace('\\', '/', (string)realpath($root));
    if (!str_starts_with($resolved, str_replace('\\', '/', sys_get_temp_dir()) . '/mjd-runner-')) return;
    foreach (glob($root . 'scripts/*') as $file) unlink($file);
    rmdir($root . 'scripts');
    foreach (glob($root . 'lib/*') as $file) unlink($file);
    rmdir($root . 'lib');
    foreach (glob($root . '*') as $file) unlink($file);
    rmdir($root);
});
copy(dirname(__DIR__) . '/scripts/core_worker.php', $root . 'scripts/core_worker.php');
file_put_contents($root . 'config.php', <<<'PHP'
<?php
namespace MajorDoMo\Core;
class Core {
    public static function worker() { return new self(); }
    public static function scheduler() { return new self(); }
    public function execute($id,$token) {
        if ($id === 'exit') exit(3);
        if ($id === 'timeout') sleep(4);
        echo str_repeat('x',70000), "\n";
        fwrite(STDERR,str_repeat('e',16000));
        return ['success'=>true,'pid'=>getmypid(),'id'=>$id,'token'=>$token,'settings'=>defined('SETTINGS_FIXTURE')];
    }
}
PHP);
file_put_contents($root . 'lib/loader.php', '<?php usleep(100000); file_put_contents(dirname(__DIR__)."/starts",getmypid()."\n",FILE_APPEND|LOCK_EX);');
file_put_contents($root . 'lib/loader.php', 'require ' . var_export(dirname(__DIR__) . '/lib/core/ErrorDetails.php', true) . ';', FILE_APPEND);
file_put_contents($root . 'load_settings.php', '<?php define("SETTINGS_FIXTURE",true); file_put_contents(__DIR__."/settings-".getmypid(),"loaded");');
$runner = new MajorDoMo\Core\ProcessRunner($root);
check($runner->prepare(), 'Подготовленный процесс запущен');
$deadline = microtime(true) + 5;
while (!is_file($root . 'starts') && microtime(true) < $deadline) usleep(10000);
check(is_file($root . 'starts'), 'Загрузка завершилась до получения задания');
$pid = (int)trim(file_get_contents($root . 'starts'));
check(!is_file($root . 'settings-' . $pid), 'Настройки читаются после получения задания, а не во время предварительной загрузки');
$first = $runner->run('operation', 'one', 'token-1', 3);
check($first['success'] && $first['pid'] === $pid && $first['id'] === 'one' && $first['token'] === 'token-1' && $first['settings'], 'Настоящий core_worker получил задание и свежие настройки');
$second = $runner->run('operation', 'two', 'token-2', 3);
check($second['success'] && $second['pid'] !== $first['pid'] && $second['token'] === 'token-2', 'Разные задания изолированы разными процессами');
check(count($logs) > 0, 'Большие stdout/stderr прочитаны без блокировки');
$failure = $runner->run('operation', 'exit', 'token-3', 3);
check(!$failure['success'], 'Выход без результата не считается успехом');
$started = microtime(true);
$timeout = $runner->run('operation', 'timeout', 'token-4', 1);
check(!$timeout['success'] && str_contains($timeout['error'], 'timeout') && microtime(true) - $started < 3, 'Таймаут завершает пользовательский процесс');
$last = $runner->run('operation', 'after-timeout', 'token-5', 3);
check($last['success'] && $last['id'] === 'after-timeout', 'Следующее задание работает после таймаута');
$cold = (new MajorDoMo\Core\ProcessRunner($root))->run('job', 'job', 'job-token', 3);
check($cold['success'] && $cold['id'] === 'job', 'Прежний запуск задач планировщика сохранён');
if (function_exists('pcntl_alarm')) {
    $started = microtime(true);
    $process = proc_open(array(PHP_BINARY, $root . 'scripts/core_worker.php', 'operation', 'timeout', 'own-deadline', '1'),
        array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w')), $pipes, $root);
    fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 124 && microtime(true) - $started < 3,
        'Самостоятельный дедлайн работает без контроля времени родителем');
}
$runner = null;
echo 'Process runner smoke: ' . $checks . " passed; disposable processes, no application boot.\n";
