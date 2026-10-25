<?php
/** 2026-10-25 — зависание контроллера определяется без БД/Redis на временных файлах. */
putenv('APP_ENV=testing');
require dirname(__DIR__) . '/lib/core/WorkerHeartbeat.php';
$root = sys_get_temp_dir() . '/mjd-worker-health-' . bin2hex(random_bytes(5));
try {
    MajorDoMo\Core\WorkerHeartbeat::touch($root, 123);
    if (MajorDoMo\Core\WorkerHeartbeat::stalled($root, 123, 200, 120)) throw new RuntimeException('Ложное зависание');
    touch($root . '/cms/cycles/workers/123.beat', time() - 130);
    if (!MajorDoMo\Core\WorkerHeartbeat::stalled($root, 123, 200, 120)) throw new RuntimeException('Зависание не обнаружено');
    if (MajorDoMo\Core\WorkerHeartbeat::stalled($root, 123, 5, 120)) throw new RuntimeException('Не учтён запуск');
    MajorDoMo\Core\WorkerHeartbeat::remove($root, 123);
    if (!MajorDoMo\Core\WorkerHeartbeat::stalled($root, 123, 200, 120)) throw new RuntimeException('Исчезнувший heartbeat не обнаружен');
    echo "Worker heartbeat smoke: 4 passed; isolated filesystem.\n";
} finally {
    if (is_file($root . '/cms/cycles/workers/123.beat')) unlink($root . '/cms/cycles/workers/123.beat');
    foreach (array('/cms/cycles/workers','/cms/cycles','/cms','') as $suffix) if (is_dir($root . $suffix)) rmdir($root . $suffix);
}
