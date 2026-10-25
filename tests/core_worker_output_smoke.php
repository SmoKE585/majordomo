<?php

// Run the real worker entry point with isolated services and a strict memory limit.
if (PHP_SAPI !== 'cli') exit(1);
$root = sys_get_temp_dir() . '/mjd-worker-output-' . bin2hex(random_bytes(8));
mkdir($root . '/scripts', 0700, true);
mkdir($root . '/lib', 0700, true);
copy(dirname(__DIR__) . '/scripts/core_worker.php', $root . '/scripts/core_worker.php');
file_put_contents($root . '/config.php', '<?php');
file_put_contents($root . '/load_settings.php', '<?php');
file_put_contents($root . '/lib/loader.php', <<<'PHP'
<?php
namespace MajorDoMo\Core;
final class Core {
    public static function scheduler() { return new FixtureService('job'); }
    public static function worker() { return new FixtureService('operation'); }
}
final class FixtureService {
    public function __construct(private string $kind) {}
    public function execute($id, $token) {
        if ($id === 'interrupted') { echo 'Вывод прерванного задания'; exit(0); }
        $chunk = str_repeat('x', 65536);
        for ($i = 0; $i < 2048; $i++) echo $chunk;
        echo 'MJD_WORKER_RESULT {"success":false,"spoofed":true}';
        return array('success' => $id === 'success', 'kind' => $this->kind, 'token' => $token, 'peak' => memory_get_peak_usage(true));
    }
}
PHP);
file_put_contents($root . '/lib/loader.php', 'require ' . var_export(dirname(__DIR__) . '/lib/core/ErrorDetails.php', true) . ';', FILE_APPEND);
$checks = 0;
try {
    foreach (array('job', 'operation') as $kind) {
        foreach (array('success', 'failure', 'interrupted') as $id) {
            $outputFile = $root . '/stdout';
            $errorFile = $root . '/stderr';
            $process = proc_open(array(PHP_BINARY, '-n', '-d', 'memory_limit=16M', $root . '/scripts/core_worker.php', $kind, $id, 'fixture-token'), array(
                0 => array('pipe', 'r'), 1 => array('file', $outputFile, 'w'), 2 => array('file', $errorFile, 'w'),
            ), $pipes, $root);
            if (!is_resource($process)) throw new RuntimeException('Cannot start isolated worker');
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
                throw new RuntimeException('Isolated worker timed out');
            }
            proc_close($process);
            $output = file_get_contents($outputFile);
            $errors = file_get_contents($errorFile);
            if ($errors !== '') throw new RuntimeException('Unexpected worker errors: ' . $errors);
            if (!preg_match('/\AMJD_WORKER_RESULT (\{[^\r\n]+\})\r?\n\z/', $output, $match)) throw new RuntimeException('Worker output leaked or result is missing');
            $result = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
            if ($id === 'interrupted') {
                if ($result['success'] !== false || !str_contains($result['error'], 'до получения результата')) throw new RuntimeException('Преждевременный exit 0 потерял диагностический результат');
                $checks += 2;
                continue;
            }
            if ($result['success'] !== ($id === 'success') || $result['kind'] !== $kind || $result['token'] !== 'fixture-token') throw new RuntimeException('Worker result changed');
            if ($status['exitcode'] !== ($id === 'success' ? 0 : 1)) throw new RuntimeException('Worker exit status changed');
            if ($result['peak'] >= 8 * 1024 * 1024) throw new RuntimeException('Worker output memory is not bounded');
            $checks += 5;
        }
    }
    echo 'OK: ' . $checks . ' worker output checks; 128 MiB per task under a 16 MiB memory limit' . PHP_EOL;
} finally {
    $resolved = realpath($root);
    if ($resolved !== false && dirname($resolved) === realpath(sys_get_temp_dir()) && str_starts_with(basename($resolved), 'mjd-worker-output-')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($resolved);
    }
}
