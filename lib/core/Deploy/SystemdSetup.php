<?php

namespace MajorDoMo\Core\Deploy;

use RuntimeException;

/**
 * Установка systemd-юнита и sudo-разрешения для управления им.
 *
 * Все файлы — конфигурация ОС, а не проекта. Перед перезаписью делается
 * резервная копия, а sudoers проверяется через `visudo -cf`.
 *
 * 2026-10-22 — добавлено для деплоя на FastPanel/systemd.
 */
final class SystemdSetup
{
    public function __construct(
        private ServiceManager $manager,
        private string $unitDir = '/etc/systemd/system',
        private string $wrapperDir = '/usr/local/sbin',
        private string $sudoersDir = '/etc/sudoers.d'
    ) {
        $this->unitDir = rtrim($unitDir, '/\\');
        $this->wrapperDir = rtrim($wrapperDir, '/\\');
        $this->sudoersDir = rtrim($sudoersDir, '/\\');
    }

    public function manager(): ServiceManager
    {
        return $this->manager;
    }

    public function unitPath(string $service = ''): string
    {
        $service = $this->normalizeService($service);
        return $this->unitDir . '/' . $service . '.service';
    }

    public function wrapperPath(string $service = ''): string
    {
        $service = $this->normalizeService($service);
        return $this->wrapperDir . '/' . $service;
    }

    public function sudoersPath(string $service = ''): string
    {
        $service = $this->normalizeService($service);
        return $this->sudoersDir . '/' . $service;
    }

    /**
     * @return array{unit:SystemdUnit,existing:string,inherited_from:string}
     */
    public function plan(string $inheritFrom = 'majordomo.service'): array
    {
        $existing = $this->readUnit($this->manager->serviceName());
        $source = $existing;
        $sourceName = $this->manager->serviceName();
        if ($source === '') {
            $source = $this->readUnit($inheritFrom);
            $sourceName = $inheritFrom;
        }
        $parsed = $source !== '' ? SystemdUnit::parseExisting($source) : array('execStartPre' => '', 'user' => '', 'group' => '');
        $execStartPre = trim((string)($parsed['execStartPre'] ?? ''));
        if ($execStartPre === '') {
            $execStartPre = SystemdUnit::defaultExecStartPre(\MajorDoMo\Core\Config::string('DB_HOST', '127.0.0.1'));
        }
        return array(
            'unit' => $this->manager->unitFor($execStartPre),
            'existing' => $existing,
            'inherited_from' => $source !== '' ? $sourceName : '',
        );
    }

    public function readUnit(string $service): string
    {
        $service = $this->normalizeService($service);
        $path = $this->unitPath($service);
        if (is_file($path)) {
            $contents = @file_get_contents($path);
            if ($contents !== false) return (string)$contents;
        }
        $result = $this->manager->run(array($this->manager->systemctlPath(), 'cat', $service . '.service'), 15);
        return $result['ok'] ? $result['out'] : '';
    }

    /** @return array{ok:bool,path:string,backup:string} */
    public function writeUnit(SystemdUnit $unit, bool $dryRun = false, ?callable $log = null): array
    {
        $path = $this->unitPath($unit->serviceName());
        $contents = $unit->render();
        if ($dryRun) return array('ok' => true, 'path' => $path, 'backup' => '');
        $backup = $this->backup($path);
        $this->writeAtomic($path, $contents, 0644);
        if ($log !== null) $log('unit', $path);
        return array('ok' => true, 'path' => $path, 'backup' => $backup);
    }

    /**
     * @return array{ok:bool,path:string,backup:string,wrapper:string,sudoers:string,test:string,test_ok:bool,test_err:string}
     */
    public function givePermissions(bool $dryRun = false, ?callable $log = null): array
    {
        $unit = $this->manager->unitFor();
        $systemctl = $this->manager->systemctlPath();
        $wrapperPath = $this->wrapperPath($unit->serviceName());
        $sudoersPath = $this->sudoersPath($unit->serviceName());
        $wrapper = $unit->wrapper($systemctl);
        $sudoers = $unit->sudoers($wrapperPath);
        $test = 'sudo -u ' . $this->manager->user() . ' sudo -n ' . $wrapperPath . ' status';
        if ($dryRun) {
            return array(
                'ok' => true,
                'path' => $wrapperPath,
                'backup' => '',
                'wrapper' => $wrapper,
                'sudoers' => $sudoers,
                'test' => $test,
                'test_ok' => false,
                'test_err' => 'dry-run',
            );
        }

        $wrapperBackup = $this->backup($wrapperPath);
        $this->writeAtomic($wrapperPath, $wrapper, 0755);
        if ($log !== null) $log('wrapper', $wrapperPath);

        $temporary = $sudoersPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $this->writeAtomic($temporary, $sudoers, 0440);
        $visudo = $this->findVisudo();
        $check = $this->manager->run(array($visudo, '-cf', $temporary), 15);
        if (!$check['ok']) {
            @unlink($temporary);
            throw new RuntimeException('visudo отклонил sudoers: ' . trim($check['err'] . ' ' . $check['out']));
        }
        $sudoersBackup = $this->backup($sudoersPath);
        if (!@rename($temporary, $sudoersPath)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось записать ' . $sudoersPath);
        }
        @chmod($sudoersPath, 0440);
        if ($log !== null) $log('sudoers', $sudoersPath);

        $testResult = $this->manager->run(array('sudo', '-u', $this->manager->user(), 'sudo', '-n', $wrapperPath, 'status'), 20);
        $permissionError = (bool)preg_match('/password|not allowed|may not run|is not allowed/i', $testResult['err'] . ' ' . $testResult['out']);
        return array(
            'ok' => true,
            'path' => $wrapperPath,
            'backup' => $wrapperBackup . ($sudoersBackup !== '' ? ',' . $sudoersBackup : ''),
            'wrapper' => $wrapper,
            'sudoers' => $sudoers,
            'test' => $test,
            'test_ok' => !$permissionError,
            'test_err' => trim($testResult['err'] . ' ' . $testResult['out']),
        );
    }

