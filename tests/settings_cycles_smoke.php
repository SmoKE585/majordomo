<?php
/**
 * Settings and cycle-state smoke test (no database required).
 *
 * Run: php tests/settings_cycles_smoke.php
 */

putenv('APP_ENV=testing');
putenv('USE_REDIS=false');
putenv('DISABLE_WEBSOCKETS=' . (($argv[1] ?? '') === 'websockets-disabled' ? '1' : '0'));
// SettingsManager checks the database on construction; keep the fixture from
// ever reaching a real server even if the shell exports DB_* variables.
putenv('DB_HOST=localhost');
putenv('DB_NAME=');
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\ConfigSchema;
use MajorDoMo\Core\Config;
use MajorDoMo\Core\Cycles\CycleManager;
use MajorDoMo\Core\Cycles\CycleStateStore;
use MajorDoMo\Core\Cycles\CycleSupervisor;
use MajorDoMo\Core\Settings\SettingsManager;
use MajorDoMo\Core\Settings\SettingsSchema;

$failures = 0;
function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo 'FAIL: ' . $message . PHP_EOL;
        return;
    }
    echo 'ok: ' . $message . PHP_EOL;
}

// --- Settings schema -------------------------------------------------------
$sections = SettingsSchema::sections();
$fields = SettingsSchema::fields();
check(count($sections) >= 8, 'settings schema exposes the main sections');
check(count($fields) > 70, 'settings schema reuses the wizard field set');
check(!isset($fields['admin_password']), 'admin creation fields are not shown in general settings');
check(!isset($fields['create_schema']), 'automatic schema creation is wizard-only');
check(isset($fields['DB_HOST']) && isset($fields['PROJECT_TITLE']) && isset($fields['THEME']), 'core wizard fields are present');
check(isset($fields['SYSTEM_DEBMES_PATH']) && isset($fields['SYSTEM_WEBSOCKETS_TOKEN']), 'legacy-only settings are preserved');

$config = ConfigSchema::all();
$missingConfig = array();
foreach (SettingsSchema::fields() as $id => $field) {
    if (($field['target'] ?? '') !== 'env') {
        continue;
    }
    if (!isset($config[$id])) {
        $missingConfig[] = $id;
    }
}
check($missingConfig === array(), 'every environment setting is declared in ConfigSchema');

$seen = array();
$duplicates = array();
foreach ($fields as $id => $field) {
    if (isset($seen[$id])) {
        $duplicates[] = $id;
    }
    $seen[$id] = true;
    if (!isset($sections[(string)($field['section'] ?? '')])) {
        $duplicates[] = $id . ' (missing section)';
    }
}
check($duplicates === array(), 'field ids are unique and reference an existing section');

// --- Cycle state store -----------------------------------------------------
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mjd-settings-cycles-' . bin2hex(random_bytes(6));
mkdir($root . '/scripts', 0777, true);
Config::boot($root);

// The admin page posts controls as `value_<ID>` because it shares the template
// with legacy DB-backed rows. A manager that only reads plain IDs saves nothing
// while still reporting success — the exact regression this test covers.
$manager = new SettingsManager($root);
$adminPost = $manager->validate(array(
    'value_PROJECT_TITLE' => 'Дом теста',
    'value_THEME' => 'light',
    'value_DEBUG_MODE' => '1',
));
check(($adminPost['values']['PROJECT_TITLE'] ?? '') === 'Дом теста', 'admin form text values (value_<ID>) are applied');
check(($adminPost['values']['THEME'] ?? '') === 'light', 'admin form select values (value_<ID>) are applied');
check(($adminPost['values']['DEBUG_MODE'] ?? '') === '1', 'admin form switch values (value_<ID>) are applied');
// Saving one section must not reset switches rendered in another section.
$otherSection = $manager->validate(array('value_PROJECT_TITLE' => 'Дом теста'));
check(($otherSection['values']['DEBUG_MODE'] ?? '') === '1', 'switches from other sections are preserved');
$plainPost = $manager->validate(array('PROJECT_TITLE' => 'Значение из мастера'));
check(($plainPost['values']['PROJECT_TITLE'] ?? '') === 'Значение из мастера', 'plain field ids still work for the wizard and reset');

