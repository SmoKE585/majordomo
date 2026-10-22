<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Context, Input, Output};
use MajorDoMo\Core\WebSockets\Runtime;

final class WebSocketsServeCommand extends Command
{
    public function name(): string { return 'websockets:serve'; }
    public function description(): string { return 'Запустить сервер WebSocket в текущем терминале (обычно им управляет цикл)'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        if ($output->isJson()) throw new \InvalidArgumentException('Длительный сервер не поддерживает --json. Используйте cycles:status --json.');
        Runtime::run($context->root);
        return 0;
    }
}
