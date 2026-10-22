<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};
use MajorDoMo\Core\Install\InstallState;

final class InstallCompleteCommand extends Command
{
    public function name(): string { return 'install:complete'; }
    public function description(): string { return 'Отметить установку завершённой без запуска мастера'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        if (!InstallState::markComplete($context->root, array('source' => 'cli'))) throw new \RuntimeException('Не удалось записать маркер установки; проверьте права на cms/modules_installed.');
        $output->line('Маркер установки отмечен завершённым.');
        $output->result(array('status' => InstallState::state($context->root)));
        return 0;
    }
}
