<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};
use MajorDoMo\Core\Maintenance\MaintenanceMode;

/**
 * Включение режима обслуживания без БД.
 *
 * 2026-10-22 — добавлено для ручных работ и деплоя.
 */
final class MaintenanceOnCommand extends Command
{
    public function name(): string { return 'maintenance:on'; }
    public function description(): string { return 'Включить режим обслуживания: сайт и API отвечают 503'; }
    public function options(): array
    {
        return array(
            'reason=' => 'Причина: system_update, manual (по умолчанию manual)',
            'ttl=' => 'Срок в секундах; 0 — до ручного выключения',
            'message=' => 'Сообщение для посетителей',
        );
    }

    public function execute(Input $input, Output $output, Context $context): int
    {
        $reason = trim((string)$input->option('reason', 'manual'));
        if ($reason === '') $reason = 'manual';
        $ttl = $input->option('ttl') !== false ? $input->integer('ttl', MaintenanceMode::DEFAULT_TTL, 0, 7 * 24 * 3600) : MaintenanceMode::DEFAULT_TTL;
        $message = trim((string)$input->option('message', ''));
        $actor = getenv('SUDO_USER') ?: (getenv('USER') ?: 'cli');
        $mode = new MaintenanceMode($context->root);
        $status = $mode->enable($reason, $ttl, (string)$actor, array('message' => $message));
        $output->title('Режим обслуживания');
        $output->success('Включён: ' . $status['reason']);
        $output->line('Файл: ' . $mode->path());
        $output->line($status['expires_at'] > 0 ? 'До: ' . date('Y-m-d H:i:s', $status['expires_at']) : 'До ручного выключения');
        $output->result($status);
        return 0;
    }
}
