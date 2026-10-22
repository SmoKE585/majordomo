<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};
use MajorDoMo\Core\Deploy\ServiceManager;
use MajorDoMo\Core\Deploy\SystemdSetup;

/**
 * Разрешить пользователю сайта управлять службой MajorDoMo без пароля.
 *
 * Создаёт wrapper в /usr/local/sbin и одну строку sudoers, а не выдаёт
 * пользователю произвольный systemctl.
 *
 * 2026-10-22 — добавлено для деплоя на FastPanel/systemd.
 */
final class SystemdGivePermissionsCommand extends Command
{
    public function name(): string { return 'systemd:give-permissions'; }
    public function description(): string { return 'Разрешить пользователю сайта restart/status службы без пароля'; }
    public function options(): array
    {
        return array(
            'service=' => 'Имя службы; по умолчанию majordomo-<user>',
            'user=' => 'Пользователь сайта; по умолчанию SUDO_USER или владелец проекта',
            'php=' => 'PHP для службы; по умолчанию PATH_TO_PHP',
            'dry-run' => 'Только показать wrapper и sudoers, ничего не записывать',
        );
    }

    public function execute(Input $input, Output $output, Context $context): int
    {
        $context->config();
        $dryRun = (bool)$input->option('dry-run');
        if (!$dryRun && (!function_exists('posix_geteuid') || posix_geteuid() !== 0)) {
            throw new \RuntimeException('Запустите от root: sudo php majordomo systemd:give-permissions');
        }
        $manager = new ServiceManager(
            $context->root,
            $this->optionString($input, 'service'),
            $this->optionString($input, 'user'),
            $this->optionString($input, 'php')
        );
        $setup = new SystemdSetup($manager);
        $result = $setup->givePermissions($dryRun, function (string $kind, string $path) use ($output): void {
            $output->line('Записан ' . $kind . ': ' . $path);
        });

        $output->title('systemd: права на управление службой');
        $output->line('Служба: ' . $manager->serviceName());
        $output->line('Пользователь: ' . $manager->user());
        $output->line('Wrapper: ' . $result['path']);
        $output->line('Sudoers: ' . $setup->sudoersPath($manager->serviceName()));
        if ($dryRun) {
            $output->section('Wrapper');
            $output->write($result['wrapper']);
            $output->section('Sudoers');
            $output->write($result['sudoers']);
            $output->warning('dry-run: файлы не изменены');
        } else {
            $output->success('Права записаны');
            $output->line('Проверка: ' . $result['test']);
            if (!$result['test_ok']) {
                $output->warning('Проверочная команда не подтвердила доступ: ' . $result['test_err']);
            }
        }
        $output->result(array(
            'service' => $manager->serviceName(),
            'user' => $manager->user(),
            'wrapper' => $result['path'],
            'sudoers' => $setup->sudoersPath($manager->serviceName()),
            'test' => $result['test'],
            'test_ok' => $result['test_ok'],
            'dry_run' => $dryRun,
        ));
        return 0;
    }

    private function optionString(Input $input, string $name): ?string
    {
        $value = $input->option($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
