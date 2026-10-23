<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};
use MajorDoMo\Core\Auth\LoginAttempts;

/**
 * Сброс всех временных блокировок входа.
 *
 * Обычная команда `auth:unblock <ip>` работает по адресу. Здесь нужен общий
 * сброс: пока сервер не определяет адрес клиента, записи идут под пустым
 * ключом, и указать для них IP невозможно.
 */
final class AuthResetCommand extends Command
{
    public function name(): string { return 'auth:reset'; }
    public function description(): string { return 'Сбросить счётчики и все временные блокировки входа'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $context->config();
        $cleared = (new LoginAttempts($context->root))->reset();
        $output->line('Счётчики входа сброшены. Снято записей: ' . $cleared . '.');
        $output->result(array('reset' => true, 'cleared' => $cleared));
        return 0;
    }
}