    /**
     * @return array{ok:bool,disabled_old:bool,started_new:bool,message:string}
     */
    public function enableUnit(SystemdUnit $unit, bool $disableOld = false, bool $dryRun = false, ?callable $log = null): array
    {
        $service = $unit->serviceName();
        $old = 'majordomo.service';
        if ($dryRun) {
            return array('ok' => true, 'disabled_old' => false, 'started_new' => false, 'message' => 'dry-run');
        }
        $systemctl = $this->manager->systemctlPath();
        $oldActive = $service !== 'majordomo' && $this->isActive($old);
        if ($oldActive && !$disableOld) {
            throw new RuntimeException('Старая служба ' . $old . ' активна. Добавьте --disable-old, иначе получится два супервизора.');
        }
        $oldStopped = false;
        if ($oldActive) {
            $stop = $this->manager->run(array($systemctl, 'disable', '--now', $old), 60);
            if (!$stop['ok']) throw new RuntimeException('Не удалось остановить ' . $old . ': ' . trim($stop['err']));
            $oldStopped = true;
            if ($log !== null) $log('old-disabled', $old);
        }

        $reload = $this->manager->run(array($systemctl, 'daemon-reload'), 30);
        if (!$reload['ok']) {
            if ($oldStopped) $this->manager->run(array($systemctl, 'start', $old), 60);
            throw new RuntimeException('systemctl daemon-reload: ' . trim($reload['err']));
        }
        $start = $this->manager->run(array($systemctl, 'enable', '--now', $service . '.service'), 90);
        if (!$start['ok'] || !$this->isActive($service)) {
            if ($oldStopped) $this->manager->run(array($systemctl, 'start', $old), 60);
            throw new RuntimeException('Не удалось запустить ' . $service . ': ' . trim($start['err'] . ' ' . $start['out']));
        }
        return array('ok' => true, 'disabled_old' => $oldStopped, 'started_new' => true, 'message' => 'Служба запущена');
    }

    /** @return array<int,string> */
    public function fixOwner(bool $dryRun = false, ?callable $log = null): array
    {
        $paths = array('cms', 'vendor', 'public/assets', 'node_modules');
        $done = array();
        foreach ($paths as $relative) {
            $path = $this->manager->root() . '/' . $relative;
            if (!file_exists($path)) continue;
            if ($dryRun) {
                $done[] = $path . ' -> ' . $this->manager->user() . ':' . $this->manager->group();
                continue;
            }
            $result = $this->manager->run(array('chown', '-R', $this->manager->user() . ':' . $this->manager->group(), $path), 300);
            if (!$result['ok']) throw new RuntimeException('chown ' . $path . ': ' . trim($result['err']));
            $done[] = $path;
            if ($log !== null) $log('chown', $path);
        }
        return $done;
    }

    private function isActive(string $service): bool
    {
        $result = $this->manager->run(array($this->manager->systemctlPath(), 'is-active', $this->normalizeService($service) . '.service'), 15);
        return $result['ok'] && in_array(trim($result['out']), array('active', 'activating'), true);
    }

    private function normalizeService(string $service): string
    {
        if ($service === '') return $this->manager->serviceName();
        $service = preg_replace('/\.service$/i', '', $service) ?? $service;
        return SystemdUnit::validateName($service, 'service');
    }

    private function backup(string $path): string
    {
        if (!is_file($path)) return '';
        $backup = $path . '.bak.' . date('YmdHis');
        if (!@copy($path, $backup)) throw new RuntimeException('Не удалось создать резервную копию ' . $path);
        return $backup;
    }

    private function writeAtomic(string $path, string $contents, int $mode): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог ' . $directory);
        }
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать ' . $path);
        }
        @chmod($temporary, $mode);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось заменить ' . $path);
        }
        @chmod($path, $mode);
    }

    private function findVisudo(): string
    {
        foreach (array('/usr/sbin/visudo', '/sbin/visudo', '/usr/bin/visudo') as $path) {
            if (is_file($path)) return $path;
        }
        return 'visudo';
    }
}
