<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\CycleActionCommand;

final class CyclesStopCommand extends CycleActionCommand
{
    protected function action(): string { return 'stop'; }
    public function description(): string { return 'Остановить цикл до явного запуска'; }
}
