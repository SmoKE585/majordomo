<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};

final class DoctorCommand extends Command
{
    public function name(): string { return 'doctor'; }
    public function description(): string { return 'Диагностика PHP, файлов, расширений и подключения к БД'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        return (new InstallCheckCommand())->execute($input, $output, $context);
    }
}
