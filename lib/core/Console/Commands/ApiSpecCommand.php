<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Api\Contract;
use MajorDoMo\Core\Console\{Command, Context, Input, Output};

final class ApiSpecCommand extends Command
{
    public function name(): string { return 'api:spec'; }
    public function description(): string { return 'Спецификация OpenAPI без подключения к БД'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $spec = Contract::openApi();
        if (!$output->isJson()) $output->line(json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $output->result($spec);
        return 0;
    }
}
