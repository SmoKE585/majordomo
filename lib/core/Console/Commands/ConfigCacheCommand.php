<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};
use MajorDoMo\Core\{ConfigCache, ConfigSchema, Env};

final class ConfigCacheCommand extends Command
{
    public function name(): string { return 'config:cache'; }
    public function description(): string { return 'Пересобрать кеш конфигурации'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        if (!ConfigCache::clear($context->root)) throw new \RuntimeException('Не удалось удалить кеш конфигурации');
        $fingerprint = ConfigCache::fingerprint($context->root, ConfigSchema::all());
        $values = array_replace(Env::parseFile($context->root . '/.env'), Env::parseFile($context->root . '/.env.local'));
        ConfigCache::write($context->root, $values, $fingerprint);
        if (ConfigCache::read($context->root, $fingerprint) === null) throw new \RuntimeException('Кеш не записан. Проверьте права на cms/cached.');
        $output->line('Кеш конфигурации пересобран.');
        $output->result(array('path' => ConfigCache::path($context->root)));
        return 0;
    }
}
