<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};

final class ModesListCommand extends Command
{
    public function name(): string { return 'modes:list'; }
    public function description(): string { return 'Режимы дома: состояние и автоматика'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $rows = array_map(static fn($mode) => array_intersect_key($mode, array_flip(array('ID', 'NAME', 'TITLE', 'ACTIVE', 'ENABLED', 'CHANGED_AT', 'SOURCE', 'LAST_ERROR'))), $context->modes()->all());
        $output->title('Режимы');
        $output->table(array('Код', 'Название', 'Состояние', 'Автоматика'), array_map(static fn($mode) => array($mode['NAME'], $mode['TITLE'], $mode['ACTIVE'] ? 'Включён' : 'Выключен', $mode['ENABLED'] ? 'Включена' : 'Пауза'), $rows));
        $output->result(array('modes' => $rows));
        return 0;
    }
}
