<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\CycleActionCommand;

final class CyclesStartCommand extends CycleActionCommand
{
    protected function action(): string { return 'start'; }
    public function description(): string { return 'Запросить запуск цикла'; }
}
