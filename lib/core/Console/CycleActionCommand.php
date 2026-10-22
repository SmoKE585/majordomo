<?php

namespace MajorDoMo\Core\Console;

use MajorDoMo\Core\Cycles\CycleSupervisor;

/** Shared validation and rendering; actual lifecycle belongs to CycleManager. */
abstract class CycleActionCommand extends Command
{
    abstract protected function action(): string;
    public function name(): string { return 'cycles:' . $this->action(); }
    public function arguments(): array { return array('name' => 'Имя цикла с cycle_ или без него'); }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $manager = $context->cycles();
        $name = $context->cycleName((string)$input->argument('name'), $manager);
        $action = $this->action();
        if (in_array($action, array('start', 'restart', 'enable'), true) && CycleSupervisor::configurationDisabled($name)) {
            throw new \RuntimeException('Цикл отключён конфигурацией. Сначала включите WebSockets в общих настройках.');
        }
        $result = $manager->$action($name);
        if (!$result['ok']) throw new \RuntimeException('Команда не принята');
        $alive = $manager->isSupervisorRunning();
        $output->line('Запрос ' . $action . ' сохранён для ' . $name . '. ' . ($alive ? 'Ожидается применение супервизором.' : 'Ожидается запуск супервизора cycle.php.'));
        $output->line('Проверка: php majordomo cycles:status ' . $name);
        $output->result(array('accepted' => true, 'applied' => false, 'name' => $name, 'command' => $action, 'desired' => $manager->store()->desired($name), 'generation' => $manager->store()->generation($name), 'supervisor_running' => $alive));
        return 0;
    }
}
