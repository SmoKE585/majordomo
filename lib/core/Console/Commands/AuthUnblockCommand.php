<?php
namespace MajorDoMo\Core\Console\Commands;
use MajorDoMo\Core\Console\{Command, Input, Output, Context};
use MajorDoMo\Core\Auth\LoginAttempts;

final class AuthUnblockCommand extends Command
{
    public function name(): string { return 'auth:unblock'; }
    public function description(): string { return 'Снять временную блокировку входа с IP-адреса'; }
    public function arguments(): array { return array('ip' => 'IPv4 или IPv6 заблокированного клиента'); }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $ip = (string)$input->argument('ip');
        $context->config();
        (new LoginAttempts($context->root))->unblock($ip);
        $output->line('Блокировка входа снята: ' . $ip);
        $output->result(array('ip' => $ip, 'unblocked' => true));
        return 0;
    }
}
