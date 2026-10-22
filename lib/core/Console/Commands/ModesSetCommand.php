<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};
use MajorDoMo\Core\Modes\Rules;

final class ModesSetCommand extends Command
{
    public function name(): string { return 'modes:set'; }
    public function description(): string { return 'Переключить режим, сохраняя работу автоматических правил'; }
    public function arguments(): array { return array('name' => 'Постоянный код режима', 'state' => 'on или off'); }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $name = (string)$input->argument('name'); $state = (string)$input->argument('state');
        if (!Rules::validName($name) || !in_array($state, array('on', 'off'), true)) throw new \InvalidArgumentException('Использование: modes:set <код> <on|off>');
        $manager = $context->modes();
        $changed = $manager->set($name, $state === 'on', 'cli');
        $mode = $manager->get($name);
        $output->success($mode['TITLE'] . ': ' . ($mode['ACTIVE'] ? 'включён' : 'выключен'));
        if ($mode['LAST_ERROR'] !== '') $output->warning('Ошибка действия: ' . $mode['LAST_ERROR']);
        $output->result(array('name' => $name, 'active' => (bool)$mode['ACTIVE'], 'changed' => $changed, 'automation' => (bool)$mode['ENABLED'], 'error' => $mode['LAST_ERROR']));
        return $mode['LAST_ERROR'] !== '' ? 1 : 0;
    }
}
