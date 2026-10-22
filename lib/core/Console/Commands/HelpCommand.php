<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};

final class HelpCommand extends Command
{
    public function name(): string { return 'help'; }
    public function description(): string { return 'Справка по команде'; }
    public function arguments(): array { return array('command?' => 'Имя команды; по умолчанию list'); }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $context->application->describe($context->application->command($input->argument('command', 'list')), $output);
        return 0;
    }
}
