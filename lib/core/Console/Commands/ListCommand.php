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
        $output->title('MajorDoMo');
        $output->muted('php majordomo <команда> [опции]');
        $groups = array();
        foreach ($commands as $command) {
            $parts = explode(':', $command['name'], 2);
            $groups[count($parts) === 1 ? 'main' : $parts[0]][] = array_values($command);
        }
        $labels = array('main' => 'Основные', 'config' => 'Конфигурация', 'cycles' => 'Циклы', 'install' => 'Установка', 'logs' => 'Журналы', 'system' => 'Система', 'users' => 'Пользователи');
        if (isset($groups['main'])) $groups = array('main' => $groups['main']) + $groups;
        foreach ($groups as $group => $rows) {
            $output->section(($labels[$group] ?? $group) . ($group === 'main' ? '' : ' · ' . $group));
            $output->table(array('Команда', 'Описание'), $rows, false);
        }
        $output->line();
        $output->muted('Справка: php majordomo help <команда>');
        $output->result(array('commands' => $commands));
        return 0;
    }
}
