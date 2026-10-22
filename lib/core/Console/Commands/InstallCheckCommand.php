<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};
use MajorDoMo\Core\Install\InstallEnvironment;

final class InstallCheckCommand extends Command
{
    public function name(): string { return 'install:check'; }
    public function description(): string { return 'Проверить окружение и БД перед установкой'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $context->config();
        $environment = new InstallEnvironment($context->root);
        $checks = $environment->checks();
        $summary = $environment->summary();
        $output->title('Диагностика системы');
        foreach ($checks as $group) {
            $output->section($group['title']);
            foreach ($group['items'] as $item) {
                $status = $item['status'] ?? 'info';
                $output->status($status, $item['label'] . ': ' . ($item['value'] ?? ''));
                if (!empty($item['hint']) && ($input->option('verbose') || in_array($status, array('fail', 'warn'), true))) $output->muted('    ' . $item['hint']);
            }
        }
        $output->line();
        $output->status($summary['fail'] > 0 ? 'ERROR' : ($summary['warn'] > 0 ? 'WARN' : 'OK'), 'Ошибок: ' . $summary['fail'] . ' · Предупреждений: ' . $summary['warn'] . ' · Успешно: ' . $summary['ok']);
        if (!$input->option('verbose')) $output->muted('Все подсказки: добавьте --verbose');
        $output->result(array('checks' => $checks, 'summary' => $summary));
        return $summary['blocking'] > 0 || $summary['fail'] > 0 ? 1 : 0;
    }
}
