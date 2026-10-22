<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};
use MajorDoMo\Core\Install\InstallState;

final class InstallStatusCommand extends Command
{
    public function name(): string { return 'install:status'; }
    public function description(): string { return 'Состояние установки и маркер мастера'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $state = InstallState::state($context->root);
        $data = $state + array('marker' => InstallState::markerPath($context->root));
        $rows = array();
        foreach ($data as $key => $value) if (is_scalar($value)) $rows[] = array($key, is_bool($value) ? ($value ? 'yes' : 'no') : (string)$value);
        $output->table(array('Параметр', 'Значение'), $rows);
        $output->result($data);
        return 0;
    }
}
