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
        foreach ($checks as $group) {
            $output->line($group['title']);
            foreach ($group['items'] as $item) {
                $output->line('[' . strtoupper($item['status'] ?? 'info') . '] ' . $item['label'] . ': ' . ($item['value'] ?? ''));
                if (!empty($item['hint'])) $output->line('  ' . $item['hint']);
            }
        }
        $output->line('Ошибок: ' . $summary['fail'] . '; предупреждений: ' . $summary['warn'] . '.');
        $output->result(array('checks' => $checks, 'summary' => $summary));
        return $summary['blocking'] > 0 || $summary['fail'] > 0 ? 1 : 0;
    }
}
