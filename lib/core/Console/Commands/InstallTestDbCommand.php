<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};
use MajorDoMo\Core\Install\InstallEnvironment;

final class InstallTestDbCommand extends Command
{
    public function name(): string { return 'install:test-db'; }
    public function description(): string { return 'Проверить подключение к БД, обязательные таблицы и администратора'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $context->config();
        $status = (new InstallEnvironment($context->root))->databaseStatus();
        unset($status['config']); // Includes the database password.
        $output->line(($status['ok'] ? '[OK] ' : '[FAIL] ') . $status['message']);
        $output->line('Сервер: ' . $status['server'] . '; таблиц: ' . count($status['tables']));
        if ($status['missing'] !== array()) $output->line('Нет обязательных таблиц: ' . implode(', ', $status['missing']));
        $output->line('Администратор: ' . ($status['admin_exists'] === null ? 'unknown' : ($status['admin_exists'] ? 'yes' : 'no')));
        $output->result($status);
        return $status['ok'] && $status['missing'] === array() ? 0 : 1;
    }
}
