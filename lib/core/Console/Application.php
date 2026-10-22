<?php

namespace MajorDoMo\Core\Console;

use MajorDoMo\Core\Console\Commands;

final class Application
{
    /** @var array<string,Command> */
    private array $commands = array();

    public function __construct(private string $root, private ?\Closure $runtimeReader = null)
    {
        foreach (array(
            new Commands\ListCommand(), new Commands\HelpCommand(), new Commands\AboutCommand(),
            new Commands\DoctorCommand(), new Commands\CyclesStatusCommand(),
            new Commands\CyclesStartCommand(), new Commands\CyclesStopCommand(), new Commands\CyclesRestartCommand(),
            new Commands\CyclesDisableCommand(), new Commands\CyclesEnableCommand(), new Commands\SystemReloadCommand(),
            new Commands\LogsTailCommand(),
            new Commands\ConfigDoctorCommand(), new Commands\ConfigCacheCommand(), new Commands\ConfigClearCommand(),
            new Commands\ConfigSnapshotCommand(), new Commands\ConfigAuditCommand(), new Commands\ConfigMigrateCommand(),
            new Commands\InstallStatusCommand(), new Commands\InstallCheckCommand(), new Commands\InstallTestDbCommand(),
            new Commands\InstallResetCommand(), new Commands\InstallCompleteCommand(),
        ) as $command) $this->register($command);
    }

    public function register(Command $command): void
    {
        $name = $command->name();
        if (!preg_match('/^[a-z][a-z0-9-]*(?::[a-z][a-z0-9-]*)*$/D', $name) || isset($this->commands[$name])) {
            throw new \LogicException('Недопустимое или повторяющееся имя команды: ' . $name);
        }
        $this->commands[$name] = $command;
    }

    public function commands(): array { $commands = $this->commands; ksort($commands); return $commands; }

    public function command(string $name): Command
    {
        if (!isset($this->commands[$name])) throw new \InvalidArgumentException('Неизвестная команда: ' . $name . '. Список: php majordomo list');
        return $this->commands[$name];
    }

    /** Arguments exclude the executable name. Streams can be replaced in tests. */
    public function run(array $arguments, mixed $stdout = null, mixed $stderr = null): int
    {
        $output = new Output($stdout ?? STDOUT, $stderr ?? STDERR);
        $output->setJson(in_array('--json', $arguments, true));
        try {
            $name = isset($arguments[0]) && !str_starts_with($arguments[0], '-') ? array_shift($arguments) : 'list';
            $command = $this->command($name);
            $input = new Input($arguments, $command);
            $output->setJson((bool)$input->option('json'));
            if ($input->option('help')) {
                $this->describe($command, $output);
                return $output->finish(0);
            }
            $context = new Context($this->root, $this, $this->runtimeReader);
            return $output->finish($command->execute($input, $output, $context));
        } catch (\InvalidArgumentException $error) {
            $output->error($error->getMessage());
            return $output->finish(2);
        } catch (\Throwable $error) {
            $output->error($error->getMessage());
            return $output->finish(1);
        }
    }

    public function describe(Command $command, Output $output): void
    {
        $usage = 'php majordomo ' . $command->name();
        foreach (array_keys($command->arguments()) as $name) $usage .= str_ends_with($name, '?') ? ' [' . rtrim($name, '?') . ']' : ' <' . $name . '>';
        $output->line($command->description());
        $output->line('Вызов: ' . $usage . ' [опции]');
        $rows = array();
        foreach ($command->arguments() as $name => $description) $rows[] = array(rtrim($name, '?'), $description);
        foreach ($command->options() + array('help' => 'Справка без выполнения команды', 'json' => 'Вывод JSON') as $name => $description) {
            $rows[] = array('--' . $name, $description);
        }
        $output->table(array('Аргумент / опция', 'Описание'), $rows);
        $output->result(array('name' => $command->name(), 'description' => $command->description(), 'usage' => $usage, 'arguments' => $command->arguments(), 'options' => $command->options() + array('help' => 'Справка', 'json' => 'JSON')));
    }
}
