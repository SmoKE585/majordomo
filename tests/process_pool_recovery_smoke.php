<?php
/** 2026-10-25 — реальные дочерние процессы и виртуальные паузы восстановления в Linux. */
putenv('APP_ENV=testing'); putenv('USE_REDIS=false');
if (PHP_OS_FAMILY !== 'Linux') { echo "SKIP: проверка пула выполняется в Linux.\n"; exit; }
require dirname(__DIR__) . '/lib/core.class.php';
MajorDoMo\Core\Config::boot(sys_get_temp_dir() . '/mjd-pool-config-' . bin2hex(random_bytes(6)));
$root = sys_get_temp_dir() . '/mjd-pool-' . bin2hex(random_bytes(6)); mkdir($root, 0700);
$now = 1000; $errors = array(); $checks = 0;
function poolNow() { return $GLOBALS['now']; }
function registerError($code, $details) { $GLOBALS['errors'][] = array($code, $details); }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
$source = file_get_contents(dirname(__DIR__) . '/lib/core/ProcessPool.php');
$source = str_replace('microtime(true)', '\\poolNow()', $source);
$source = preg_replace('/(?<![A-Za-z0-9_])time\(\)/', '\\poolNow()', $source);
eval(substr($source, 5));
$pool = new MajorDoMo\Core\ProcessPool($root);
$pool->add('broken', array(PHP_BINARY, '-r', 'fwrite(STDERR,"fixture fatal cause\n"); exit(0);'));
$marker = $root . '/first-run';
$pool->add('transient', array(PHP_BINARY, '-r', 'if (!is_file(' . var_export($marker, true) . ')) { touch(' . var_export($marker, true) . '); exit(7); } while (true) usleep(10000);'));
try {
    $pool->start();
    for ($i = 0; $i < 40; $i++) {
        usleep(50000); $now += 5; $pool->maintain();
        if ($pool->restarts('broken') < 4) check($errors === array(), 'Промежуточное восстановление не создаёт ошибок');
    }
    check(count($errors) === 1 && $errors[0][0] === 'worker_stop:broken', 'Пул создаёт одну ошибку после трёх неудачных повторов');
    $data = MajorDoMo\Core\ErrorDetails::decode($errors[0][1]);
    check(str_contains($data['message'], 'fixture fatal cause'), 'Причина из stderr сохранена вместе с неожиданным exit 0');
    check($pool->pids()['broken'] === 0 && $pool->restarts('broken') === 4, 'После исчерпания попыток процесс больше не запускается');
    check($pool->pids()['transient'] > 0 && $pool->restarts('transient') === 1, 'Один временный сбой восстанавливается без уведомления');
} finally {
    $pool->stop();
    foreach (glob($root . '/*') ?: array() as $file) unlink($file);
    rmdir($root);
}
echo 'Process pool recovery smoke: ' . $checks . ' passed; Linux, без приложения и внешних сервисов.' . PHP_EOL;
