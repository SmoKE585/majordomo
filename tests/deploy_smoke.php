<?php

/** Изолированные проверки команд деплоя и preflight без рабочей БД. */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

require dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Deploy\CommandRunner;
use MajorDoMo\Core\Deploy\Deployment;
use MajorDoMo\Core\Deploy\ServiceManager;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    $checks++;
    echo 'PASS ' . $message . PHP_EOL;
}

$root = sys_get_temp_dir() . '/mjd-deploy-' . bin2hex(random_bytes(6));
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: array() as $item) {
        if ($item === '.' || $item === '..') continue;
        $remove($path . '/' . $item);
    }
    rmdir($path);
};

try {
    mkdir($root . '/vendor', 0777, true);
    mkdir($root . '/cms', 0777, true);
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/composer.lock', '{}');
    file_put_contents($root . '/vendor/autoload.php', "<?php\nnamespace Bluerhinos { class phpMQTT {} }\nnamespace PHPMailer\\PHPMailer { class PHPMailer {} }\n");

    putenv('DEPLOY_ASSETS=never');
    putenv('DEPLOY_COMPOSER=' . PHP_BINARY);
    Config::boot($root);

    $runner = new CommandRunner($root);
    $direct = $runner->run(array(PHP_BINARY, '-r', 'echo "ok";'), 10);
    check($direct['ok'] && trim($direct['out']) === 'ok', 'CommandRunner выполняет команду без shell');
    $version = $runner->version(PHP_BINARY, '--version');
    check($version['ok'] && $version['version'] !== '', 'CommandRunner читает версию инструмента');
    $timeout = $runner->run(array(PHP_BINARY, '-r', 'sleep(3);'), 1);
    check($timeout['timed_out'] && !$timeout['ok'], 'CommandRunner завершает команду по таймауту');

    $testUser = function_exists('posix_getpwuid') ? (string)(posix_getpwuid(posix_geteuid())['name'] ?? 'tester') : 'tester';
    if ($testUser === 'root') $testUser = function_exists('posix_getpwnam') && posix_getpwnam('nobody') ? 'nobody' : '';
    if ($testUser === '') {
        check(true, 'preflight пропущен: тест запущен от root без nobody');
    } else {
        $service = new ServiceManager($root, 'majordomo-deploy-test', $testUser, PHP_BINARY);
        $deployment = new Deployment($root, $runner, $service);
        $checksList = $deployment->preflight();
        $fatal = array_values(array_filter($checksList, static fn(array $check) => $check['fatal'] && !$check['ok']));
        foreach ($fatal as $check) echo 'FATAL: ' . $check['title'] . ' — ' . $check['details'] . PHP_EOL;
        check($fatal === array(), 'preflight не имеет фатальных ошибок при DEPLOY_ASSETS=never');
        check($deployment->preflightOk(), 'preflightOk подтверждает готовность');
        $autoload = $deployment->verifyAutoload();
        check($autoload['ok'] && !in_array(false, $autoload['classes'], true), 'health-check видит phpMQTT и PHPMailer');
        $assets = $deployment->buildAssets();
        check($assets['ok'] && !$assets['build'], 'DEPLOY_ASSETS=never не запускает npm');
        $deployment->writeState(array('state' => 'test'));
        check(($deployment->readState()['state'] ?? '') === 'test', 'состояние деплоя сохраняется и читается');
    }

    echo 'Deploy smoke tests passed: ' . $checks . ' checks.' . PHP_EOL;
} finally {
    $remove($root);
}
