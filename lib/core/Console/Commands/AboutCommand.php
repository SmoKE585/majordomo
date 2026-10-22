<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};
use MajorDoMo\Core\Install\InstallState;

final class AboutCommand extends Command
{
    public function name(): string { return 'about'; }
    public function description(): string { return 'Сведения о системе без подключения к сервисам'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $data = array(
            'project' => 'MajorDoMo', 'php' => PHP_VERSION, 'php_binary' => PHP_BINARY,
            'os' => PHP_OS_FAMILY, 'root' => $context->root,
            'env_exists' => is_file($context->root . '/.env'),
            'installed' => InstallState::isComplete($context->root),
        );
        $rows = array();
        $labels = array('project' => 'Проект', 'php' => 'Версия PHP', 'php_binary' => 'PHP', 'os' => 'Система', 'root' => 'Каталог', 'env_exists' => '.env', 'installed' => 'Установлен');
        foreach ($data as $key => $value) $rows[] = array($labels[$key], is_bool($value) ? ($value ? 'yes' : 'no') : $value);
        $output->title('О системе');
        $output->table(array('Параметр', 'Значение'), $rows);
        $output->result($data);
        return 0;
    }
}
