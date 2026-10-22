<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};

final class ListCommand extends Command
{
    public function name(): string { return 'list'; }
    public function description(): string { return 'Список команд MajorDoMo'; }
    public function arguments(): array { return array('group?' => 'Фильтр по группе, например cycles'); }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $commands = array();
        foreach ($context->application->commands() as $command) {
            $group = $input->argument('group');
            if ($group !== null && !str_starts_with($command->name(), $group . ':') && $command->name() !== $group) continue;
            $commands[] = array('name' => $command->name(), 'description' => $command->description());
        }
        $output->line('MajorDoMo · php majordomo <команда> [опции]');
        $output->table(array('Команда', 'Описание'), array_map('array_values', $commands));
        $output->line('Справка: php majordomo help <команда> или <команда> --help');
        $output->result(array('commands' => $commands));
        return 0;
    }
}
