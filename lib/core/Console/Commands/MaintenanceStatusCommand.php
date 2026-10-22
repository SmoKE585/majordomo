<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};
use MajorDoMo\Core\Maintenance\MaintenanceMode;

/** Состояние режима обслуживания. 2026-10-22 — добавлено для деплоя. */
final class MaintenanceStatusCommand extends Command
{
    public function name(): string { return 'maintenance:status'; }
    public function description(): string { return 'Показать состояние режима обслуживания'; }

    public function execute(Input $input, Output $output, Context $context): int
    {
        $mode = new MaintenanceMode($context->root);
        $status = $mode->status();
        $output->title('Режим обслуживания');
        if (!empty($status['error'])) $output->warning($status['error']);
        if (empty($status['active'])) {
            $output->info($status['expired'] ? 'Истёк; сайт доступен' : 'Выключен');
        } else {
            $output->status(!empty($status['failed']) ? 'WARN' : 'OK',
                (!empty($status['failed']) ? 'Ошибка обновления, режим удерживается' : 'Включён') . ': ' . $status['reason']);
            if ($status['message'] !== '') $output->line($status['message']);
            $output->line($status['expires_at'] > 0 ? 'До: ' . date('Y-m-d H:i:s', $status['expires_at']) : 'До ручного выключения');
        }
        $output->result($status);
        return 0;
    }
}
