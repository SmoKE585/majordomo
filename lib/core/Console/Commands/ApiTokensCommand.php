<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};

final class ApiTokensCommand extends Command
{
    public function name(): string { return 'api:tokens'; }
    public function description(): string { return 'Список API-токенов без секретов и хешей'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $tokens = $context->apiTokens()->all();
        $output->table(array('ID', 'Название', 'Состояние', 'Действует до'), array_map(static fn($row) => array($row['ID'], $row['NAME'], $row['REVOKED_AT'] !== null ? 'Отозван' : ($row['EXPIRES_AT'] !== null && (int)$row['EXPIRES_AT'] <= time() ? 'Истёк' : 'Активен'), $row['EXPIRES_AT'] === null ? 'Бессрочно' : date('c', (int)$row['EXPIRES_AT'])), $tokens));
        $output->result(array('tokens' => $tokens));
        return 0;
    }
}
