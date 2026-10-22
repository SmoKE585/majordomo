<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};

final class SystemReloadCommand extends Command
{
    public function name(): string { return 'system:reload'; }
    public function description(): string { return 'Завершить супервизор и циклы для повторного запуска менеджером службы'; }
    public function options(): array { return array('managed' => 'Подтверждаю: служба настроена на автоматический перезапуск (например, systemd Restart=always)'); }
    public function execute(Input $input, Output $output, Context $context): int
    {
        if (!$input->option('managed')) throw new \InvalidArgumentException('Нужен --managed: без внешнего менеджера службы супервизор завершится и не запустится снова.');
        $manager = $context->cycles();
        if (!$manager->isSupervisorRunning()) throw new \RuntimeException('Нет живого супервизора. Запустите настроенную службу MajorDoMo.');
        $manager->requestReload();
        $output->line('Запрос reload сохранён. Супервизор завершит циклы и выйдет; повторный запуск выполняет менеджер службы.');
        $output->result(array('accepted' => true, 'applied' => false, 'reload' => $manager->store()->reloadRequested()));
        return 0;
    }
}
