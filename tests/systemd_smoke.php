<?php

/** Изолированные проверки формата systemd-юнита, wrapper и sudoers. */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

require dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Deploy\ServiceManager;
use MajorDoMo\Core\Deploy\SystemdSetup;
use MajorDoMo\Core\Deploy\SystemdUnit;

\MajorDoMo\Core\Config::boot(sys_get_temp_dir() . '/mjd-systemd-config-' . bin2hex(random_bytes(4)));

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    $checks++;
    echo 'PASS ' . $message . PHP_EOL;
}

$root = sys_get_temp_dir() . '/mjd-systemd-' . bin2hex(random_bytes(6));
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
    mkdir($root, 0777, true);
    $unit = new SystemdUnit(
        'majordomo-mjdm_smart15_usr',
        'mjdm_smart15_usr',
        'mjdm_smart15_usr',
        '/var/www/mjdm_smart15_usr/data/www/mjdm.smart155.ru',
        '/usr/bin/php8.4',
        "/bin/sh -c 'for i in $(seq 1 60); do mysqladmin ping -h 127.0.0.1 --silent && exit 0; sleep 2; done; exit 1'",
        '/home/mjdm_smart15_usr'
    );
    $rendered = $unit->render();
    check(str_contains($rendered, 'User=mjdm_smart15_usr'), 'юнит содержит User');
    check(str_contains($rendered, 'Group=mjdm_smart15_usr'), 'юнит содержит Group');
    check(str_contains($rendered, 'WorkingDirectory=/var/www/mjdm_smart15_usr/data/www/mjdm.smart155.ru'), 'юнит содержит WorkingDirectory');
    check(str_contains($rendered, 'ExecStart=/usr/bin/php8.4 /var/www/mjdm_smart15_usr/data/www/mjdm.smart155.ru/cycle.php'), 'юнит содержит ExecStart');
    check(str_contains($rendered, 'Restart=always') && str_contains($rendered, 'KillMode=control-group'), 'юнит перезапускает службу и группу');
    check(str_contains($rendered, 'ExecStartPre='), 'ExecStartPre сохранён');
    check(str_contains($rendered, 'Environment="HOME=/home/mjdm_smart15_usr"'), 'HOME передан службе');

    $wrapper = $unit->wrapper('/bin/systemctl');
    check(str_contains($wrapper, 'exec /bin/systemctl "$1" majordomo-mjdm_smart15_usr.service'), 'wrapper использует точный unit');
    check(str_contains($wrapper, 'restart|start|stop|status|is-active'), 'wrapper ограничивает команды');
    $sudoers = $unit->sudoers('/usr/local/sbin/majordomo-mjdm_smart15_usr');
    check(str_contains($sudoers, 'NOPASSWD: /usr/local/sbin/majordomo-mjdm_smart15_usr'), 'sudoers разрешает только wrapper');
    check(str_contains($sudoers, 'Defaults:mjdm_smart15_usr !requiretty'), 'sudoers не требует tty');

    $parsed = SystemdUnit::parseExisting("[Service]\nUser=site\nGroup=site\nExecStartPre=/bin/true\n");
    check($parsed['user'] === 'site' && $parsed['group'] === 'site' && $parsed['execStartPre'] === '/bin/true', 'parseExisting читает User/Group/ExecStartPre');
    check(str_contains(SystemdUnit::defaultExecStartPre('localhost'), 'systemctl is-active --quiet mysql'), 'ExecStartPre проверяет БД без MySQL-авторизации');

    $failed = false;
    try {
        new SystemdUnit('bad/name', 'user', 'user', $root, '/usr/bin/php');
    } catch (RuntimeException $error) {
        $failed = true;
    }
    check($failed, 'недопустимое имя службы отклоняется');

    // Файловая часть: временный unitDir, без systemctl и без root.
    $testUser = function_exists('posix_getpwuid') ? (string)(posix_getpwuid(posix_geteuid())['name'] ?? 'tester') : 'tester';
    if ($testUser === 'root') $testUser = function_exists('posix_getpwnam') && posix_getpwnam('nobody') ? 'nobody' : 'tester';
    $unitDir = $root . '/units';
    $wrapperDir = $root . '/bin';
    $sudoersDir = $root . '/sudoers';
    $setup = new SystemdSetup(new ServiceManager($root, 'majordomo-test', $testUser, '/usr/bin/php'), $unitDir, $wrapperDir, $sudoersDir);
    $temporaryUnit = $setup->plan('majordomo.service')['unit'];
    $written = $setup->writeUnit($temporaryUnit);
    check(is_file($written['path']) && str_contains((string)file_get_contents($written['path']), 'Description=MajorDoMo (' . $testUser . ')'), 'writeUnit пишет файл службы');
    check(str_contains((string)file_get_contents($written['path']), 'User=' . $testUser), 'writeUnit пишет пользователя');
    $writtenAgain = $setup->writeUnit($temporaryUnit);
    check($writtenAgain['backup'] !== '' && is_file($writtenAgain['backup']), 'повторная запись создаёт backup конфигурации');

    $dry = $setup->givePermissions(true);
    check(str_contains($dry['wrapper'], 'systemctl') && str_contains($dry['sudoers'], 'NOPASSWD'), 'givePermissions dry-run формирует wrapper и sudoers');
    check(!is_file($setup->wrapperPath('majordomo-test')), 'dry-run не пишет wrapper');
    check(is_array($setup->fixOwner(true)), 'fixOwner dry-run не падает на отсутствующих каталогах');

    echo 'Systemd smoke tests passed: ' . $checks . ' checks.' . PHP_EOL;
} finally {
    $remove($root);
}
