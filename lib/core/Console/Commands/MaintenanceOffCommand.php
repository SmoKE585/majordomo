<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};
use MajorDoMo\Core\Cycles\CycleManager;
use MajorDoMo\Core\Maintenance\MaintenanceMode;

/** Выключение режима обслуживания. 2026-10-22 — добавлено для деплоя. */
final class MaintenanceOffCommand extends Command
{
    public function name(): string { return 'maintenance:off'; }
    public function description(): string { return 'Выключить режим обслуживания и снять hold циклов'; }

    public function execute(Input $input, Output $output, Context $context): int
    {
        $mode = new MaintenanceMode($context->root);
        $wasActive = $mode->isActive();
        $removed = $mode->disable();
        $released = (new CycleManager($context->root))->releaseHold();
        $output->title('Режим обслуживания');
        if ($removed) $output->success('Выключен');
        else $output->info('Режим обслуживания не был включён');
        if ($released) $output->success('Hold циклов снят');
        $output->result(array('was_active' => $wasActive, 'removed' => $removed, 'cycles_hold_released' => $released));
        return 0;
    }
}
