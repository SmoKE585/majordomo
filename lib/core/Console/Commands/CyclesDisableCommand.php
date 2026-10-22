<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\CycleActionCommand;

final class CyclesDisableCommand extends CycleActionCommand
{
    protected function action(): string { return 'disable'; }
    public function description(): string { return 'Отключить цикл с сохранением состояния'; }
}
