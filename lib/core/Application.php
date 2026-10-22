<?php

namespace MajorDoMo\Core;

/**
 * Base class for HTTP entry points.
 *
 * Legacy URLs (admin.php, index.php, nf.php, api.php) stay as thin adapters,
 * but the boot sequence lives here instead of being copied into every file:
 * configuration -> legacy library loader -> DB -> settings.
 *
 * Subclasses own the entry-specific ordering and response handling.
 */
abstract class Application
{
    protected string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? dirname(__DIR__, 2), '/\\');
        if (is_dir($this->root)) {
            @chdir($this->root);
        }
    }

    abstract public function run(): void;

    /**
     * Load .env-backed configuration and start the total timer.
     */
    protected function bootConfiguration(): void
    {
        // config.php still declares these two legacy globals.
        global $restart_threads, $aditional_git_urls;

        require_once $this->root . '/lib/perfmonitor.class.php';
        startMeasure('TOTAL');
        require_once $this->root . '/config.php';
    }

    /**
     * Load the legacy library set and open the database connection.
     */
    protected function bootLibraries(): void
    {
        require_once $this->root . '/lib/loader.php';
    }

    /**
     * Load database-backed settings (SETTINGS_* constants, timezone, auth).
     */
    protected function bootSettings(): void
    {
        // load_settings.php normalizes this legacy global.
        global $aditional_git_urls;
        require_once $this->root . '/load_settings.php';
    }

    /**
     * The legacy code reads the session through `global $session`.
     */
    protected function startSession(string $name = 'prj'): void
    {
        global $session;
        $session = $name === 'prj' ? Core::session($this->root) : new Http\Session($name, $this->root);
    }

    protected function rootPath(): string
    {
        return $this->root;
    }
}
