<?php

namespace MajorDoMo\Core\Console;

use MajorDoMo\Core\Console\Commands;

/**
 * Единый реестр CLI и обработка вывода команд.
 *
 * Изменения:
 * 2026-10-25 — зарегистрирована диагностика долговечного журнала заданий ядра.
 * 2026-10-23 — обновление системы из терминала (`deploy:update`) для deploy.sh.
 * 2026-10-23 — сборка индекса знаний ИИ-помощника.
 * 2026-10-23 — зарегистрирована диагностика опционального Qdrant модуля ИИ.
 */
final class Application
{
    /** @var array<string,Command> */
    private array $commands = array();

    public function __construct(
        private string $root,
        private ?\Closure $runtimeReader = null,
        private ?\Closure $usersFactory = null,
        private ?TerminalInput $terminalInput = null,
        private ?\Closure $modesFactory = null,
        private ?\Closure $apiTokensFactory = null,
        private ?\Closure $migrationsFactory = null
    )
    {
        foreach (array(
            new Commands\ListCommand(), new Commands\HelpCommand(), new Commands\AboutCommand(),
            new Commands\DoctorCommand(), new Commands\CyclesStatusCommand(),
            new Commands\CyclesStartCommand(), new Commands\CyclesStopCommand(), new Commands\CyclesRestartCommand(),
            new Commands\CyclesDisableCommand(), new Commands\CyclesEnableCommand(), new Commands\SystemReloadCommand(),
            new Commands\MigrationsStatusCommand(), new Commands\MigrationsRunCommand(),
            new Commands\MaintenanceOnCommand(), new Commands\MaintenanceOffCommand(), new Commands\MaintenanceStatusCommand(),
            new Commands\DeployUpdateCommand(),
            new Commands\SystemdSetCommand(), new Commands\SystemdGivePermissionsCommand(),
            new Commands\LogsTailCommand(),
            new Commands\ConfigDoctorCommand(), new Commands\ConfigCacheCommand(), new Commands\ConfigClearCommand(),
            new Commands\ConfigSnapshotCommand(), new Commands\ConfigAuditCommand(), new Commands\ConfigMigrateCommand(),
            new Commands\InstallStatusCommand(), new Commands\InstallCheckCommand(), new Commands\InstallTestDbCommand(),
            new Commands\InstallResetCommand(), new Commands\InstallCompleteCommand(),
            new Commands\UsersListCommand(), new Commands\UsersPasswordCommand(),
            new Commands\AuthUnblockCommand(), new Commands\AuthResetCommand(),
            new Commands\ModesListCommand(), new Commands\ModesSetCommand(),
            new Commands\ApiTokensCommand(), new Commands\ApiRevokeCommand(), new Commands\ApiSpecCommand(),
            new Commands\WebSocketsServeCommand(), new Commands\MqttRuntimeCommand(), new Commands\QueueStatusCommand(),
            new Commands\AiRagCheckCommand(), new Commands\AiIndexCommand(),
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
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) return false;
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $name = isset($arguments[0]) && !str_starts_with($arguments[0], '-') ? array_shift($arguments) : 'list';
            $command = $this->command($name);
            $input = new Input($arguments, $command);
            $output->setJson((bool)$input->option('json'));
            if ($input->option('ansi') && $input->option('no-ansi')) throw new \InvalidArgumentException('Выберите либо --ansi, либо --no-ansi');
            $output->setAnsi($input->option('ansi') ? true : ($input->option('no-ansi') ? false : null));
            if ($input->option('help')) {
                $this->describe($command, $output);
                return $output->finish(0);
            }
            $context = new Context($this->root, $this, $this->runtimeReader, $this->usersFactory, $this->terminalInput, $this->modesFactory, $this->apiTokensFactory, $this->migrationsFactory);
            return $output->finish($command->execute($input, $output, $context));
        } catch (\InvalidArgumentException $error) {
            $output->error($error->getMessage());
            return $output->finish(2);
        } catch (\Throwable $error) {
            $output->error($error->getMessage());
            return $output->finish(1);
        } finally {
            restore_error_handler();
        }
    }

    public function describe(Command $command, Output $output): void
    {
        $usage = 'php majordomo ' . $command->name();
        foreach (array_keys($command->arguments()) as $name) $usage .= str_ends_with($name, '?') ? ' [' . rtrim($name, '?') . ']' : ' <' . $name . '>';
        $output->title($command->name());
        $output->muted($command->description());
        $output->section('Использование');
        $output->line('  ' . $usage . ' [опции]');
        $options = $command->options() + array('help' => 'Справка без выполнения команды', 'json' => 'Вывод JSON', 'ansi' => 'Включить цвета принудительно', 'no-ansi' => 'Выключить цвета', 'verbose' => 'Подробные подсказки диагностики');
        $rows = array();
        foreach ($command->arguments() as $name => $description) $rows[] = array(rtrim($name, '?'), $description);
        if ($rows !== array()) {
            $output->section('Аргументы');
            $output->table(array('Аргумент', 'Описание'), $rows, false);
        }
        $rows = array();
        foreach ($options as $name => $description) {
            $rows[] = array('--' . $name, $description);
        }
        $output->section('Опции');
        $output->table(array('Опция', 'Описание'), $rows, false);
        $output->line();
        $output->result(array('name' => $command->name(), 'description' => $command->description(), 'usage' => $usage, 'arguments' => $command->arguments(), 'options' => $options));
    }
}
