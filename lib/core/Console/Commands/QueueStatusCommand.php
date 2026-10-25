<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};
use MajorDoMo\Core\Core;

/** Диагностика заданий ядра. 2026-10-25 — ожидающие, работающие и неудачные задания из долговечного журнала. */
final class QueueStatusCommand extends Command
{
    public function __construct(private ?\Closure $factory = null) {}
    public function name(): string { return 'queue:status'; }
    public function description(): string { return 'Состояние очереди ядра без запуска и повторения заданий'; }
    public function options(): array { return array('failed' => 'Показать последние отказы', 'limit=' => 'Лимит отказов (1–200, по умолчанию 20)'); }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $limit = $input->integer('limit', 20, 1, 200);
        try {
            if ($this->factory) $queue = ($this->factory)();
            else { $context->migrations(); $queue = Core::queue()->runtime(); }
            $data = array('status' => $queue->status());
            if ($input->option('failed')) $data['failed'] = $queue->failed($limit);
        } catch (\Throwable $error) {
            $output->error('Не удалось прочитать очередь ядра. Проверьте подключение к БД и применение миграций.');
            $output->result(array('available' => false));
            return 1;
        }
        $output->title('Очередь ядра');
        $status = $data['status'];
        $output->table(array('Ожидают и работают', 'В работе', 'Отказы', 'Самое старое задание'), array(array(
            $status['pending'] ?? 0, $status['running'] ?? 0, $status['failed'] ?? 0, $status['oldest'] ?? '—',
        )));
        if (!empty($data['failed'])) $output->table(array('ID', 'Операция', 'Полоса', 'Попыток', 'Причина'), array_map(
            static fn($row) => array($row['ID'], $row['DATANAME'], $row['LANE'], $row['ATTEMPTS'], $row['LAST_ERROR']), $data['failed']));
        $output->result($data);
        return 0;
    }
}
