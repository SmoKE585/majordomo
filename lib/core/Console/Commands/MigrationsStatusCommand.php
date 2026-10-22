<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};

/**
 * Состояние файловых миграций ядра.
 *
 * 2026-10-22 — добавлено вместе с переходом на автообнаружение миграций.
 */
final class MigrationsStatusCommand extends Command
{
    public function name(): string { return 'migrations:status'; }
    public function description(): string { return 'Применённые и ожидающие миграции базы данных'; }
    public function options(): array { return array('pending' => 'Показать только неприменённые миграции'); }

    public function execute(Input $input, Output $output, Context $context): int
    {
        $rows = $context->migrations()->status();
        $pendingOnly = (bool)$input->option('pending');
        if ($pendingOnly) {
            $rows = array_values(array_filter($rows, static fn(array $row) => !$row['applied']));
        }
        $pending = count(array_filter($rows, static fn(array $row) => !$row['applied']));
        $missing = count(array_filter($rows, static fn(array $row) => $row['missing_file']));
        $output->title('Миграции базы данных');
        $output->status($pending === 0 && $missing === 0 ? 'OK' : 'WARN',
            $pending === 0 ? 'Все миграции применены' : 'Ожидают применения: ' . $pending);
        if ($missing > 0) {
            $output->warning('Записей без файла миграции: ' . $missing . '. Это признак откатанного кода или удалённого файла.');
        }
        $output->table(array('Версия', 'Описание', 'Статус', 'Батч', 'Применена', 'мс'), array_map(static fn(array $row) => array(
            $row['version'],
            $row['description'] !== '' ? $row['description'] : ($row['missing_file'] ? 'файл отсутствует' : ''),
            $row['missing_file'] ? 'WARN' : ($row['applied'] ? 'OK' : 'WARN'),
            $row['batch'] > 0 ? $row['batch'] : '—',
            $row['applied_at'] !== '' ? $row['applied_at'] : '—',
            $row['duration_ms'] > 0 ? $row['duration_ms'] : '—',
        ), $rows));
        $output->result(array('migrations' => $rows, 'pending' => $pending, 'missing_files' => $missing));
        return $missing > 0 ? 1 : 0;
    }
}
