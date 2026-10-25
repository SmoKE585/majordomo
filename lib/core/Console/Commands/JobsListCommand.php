<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};
use MajorDoMo\Core\Core;

/** Просмотр таймеров. 2026-10-25 — состояния, попытки и ошибки без запуска команд. */
final class JobsListCommand extends Command
{
    public function __construct(private ?\Closure $factory = null) {}
    public function name(): string { return 'jobs:list'; }
    public function description(): string { return 'Отложенные задания, попытки и ошибки выполнения'; }
    public function options(): array { return array('status=' => 'waiting, running, retry, failed или done', 'page=' => 'Страница, по 50 заданий'); }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $status = (string)($input->option('status') ?? '');
        if (!in_array($status, array('', 'waiting', 'running', 'retry', 'failed', 'done'), true)) throw new \InvalidArgumentException('Неизвестное состояние задания.');
        if ($this->factory) $scheduler = ($this->factory)();
        else { $context->migrations(); $scheduler = Core::scheduler(); }
        $data = $scheduler->listing($status, '', $input->integer('page', 1, 1, 1000000));
        $output->table(array('ID', 'Название', 'Запуск', 'Состояние', 'Попыток', 'Ошибка'), array_map(
            static fn($row) => array($row['ID'], $row['TITLE'], $row['RUNTIME'], $row['STATE'], $row['ATTEMPTS'], $row['LAST_ERROR'] ?? ''), $data['rows']));
        $output->result($data);
        return 0;
    }
}
