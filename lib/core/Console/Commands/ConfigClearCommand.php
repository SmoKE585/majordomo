<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};
use MajorDoMo\Core\ConfigCache;

final class ConfigClearCommand extends Command
{
    public function name(): string { return 'config:clear'; }
    public function description(): string { return 'Удалить кеш конфигурации'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        if (!ConfigCache::clear($context->root)) throw new \RuntimeException('Не удалось удалить кеш конфигурации');
        $output->success('Кеш конфигурации удалён.');
        $output->result(array('path' => ConfigCache::path($context->root)));
        return 0;
    }
}
