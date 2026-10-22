<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};
use MajorDoMo\Core\Config;

final class ConfigSnapshotCommand extends Command
{
    public function name(): string { return 'config:snapshot'; }
    public function description(): string { return 'Показать конфигурацию с замаскированными секретами'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $context->config();
        $snapshot = array();
        foreach (Config::schema() as $key => $definition) {
            if (!Config::has($key)) continue;
            $value = Config::get($key);
            $snapshot[$key] = array('type' => gettype($value), 'value' => ($definition['secret'] ?? false) ? '***' : $value);
        }
        ksort($snapshot);
        if (!$output->isJson()) $output->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        $output->result($snapshot);
        return 0;
    }
}
