<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};
use MajorDoMo\Core\Deploy\ServiceManager;
use MajorDoMo\Core\Deploy\SystemdSetup;

/**
 * Создать systemd-юнит MajorDoMo для текущего сайта.
 *
 * Запускается от root: `sudo php majordomo systemd:set --apply`.
 *
 * 2026-10-22 — добавлено для деплоя на FastPanel/systemd.
 */
final class SystemdSetCommand extends Command
{
    public function name(): string { return 'systemd:set'; }
    public function description(): string { return 'Создать systemd-юнит majordomo-<user> с путями и пользователем сайта'; }
    public function options(): array
    {
        return array(
            'service=' => 'Имя службы; по умолчанию majordomo-<user>',
            'user=' => 'Пользователь сайта; по умолчанию SUDO_USER или владелец проекта',
            'php=' => 'PHP для cycle.php; по умолчанию PATH_TO_PHP',
            'inherit-from=' => 'Откуда взять ExecStartPre; по умолчанию majordomo.service',
            'apply' => 'Записать юнит, daemon-reload и enable --now',
            'disable-old' => 'Остановить и отключить majordomo.service перед запуском нового юнита',
            'fix-owner' => 'Выставить владельца cms/vendor/public/assets/node_modules пользователю сайта',
            'dry-run' => 'Только показать юнит, ничего не записывать',
        );
    }

    public function execute(Input $input, Output $output, Context $context): int
    {
        $context->config();
        $dryRun = (bool)$input->option('dry-run');
        if (!$dryRun && (!function_exists('posix_geteuid') || posix_geteuid() !== 0)) {
            throw new \RuntimeException('Запустите от root: sudo php majordomo systemd:set');
        }
        $manager = new ServiceManager(
            $context->root,
            $this->optionString($input, 'service'),
            $this->optionString($input, 'user'),
            $this->optionString($input, 'php')
        );
        if (DIRECTORY_SEPARATOR === '/' && !is_executable($manager->phpBinary())) {
            throw new \RuntimeException('PHP для службы не найден или не исполняется: ' . $manager->phpBinary() . '. Укажите --php=/usr/bin/php.');
        }
        $setup = new SystemdSetup($manager);
        $inheritFrom = $this->optionString($input, 'inherit-from') ?: 'majordomo.service';
        $plan = $setup->plan($inheritFrom);
        $unit = $plan['unit'];

        $output->title('systemd: юнит MajorDoMo');
        $output->line('Служба: ' . $unit->serviceName());
        $output->line('Пользователь: ' . $manager->user() . ':' . $manager->group());
        $output->line('PHP: ' . $manager->phpBinary());
        $output->line('Каталог: ' . $manager->root());
        $output->line('ExecStartPre: ' . ($unit->execStartPre() !== '' ? $unit->execStartPre() : 'нет'));
        if ($plan['inherited_from'] !== '') $output->line('Наследует параметры из: ' . $plan['inherited_from']);

        if ($dryRun) {
            $output->section('Юнит');
            $output->write($unit->render());
            if ($input->option('fix-owner')) {
                $output->section('Владелец (dry-run)');
                foreach ($setup->fixOwner(true) as $line) $output->line($line);
            }
            $output->warning('dry-run: файлы не изменены');
            $output->result(array('unit' => $unit->render(), 'path' => $setup->unitPath($unit->serviceName()), 'dry_run' => true));
            return 0;
        }

        $written = $setup->writeUnit($unit, false, function (string $kind, string $path) use ($output): void {
            $output->line('Записан ' . $kind . ': ' . $path);
        });
        if ($input->option('fix-owner')) {
            $output->section('Владелец');
            foreach ($setup->fixOwner(false, function (string $kind, string $path) use ($output): void {
                $output->line('chown: ' . $path);
            }) as $line) $output->line($line);
        }

        if (!$input->option('apply')) {
            $output->success('Юнит записан: ' . $written['path']);
            $output->line('Дальше: sudo systemctl daemon-reload && sudo systemctl enable --now ' . $unit->serviceName() . '.service');
            $output->result(array('written' => $written, 'applied' => false));
            return 0;
        }

        try {
            $enabled = $setup->enableUnit($unit, (bool)$input->option('disable-old'), false, function (string $kind, string $value) use ($output): void {
                $output->line($kind . ': ' . $value);
            });
        } catch (\Throwable $error) {
            $output->error($error->getMessage());
            $output->warning('Юнит записан, но не запущен. Исправьте причину и повторите systemctl enable --now ' . $unit->serviceName() . '.service');
            throw $error;
        }
        $output->success('Служба ' . $unit->serviceName() . ' запущена');
        $output->result(array('written' => $written, 'applied' => true, 'enabled' => $enabled));
        return 0;
    }

    private function optionString(Input $input, string $name): ?string
    {
        $value = $input->option($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
