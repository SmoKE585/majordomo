<?php

/** CLI contract: temporary filesystem, injected runtime, no live DB/Redis. */
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('DB_HOST=');
putenv('DB_NAME=');
putenv('USE_REDIS=false');
putenv('DISABLE_WEBSOCKETS=1');
putenv('LOG_DIRECTORY=');
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Console\{Application, LogTail, Output};
use MajorDoMo\Core\Cycles\CycleStateStore;
use MajorDoMo\Core\Config;

$checks = 0;
function check(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($message);
}

function runCli(Application $application, array $arguments, bool $json = true): array
{
    $out = fopen('php://temp', 'w+');
    $err = fopen('php://temp', 'w+');
    try {
        if ($json) $arguments[] = '--json';
        $code = $application->run($arguments, $out, $err);
        rewind($out);
        rewind($err);
        $stdout = stream_get_contents($out);
        $stderr = stream_get_contents($err);
        $data = $json ? json_decode($stdout, true, 512, JSON_THROW_ON_ERROR) : null;
        if ($json) check(is_array($data) && $data['ok'] === ($code === 0) && $stderr === '', 'JSON envelope is valid and stdout is not mixed with text');
        return array($code, $data, $stdout, $stderr);
    } finally { fclose($out); fclose($err); }
}

function removeFixture(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->isDir() && !$item->isLink()) removeFixture($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/mjd-console-' . bin2hex(random_bytes(6));
mkdir($root);
try {
    $runtime = array('cycle_mqttrun' => (string)time(), 'cycle_mqttstatus' => 'running');
    $application = new Application($root, static function () use (&$runtime) { return $runtime; });
    [$code, $data] = runCli($application, array());
    check($code === 0 && count($data['data']['commands']) === 23, 'All concrete command classes are registered');
    [$code, $data] = runCli($application, array('list', 'cycles'));
    check($code === 0 && count($data['data']['commands']) === 6, 'Group filter lists cycle commands');
    foreach ($application->commands() as $command) {
        [$code, $data] = runCli($application, array($command->name(), '--help'));
        check($code === 0 && $data['data']['name'] === $command->name(), 'Help never executes ' . $command->name());
    }
    [$code] = runCli($application, array('help', 'cycles:start'));
    check($code === 0, 'help accepts command name');
    [$code, $data] = runCli($application, array('about'));
    check($code === 0 && $data['data']['env_exists'] === false, 'about works without .env');
    check(!is_dir($root . '/cms'), 'List, help and about do not boot configuration or write runtime files');

    foreach (array(
        array('missing'), array('cycles:start'), array('cycles:start', 'mqtt', '--bogus'),
        array('cycles:restart'), array('cycles:restart', 'mqtt', '--all'),
        array('cycles:restart', '--all', '--all'), array('cycles:stop', 'mqtt', 'extra'),
        array('cycles:stop', 'mqtt', '--json=false'), array('cycles:stop', '-x'),
    ) as $arguments) {
        [$code] = runCli($application, $arguments);
        check($code === 2, 'Invalid usage returns 2: ' . implode(' ', $arguments));
    }
    check(!is_dir($root . '/cms'), 'Invalid syntax does not boot services');
    $application->register(new class extends MajorDoMo\Core\Console\Command {
        public function name(): string { return 'fixture:warning'; }
        public function description(): string { return 'Fixture warning'; }
        public function execute(MajorDoMo\Core\Console\Input $input, Output $output, MajorDoMo\Core\Console\Context $context): int
        {
            fopen($context->root . '/missing/file.txt', 'rb');
            return 0;
        }
    });
    [$code] = runCli($application, array('fixture:warning'));
    check($code === 1, 'PHP warning becomes an error JSON instead of contaminating stdout');
    [$code, , $stdout, $stderr] = runCli($application, array('missing'), false);
    check($code === 2 && $stdout === '' && $stderr !== '', 'Human errors go to stderr');

    mkdir($root . '/scripts');
    mkdir($root . '/tools');
    mkdir($root . '/cms/debmes', 0777, true);
    foreach (array('mqtt', 'main', 'ping', 'websockets') as $name) file_put_contents($root . '/scripts/cycle_' . $name . '.php', '<?php');
    file_put_contents($root . '/.env', "APP_ENV=testing\nDB_HOST=\nDB_NAME=\nUSE_REDIS=false\nDB_PASSWORD=fixture-password\nLOG_DIRECTORY=\n");
    file_put_contents($root . '/config.php', '<?php // fixture');
    copy(dirname(__DIR__) . '/tools/config-migrate-runner.php', $root . '/tools/config-migrate-runner.php');
    [$code] = runCli($application, array('config:cache'));
    check($code === 0 && is_file($root . '/cms/cached/config.php'), 'Config cache is built only inside the fixture');
    Config::boot($root);
    check(Config::string('APP_ENV') === 'testing' && Config::string('DB_HOST') === '' && Config::string('DB_NAME') === '' && Config::get('USE_REDIS') === false, 'Actual configuration is testing and cannot connect to production');
    check(!isset($GLOBALS['db']), 'No legacy DB bootstrap');
    [$code, $data, $stdout] = runCli($application, array('config:snapshot'));
    check($code === 0 && $data['data']['DB_PASSWORD']['value'] === '***' && !str_contains($stdout, 'fixture-password'), 'Snapshot masks secrets in data and messages');

    $store = new CycleStateStore($root);
    foreach (array('stop' => 'stopped', 'start' => 'running', 'disable' => 'disabled', 'enable' => 'running') as $action => $desired) {
        [$code, $data] = runCli($application, array('cycles:' . $action, 'mqtt'));
        check($code === 0 && $store->desired('cycle_mqtt') === $desired, $action . ' persists desired state');
        check($data['data']['accepted'] && !$data['data']['applied'] && !$data['data']['supervisor_running'], 'Queued commands do not claim completed process changes');
    }
    $before = $store->generation('cycle_mqtt');
    [$code] = runCli($application, array('cycles:restart', 'cycle_mqtt'));
    check($code === 0 && $store->generation('cycle_mqtt') === $before + 1, 'Single restart increments generation');
    $store->set('cycle_ping', 'stopped');
    $store->set('cycle_main', 'disabled');
    $before = $store->generation('cycle_mqtt');
    [$code] = runCli($application, array('cycles:restart', '--all'));
    check($code === 0 && $store->generation('cycle_mqtt') === $before + 1, 'Restart all restarts running cycles');
    check($store->desired('cycle_ping') === 'stopped' && $store->desired('cycle_main') === 'disabled', 'Restart all preserves stopped and disabled states');
    [$code] = runCli($application, array('cycles:start', 'websockets'));
    check($code === 1, 'CLI cannot bypass configuration-disabled WebSockets');
    foreach (array('../mqtt', 'mqtt.php', 'unknown', 'mqtt;whoami') as $name) {
        $before = file_get_contents($root . '/cms/cycles/state.json');
        [$code] = runCli($application, array('cycles:stop', $name));
        check($code === 2 && file_get_contents($root . '/cms/cycles/state.json') === $before, 'Invalid or unknown cycle is rejected before state mutation');
    }
    [$code, $data] = runCli($application, array('cycles:status', 'mqtt'));
    check($code === 0 && $data['data']['cycles'][0]['status'] === 'running', 'Injected heartbeat produces actual running state');
    $runtime['cycle_mqttrun'] = (string)(time() - 1000);
    [$code, $data] = runCli($application, array('cycles:status', 'mqtt'));
    check($code === 0 && $data['data']['cycles'][0]['status'] === 'hang', 'Old heartbeat is not reported as running');
    [$code, $data] = runCli($application, array('cycles:status', '--offline'));
    check($code === 0 && $data['data']['offline'] && $data['data']['cycles'][0]['status'] === 'unknown', 'Offline mode clearly distinguishes unknown runtime');
    [$code] = runCli(new Application($root), array('cycles:status'));
    check($code === 1, 'Unavailable runtime produces error instead of made-up stopped states');

    [$code] = runCli($application, array('system:reload'));
    check($code === 2 && $store->reloadRequested() === 0, 'Reload requires managed-service acknowledgement');
    [$code] = runCli($application, array('system:reload', '--managed'));
    check($code === 1 && $store->reloadRequested() === 0, 'Reload refuses a missing supervisor');
    $store->touchSupervisor();
    [$code, $data] = runCli($application, array('system:reload', '--managed'));
    check($code === 0 && $store->reloadRequested() > 0 && !$data['data']['applied'], 'Managed reload records a request without claiming restart success');

    [$code] = runCli($application, array('install:complete'));
    [$status, $data] = runCli($application, array('install:status'));
    check($code === 0 && $status === 0 && $data['data']['complete'], 'Install marker commands use the fixture');
    [$code] = runCli($application, array('install:reset'));
    [$status, $data] = runCli($application, array('install:status'));
    check($code === 0 && !$data['data']['complete'], 'Reset only changes setup marker');
    [$code, $data, $stdout] = runCli($application, array('install:test-db'));
    check($code === 1 && !isset($data['data']['config']) && !str_contains($stdout, 'fixture-password'), 'DB diagnostic refuses empty test connection and omits credentials');
    [$code] = runCli($application, array('doctor'));
    check(in_array($code, array(0, 1), true), 'Doctor runs against isolated empty DB configuration');
    [$code] = runCli($application, array('config:doctor'));
    check($code === 1, 'Config doctor detects missing required database config');

    file_put_contents($root . '/legacy.php', "<?php\ndefine('DB_PASSWORD', 'migration-secret');\ndefine('PROJECT_TITLE', 'Migrated fixture');\n");
    $before = file_get_contents($root . '/.env');
    [$code, $data, $stdout] = runCli($application, array('config:migrate', '--from', $root . '/legacy.php'));
    check($code === 0 && !$data['data']['written'] && !str_contains($stdout, 'migration-secret'), 'Migration dry-run masks secrets');
    check(file_get_contents($root . '/.env') === $before && glob($root . '/.env.bak.*') === array(), 'Dry run creates no env or backup changes');
    [$code] = runCli($application, array('config:migrate', '--from=' . $root . '/legacy.php', '--write'));
    check($code === 2 && file_get_contents($root . '/.env') === $before, 'Existing env cannot be overwritten without --force');
    [$code, $data] = runCli($application, array('config:migrate', '--from=' . $root . '/legacy.php', '--write', '--force'));
    check($code === 0 && $data['data']['written'] && is_file($data['data']['backup']), 'Forced migration creates backup before writing fixture env');
    check(file_get_contents($data['data']['backup']) === $before, 'Backup contains original fixture env');
    [$code] = runCli($application, array('config:cache'));
    check($code === 0 && is_file($root . '/cms/cached/config.php'), 'Cache rebuild also works after configuration was already booted');
    [$code] = runCli($application, array('config:clear'));
    check($code === 0 && !is_file($root . '/cms/cached/config.php'), 'Config clear removes only the fixture cache');
    file_put_contents($root . '/noisy.php', "<?php echo 'private-raw-output';");
    [$code, , $stdout] = runCli($application, array('config:migrate', '--from=' . $root . '/noisy.php'));
    check($code === 1 && !str_contains($stdout, 'private-raw-output'), 'Unexpected legacy stdout is never exposed');

    $day = date('Y-m-d');
    mkdir($root . '/cms/debmes/' . $day);
    $log = $root . '/cms/debmes/' . $day . '/errors.log';
    file_put_contents($log, "first\nsecond\nthird\n");
    [$code, $data] = runCli($application, array('logs:tail', '--lines=2'));
    check($code === 0 && $data['data']['lines'] === array('second', 'third'), 'Log tail returns requested lines');
    file_put_contents($log, "first\n\n\n");
    [$code, $data] = runCli($application, array('logs:tail', '--lines', '2'));
    check($code === 0 && $data['data']['lines'] === array('', ''), 'Tail preserves final blank lines and accepts separate option values');
    file_put_contents($log, "first\nsecond\nthird\n");
    foreach (array(array('--lines=0'), array('--lines=-1'), array('--lines=1001'), array('--lines=bogus'), array('--date=2026-02-30'), array('--lines'), array('../.env'), array('--follow')) as $options) {
        [$code] = runCli($application, array_merge(array('logs:tail'), $options));
        check($code === 2, 'Invalid log input or JSON follow is rejected');
    }
    file_put_contents($root . '/cms/debmes/log_' . $day . '-cycle_mqtt.php.txt', "cycle output\n");
    [$code, $data] = runCli($application, array('logs:tail', 'mqtt', '--cycle'));
    check($code === 0 && $data['data']['lines'] === array('cycle output'), 'Cycle stdout filename follows Threads convention');
    [$code] = runCli($application, array('logs:tail', 'missing'));
    check($code === 1, 'Missing journal produces explicit error');
    $large = $root . '/cms/debmes/large.log';
    file_put_contents($large, str_repeat('x', 5 * 1024 * 1024));
    $tail = new LogTail();
    $last = $tail->lastLines($large, 10);
    check($last['truncated'] && strlen($last['lines'][0]) <= 4 * 1024 * 1024, 'Initial log read is bounded');

    $stream = fopen('php://temp', 'w+');
    $errors = fopen('php://temp', 'w+');
    $output = new Output($stream, $errors);
    $last = $tail->lastLines($log, 1);
    $step = 0;
    try {
        $tail->follow(static function () use (&$step, $log) {
            $step++;
            if ($step === 2) file_put_contents($log, "appended\n", FILE_APPEND);
            if ($step === 3) file_put_contents($log, "short\n");
            if ($step === 4) { rename($log, $log . '.old'); file_put_contents($log, "rotated\n"); }
            if ($step === 5) throw new RuntimeException('fixture-follow-done');
            return $log;
        }, $last['offset'], $last['identity'], $output);
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'fixture-follow-done', 'Follow stops at test boundary');
    }
    rewind($stream);
    $text = stream_get_contents($stream);
    fclose($stream);
    fclose($errors);
    check($text === "appended\nshort\nrotated\n", 'Follow handles append, truncation and rotation');

    $nextLog = $root . '/cms/debmes/next-day.log';
    file_put_contents($nextLog, "new day\n");
    $stream = fopen('php://temp', 'w+');
    $errors = fopen('php://temp', 'w+');
    $step = 0;
    $last = $tail->lastLines($log, 1);
    try {
        $tail->follow(static function () use (&$step, $log, $nextLog) {
            $step++;
            if ($step === 4) throw new RuntimeException('fixture-follow-done');
            return $step < 3 ? $log : $nextLog;
        }, $last['offset'], $last['identity'], new Output($stream, $errors));
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'fixture-follow-done', 'Date-follow stops at test boundary');
    }
    rewind($stream);
    check(stream_get_contents($stream) === "new day\n", 'Follow reads new daily path from its beginning');
    fclose($stream);
    fclose($errors);

    file_put_contents($root . '/tools/config-audit.php', '<?php echo json_encode(array("reference_count" => 1, "files_with_references" => 1, "arguments" => array_slice($argv, 1))); exit(in_array("--fail-on-legacy", $argv, true) ? 1 : 0);');
    [$code, $data] = runCli($application, array('config:audit', '--no-log', '--fail-on-legacy'));
    check($code === 1 && in_array('--no-log', $data['data']['arguments'], true), 'Audit forwards flags and preserves nonzero exit code');

    // Block lock creation: no false positive command result is allowed.
    unlink($root . '/cms/cycles/state.lock');
    mkdir($root . '/cms/cycles/state.lock');
    [$code, $data] = runCli($application, array('cycles:stop', 'mqtt'));
    check($code === 1 && !$data['ok'], 'Unwritable state lock fails the command');
    rmdir($root . '/cms/cycles/state.lock');
    unlink($root . '/cms/cycles/state.json');
    mkdir($root . '/cms/cycles/state.json');
    [$code] = runCli($application, array('cycles:stop', 'mqtt'));
    check($code === 1, 'Failed atomic state write fails the command');

    // Compatibility wrappers are exercised only in help mode: never boot live config.
    $runner = new MajorDoMo\Core\Console\PhpToolRunner();
    foreach (array('config', 'install') as $group) {
        $result = $runner->run(dirname(__DIR__) . '/tools/' . $group . '.php', array('help'), dirname(__DIR__));
        check($result['code'] === 0 && $result['stderr'] === '' && str_contains($result['stdout'], $group . ':'), 'Legacy adapter lists ' . $group . ' commands');
    }
    $result = $runner->run(dirname(__DIR__) . '/majordomo', array('cycles:restart', '--help', '--json'), $root);
    check($result['code'] === 0 && json_decode($result['stdout'], true)['data']['name'] === 'cycles:restart', 'Root CLI entry works from another cwd');
    echo 'OK: ' . $checks . " CLI checks (isolated filesystem, no database or Redis).\n";
} finally {
    removeFixture($root);
}
