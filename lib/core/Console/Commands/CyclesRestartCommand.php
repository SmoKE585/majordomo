<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{CycleActionCommand, Input, Output, Context};

final class CyclesRestartCommand extends CycleActionCommand
{
    protected function action(): string { return 'restart'; }
    public function description(): string { return 'Перезапуск цикла или всех циклов с desired=running'; }
    public function arguments(): array { return array('name?' => 'Имя цикла; обязательно без --all'); }
    public function options(): array { return array('all' => 'Перезапустить все running-циклы, сохранив stopped/disabled'); }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $all = (bool)$input->option('all');
        if ($all === ($input->argument('name') !== null)) throw new \InvalidArgumentException('Укажите либо имя цикла, либо --all');
        if (!$all) return parent::execute($input, $output, $context);
        $manager = $context->cycles();
        $result = $manager->restartAll();
        $output->success($result['cycles'] > 0 ? 'Запрос перезапуска сохранён. Циклов: ' . $result['cycles'] . '.' : 'Нет циклов с желаемым состоянием running.');
        $output->info($result['supervisor_running'] ? 'Ожидается применение супервизором.' : 'Супервизор не запущен: запрос ожидает его запуска.');
        $output->result(array('accepted' => true, 'applied' => false, 'cycles' => $result['cycles'], 'supervisor_running' => $result['supervisor_running']));
        return 0;
    }
}
