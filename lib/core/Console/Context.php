<?php

namespace MajorDoMo\Core\Console;

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Cycles\CycleManager;
use MajorDoMo\Core\Users\UserAccounts;

final class Context
{
    /** Optional reader is for isolated CLI tests and embedded callers. */
    public function __construct(
        public readonly string $root,
        public readonly Application $application,
        private ?\Closure $runtimeReader = null,
        private ?\Closure $usersFactory = null,
        private ?TerminalInput $terminal = null,
        private ?\Closure $modesFactory = null,
        private ?\Closure $apiTokensFactory = null
    ) {}

    public function terminalInput(): TerminalInput { return $this->terminal ??= new TerminalInput(); }

    public function apiTokens(): \MajorDoMo\Core\Api\TokenStore
    {
        $this->config();
        return $this->apiTokensFactory !== null ? ($this->apiTokensFactory)() : \MajorDoMo\Core\Api\TokenStore::connect();
    }

    public function users(): UserAccounts
    {
        $this->config();
        return $this->usersFactory !== null ? ($this->usersFactory)() : UserAccounts::connect();
    }

    public function config(): void { Config::boot($this->root); }

    public function modes(): \MajorDoMo\Core\Modes\ModeManager
    {
        if ($this->modesFactory !== null) return ($this->modesFactory)();
        chdir($this->root);
        require_once $this->root . '/config.php';
        require_once $this->root . '/lib/loader.php';
        require_once $this->root . '/load_settings.php';
        if (!isModuleInstalled('modes')) throw new \RuntimeException('Откройте раздел «Режимы» в панели для установки модуля.');
        return \MajorDoMo\Core\Modes\ModeManager::shared();
    }

    public function cycles(bool $runtime = false): CycleManager
    {
        $this->config();
        $reader = null;
        if ($runtime) {
            $values = $this->runtimeReader !== null ? ($this->runtimeReader)() : (new CycleRuntimeReader())->read();
            $reader = static fn(string $key) => $values[strtolower($key)] ?? false;
        }
        return new CycleManager($this->root, null, $reader);
    }

    public function cycleName(string $value, CycleManager $manager): string
    {
        if (!preg_match('/^(?:cycle_)?[A-Za-z0-9_]+$/D', $value)) throw new \InvalidArgumentException('Недопустимое имя цикла');
        $name = str_starts_with($value, 'cycle_') ? $value : 'cycle_' . $value;
        if (!$manager->exists($name)) throw new \InvalidArgumentException('Цикл не найден: ' . $value);
        return $name;
    }
}
