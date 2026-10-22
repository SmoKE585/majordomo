<?php

/** System helpers. Public names and argument defaults are part of the module API. */

use MajorDoMo\Core\Modes\ModeManager;

function disableMode(string $name, string $source = 'code'): bool { return setMode($name, false, $source); }

function enableMode(string $name, string $source = 'code'): bool { return setMode($name, true, $source); }

/** Public PHP API, available through the standard libraries loader. */
function isModeActive(string $name): bool { return ModeManager::shared()->active($name); }

/** Called by the existing hook bus, including events without legacy subscribers. */
function modesProcessEvent(string $event, array $details): void
{
    if (!isModuleInstalled('modes')) return;
    try { ModeManager::shared()->tick($event, $details); }
    catch (Throwable $error) { DebMes($error->getMessage(), 'modes', 'error'); }
}

function setMode(string $name, bool $active, string $source = 'code'): bool { return ModeManager::shared()->set($name, $active, $source); }
