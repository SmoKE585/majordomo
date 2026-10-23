<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};

/**
 * Применение всех отсутствующих миграций.
 * 2026-10-23 — --after-update ставит событие обновления в штатную очередь.
 *
 * 2026-10-22 — добавлено вместе с переходом на автообнаружение миграций.
 */
final class MigrationsRunCommand extends Command
{
    public function name(): string { return 'migrations:run'; }
    public function description(): string { return 'Применить все отсутствующие миграции базы данных'; }
    public function options(): array { return array('pending' => 'Только показать список без применения', 'after-update' => 'Уведомить подписчиков о завершении обновления файлов'); }

    public function execute(Input $input, Output $output, Context $context): int
    {
        $migrations = $context->migrations();
        if ($input->option('pending')) {
            $pending = array_values(array_filter($migrations->status(), static fn(array $row) => !$row['applied']));
            $output->title('Ожидающие миграции');
            $output->table(array('Версия', 'Описание'), array_map(static fn(array $row) => array($row['version'], $row['description']), $pending));
            $output->result(array('pending' => array_column($pending, 'version')));
            return 0;
        }

        $result = $migrations->run(function (string $version, string $description, int $index, int $total) use ($output): void {
            $output->line('[' . $index . '/' . $total . '] ' . $version . ($description !== '' ? ' — ' . $description : ''));
        });
        $output->title('Миграции базы данных');
        if ($result['applied'] === array()) {
            $output->success('Новых миграций нет');
        } else {
            $output->success('Применено миграций: ' . count($result['applied']) . ' (батч ' . $result['batch'] . ')');
        }
        if ($result['orphaned'] !== array()) {
            $output->warning('Записи без файлов миграций: ' . implode(', ', $result['orphaned']));
        }
        if ($input->option('after-update') && function_exists('processSubscriptionsSafe')) {
            try {
                if (!\processSubscriptionsSafe('SYSTEM_UPDATED')) $output->warning('Не удалось поставить уведомление об обновлении в очередь.');
            } catch (\Throwable $failure) {
                $output->warning('Миграции применены, но очередь уведомления об обновлении недоступна.');
                error_log('MajorDoMo SYSTEM_UPDATED: ' . get_class($failure));
            }
        }
        $output->result($result);
        return 0;
    }
}
