<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};
use MajorDoMo\Core\Maintenance\{AutomaticMaintenance, RoutineMaintenance};
use MajorDoMo\Core\Core;

/**
 * Управление автоматическим обслуживанием через общий CLI.
 * 2026-11-11 — расписание, результаты, отдельный этап и изоляция дочерней группы процессов.
 */
final class MaintenanceRunCommand extends Command
{
    public function __construct(private ?\Closure $factory = null) {}
    public function name(): string { return 'maintenance:run'; }
    public function description(): string { return 'Выполнить обслуживание по расписанию или отдельный этап'; }
    public function options(): array
    {
        return array('task=' => 'Этап: cleanup, backup, files, tables', 'status' => 'Показать последние результаты без подключения к БД',
            'force' => 'Запустить все этапы сейчас, независимо от расписания');
    }

    public function execute(Input $input, Output $output, Context $context): int
    {
        $task = $input->option('task', null);
        if ($task !== null && !in_array($task, AutomaticMaintenance::TASKS, true)) throw new \InvalidArgumentException('Неизвестный этап обслуживания');
        if ($input->option('status') && ($task !== null || $input->option('force'))) throw new \InvalidArgumentException('--status нельзя совмещать с запуском');
        if ($task !== null && $input->option('force')) throw new \InvalidArgumentException('--force применяется к расписанию, а не к отдельному этапу');
        $automatic = new AutomaticMaintenance($context->root);
        if ($input->option('status')) {
            $state = $automatic->status();
            $output->title('Автоматическое обслуживание');
            $output->table(array('Этап', 'Результат', 'Попытки', 'Ошибка'), array_map(static fn($task, $row) => array(
                $task, $row['status'] ?? '', $row['attempts'] ?? 0, $row['error'] ?? ''), array_keys($state), array_values($state)));
            $output->result(array('tasks' => $state));
            return 0;
        }
        $context->config();
        if ($task === null) {
            $cancelled = false;
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
                $cancel = static function () use (&$cancelled): void { $cancelled = true; };
                pcntl_signal(SIGTERM, $cancel);
                pcntl_signal(SIGINT, $cancel);
            }
            $data = $automatic->runDue(static function () use (&$cancelled): void {
                if ($cancelled) throw new \RuntimeException('Обслуживание прервано');
            }, force: (bool)$input->option('force'));
            $output->result($data);
            return count(array_filter($data['tasks'], static fn($row) => $row['status'] === 'failed')) > 0 ? 1 : 0;
        }
        // Отдельная группа нужна, чтобы таймаут остановил и запущенный mysqldump.
        if (function_exists('posix_setsid') && posix_getpgrp() !== getmypid() && posix_setsid() < 0) throw new \RuntimeException('Не удалось изолировать процесс обслуживания');
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function (): void { throw new \RuntimeException('Обслуживание остановлено супервизором'); });
        }
        if ($this->factory !== null) $service = ($this->factory)();
        else {
            // Как миграции, обслуживание использует штатный адаптер БД и системные
            // helpers; пользовательские методы и старую админку не запускает.
            $context->migrations();
            require_once $context->root . '/load_settings.php';
            $service = new RoutineMaintenance(Core::database(), $context->root);
        }
        $data = $service->run($task);
        $output->result($data);
        return 0;
    }
}
