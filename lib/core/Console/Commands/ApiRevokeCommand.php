<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};

final class ApiRevokeCommand extends Command
{
    public function name(): string { return 'api:revoke'; }
    public function description(): string { return 'Немедленно отозвать API-токен по ID'; }
    public function arguments(): array { return array('id' => 'Положительный ID токена из api:tokens'); }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $id = $input->argument('id');
        if (!ctype_digit($id) || (int)$id < 1) throw new \InvalidArgumentException('ID должен быть положительным целым числом.');
        $context->apiTokens()->revoke((int)$id);
        $output->success('Токен отозван.');
        $output->result(array('id' => (int)$id, 'revoked' => true));
        return 0;
    }
}
