<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};
use MajorDoMo\Core\Install\InstallState;

final class InstallResetCommand extends Command
{
    public function name(): string { return 'install:reset'; }
    public function description(): string { return 'Повторно открыть мастер установки без удаления данных'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        if (!InstallState::reset($context->root, 'cli reset')) throw new \RuntimeException('Не удалось записать маркер установки; проверьте права на cms/modules_installed.');
        $output->line('Мастер установки снова включён. База данных не изменена.');
        $output->result(array('status' => InstallState::state($context->root)));
        return 0;
    }
}
