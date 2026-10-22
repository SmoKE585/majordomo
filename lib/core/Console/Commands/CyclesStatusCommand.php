<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};
use MajorDoMo\Core\Cycles\CycleStateStore;

final class CyclesStatusCommand extends Command
{
    public function name(): string { return 'cycles:status'; }
    public function description(): string { return 'Желаемые и фактические состояния циклов'; }
    public function arguments(): array { return array('name?' => 'Имя цикла с cycle_ или без него'); }
    public function options(): array { return array('offline' => 'Без БД/Redis: фактическое состояние unknown'); }
    public function execute(Input $input, Output $output, Context $context): int
    {
        // Validate names before contacting runtime services.
        $manager = $context->cycles();
        $names = $input->argument('name') === null ? $manager->names() : array($context->cycleName($input->argument('name'), $manager));
        $store = $manager->store();
        $supervisor = $store->snapshot();
        $alive = $store->isSupervisorRunning();
        $offline = (bool)$input->option('offline');
        if (!$offline) $manager = $context->cycles(true);
        $states = array();
        foreach ($names as $name) {
            $state = $offline
                ? array('name' => $name, 'desired' => $store->desired($name), 'status' => 'unknown', 'online' => null, 'updated' => null, 'generation' => $store->generation($name))
                : $manager->state($name);
            $states[] = $state;
        }
        $output->line('Супервизор: ' . ($alive ? 'работает' : 'нет свежего heartbeat'));
        $output->table(array('Цикл', 'Желаемое', 'Фактическое', 'Heartbeat', 'Поколение'), array_map(static fn(array $state) => array(
            $state['name'], $state['desired'], $state['status'],
            empty($state['updated']) ? '—' : date('Y-m-d H:i:s', $state['updated']), $state['generation'],
        ), $states));
        $output->result(array('offline' => $offline, 'supervisor' => array('running' => $alive, 'pid' => $supervisor['supervisor_pid'], 'heartbeat' => $supervisor['supervisor_heartbeat']), 'cycles' => $states));
        return 0;
    }
}
