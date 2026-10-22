<?php

use MajorDoMo\Core\Modes\ModeManager;

/** Public PHP API, available through the standard libraries loader. */
function isModeActive(string $name): bool { return ModeManager::shared()->active($name); }
function setMode(string $name, bool $active, string $source = 'code'): bool { return ModeManager::shared()->set($name, $active, $source); }
function enableMode(string $name, string $source = 'code'): bool { return setMode($name, true, $source); }
function disableMode(string $name, string $source = 'code'): bool { return setMode($name, false, $source); }

/** Called by the existing hook bus, including events without legacy subscribers. */
function modesProcessEvent(string $event, array $details): void
{
    if (!isModuleInstalled('modes')) return;
    try { ModeManager::shared()->tick($event, $details); }
    catch (Throwable $error) { DebMes($error->getMessage(), 'modes', 'error'); }
}
