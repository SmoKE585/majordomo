<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\CycleActionCommand;

final class CyclesEnableCommand extends CycleActionCommand
{
    protected function action(): string { return 'enable'; }
    public function description(): string { return 'Включить ранее отключённый цикл'; }
}