file_put_contents($root . '/scripts/cycle_test.php', "<?php\n");
file_put_contents($root . '/scripts/cycle_other.php', "<?php\n");
try {
    $store = new CycleStateStore($root);
    $store->mergeDefaults(array('cycle_test', 'cycle_other'));
    check($store->desired('cycle_test') === CycleStateStore::RUNNING, 'cycles default to running');
    check($store->desired('unknown') === CycleStateStore::RUNNING, 'unknown cycles are treated as running');

    $store->set('cycle_test', CycleStateStore::DISABLED);
    check($store->isDisabled('cycle_test'), 'disable is persisted');
    check($store->desired('cycle_test') === CycleStateStore::DISABLED, 'disabled state is readable');

    $store->set('cycle_test', CycleStateStore::STOPPED);
    check($store->isStopped('cycle_test'), 'stop is persisted separately from disable');

    $store->set('cycle_test', CycleStateStore::RUNNING, true);
    check($store->generation('cycle_test') === 1, 'restart bumps the generation');
    check($store->isRunning('cycle_test'), 'restart returns the cycle to running');

    $store->requestReload();
    check($store->reloadRequested() > 0, 'reload request is persisted for a database switch');
    $store->clearReload();
    check($store->reloadRequested() === 0, 'reload request can be consumed');

    $manager = new CycleManager($root, $store);
    check($manager->names() === array('cycle_other', 'cycle_test'), 'cycle manager discovers script files');
    $manager->disable('cycle_test');
    check($store->isDisabled('cycle_test'), 'manager disable writes the persistent state');
    $manager->enable('cycle_test');
    check($store->isRunning('cycle_test'), 'manager enable returns the cycle to running');
    $beforeRestart = $store->generation('cycle_test');
    $manager->restart('cycle_test');
    check($store->generation('cycle_test') === $beforeRestart + 1, 'manager restart bumps the generation');

    $supervisor = new CycleSupervisor($store);
    $supervisor->bootstrap(array('cycle_test', 'cycle_other'));
    check($supervisor->shouldStartAtBoot('cycle_test'), 'supervisor starts running cycles at boot');
    $store->set('cycle_other', CycleStateStore::DISABLED);
    check(!$supervisor->shouldStartAtBoot('cycle_other'), 'supervisor skips disabled cycles at boot');

    $toStart = array();
    $toStop = array();
    $running = array('cycle_other' => 1);
    $supervisor->reconcile(array('cycle_test', 'cycle_other'), $running, $toStart, $toStop);
    check(isset($toStop['cycle_other']), 'supervisor stops a running disabled cycle');
    check(!isset($toStart['cycle_other']), 'supervisor does not restart a disabled cycle');

    $store->set('cycle_test', CycleStateStore::RUNNING, true);
    $toStart = array();
    $toStop = array();
    $supervisor->reconcile(array('cycle_test'), array('cycle_test' => 1), $toStart, $toStop);
    check(isset($toStop['cycle_test']) && isset($toStart['cycle_test']), 'supervisor restarts a running cycle on a new generation');

    $websocketsDisabled = Config::bool('DISABLE_WEBSOCKETS');
    foreach (array('cycle_wscache', 'cycle_websockets') as $name) {
        check($supervisor->shouldStartAtBoot($name) === !$websocketsDisabled, $name . ' boot respects WebSocket configuration');
        $state = $manager->state($name);
        check(($state['status'] === 'disabled') === $websocketsDisabled && !$state['online'], $name . ' status respects WebSocket configuration');
        check($state['desired'] === 'running', $name . ' configuration does not overwrite persistent preferences');
        $toStart = array($name => time() + 2);
        $toStop = array();
        $supervisor->reconcile(array($name), array($name => 1), $toStart, $toStop);
        check(isset($toStop[$name]) === $websocketsDisabled && isset($toStart[$name]) === !$websocketsDisabled, $name . ' recovery respects WebSocket configuration');
    }
} finally {
    foreach (glob($root . '/scripts/*.php') ?: array() as $file) {
        unlink($file);
    }
    @unlink($root . '/cms/cycles/state.json');
    @unlink($root . '/cms/cycles/state.lock');
    @rmdir($root . '/cms/cycles');
    if (is_file($root . '/cms/cached/config.php')) unlink($root . '/cms/cached/config.php');
    if (is_dir($root . '/cms/cached')) rmdir($root . '/cms/cached');
    @rmdir($root . '/cms');
    @rmdir($root . '/scripts');
    @rmdir($root);
}

if ($failures > 0) {
    echo 'RESULT ' . $failures . ' failure(s)' . PHP_EOL;
    exit(1);
}
echo 'RESULT settings and cycle state smoke tests passed' . PHP_EOL;
