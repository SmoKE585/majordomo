<?php

declare(strict_types=1);

/**
 * Setup wizard smoke test (no live database required).
 *
 * Covers the parts that are easy to break silently:
 *  - .env writer preserves comments, updates/removes keys and round-trips values;
 *  - install marker lifecycle and the legacy fallback;
 *  - schema integrity and dependency references;
 *  - install URL detection for the gate;
 *  - MDJUI field rendering;
 *  - Installer defaults/validation without a reachable database.
 *
 * Usage:
 *   php tests/install_smoke.php
 *
 * The database-backed apply path is exercised manually (or by a separate
 * disposable-database run) because it must not touch a real installation.
 */

require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\ConfigSchema;
use MajorDoMo\Core\Env;
use MajorDoMo\Core\Install\EnvFile;
use MajorDoMo\Core\Install\InstallFormRenderer;
use MajorDoMo\Core\Install\InstallGate;
use MajorDoMo\Core\Install\InstallSchema;
use MajorDoMo\Core\Install\InstallState;
use MajorDoMo\Core\Install\Installer;

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

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: array() as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $remove($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$fixture = sys_get_temp_dir() . '/mjd-install-' . bin2hex(random_bytes(6));
mkdir($fixture, 0777, true);
mkdir($fixture . '/cms/cached', 0777, true);
mkdir($fixture . '/cms/debmes', 0777, true);
mkdir($fixture . '/cms/modules_installed', 0777, true);

file_put_contents($fixture . '/.env', implode("\n", array(
    '# keep me: project settings',
    'DB_HOST=localhost',
    'DB_NAME=mjd_install_fixture',
    'DB_USER=root',
    'DB_PASSWORD=',
    '# USE_REDIS=false',
    '# HOME_NETWORK=192.168.0.*',
    'PROJECT_TITLE=Old Title',
    '',
)));

// --- .env writer -----------------------------------------------------------
$result = EnvFile::apply($fixture, array(
    'DB_HOST' => 'db.local',
    'DB_NAME' => 'mjd_install_fixture',
    'PROJECT_TITLE' => 'Мой дом',
    'USE_REDIS' => 'false',
    'GIT_URL' => 'https://example.com/home.git',
    'HOME_NETWORK' => '192.168.0.*',
    'UNUSED_KEY' => null,
));
check(!empty($result['ok']), '.env writer reports success');
$parsed = Env::parseFile($fixture . '/.env');
check(($parsed['DB_HOST'] ?? '') === 'db.local', 'active key updated in place');
check(($parsed['PROJECT_TITLE'] ?? '') === 'Мой дом', 'quoted UTF-8 value round-trips');
check(($parsed['USE_REDIS'] ?? '') === 'false', 'commented documented key is uncommented');
check(($parsed['HOME_NETWORK'] ?? '') === '192.168.0.*', 'commented optional key is uncommented');
check(($parsed['GIT_URL'] ?? '') === 'https://example.com/home.git', 'new key appended');
$contents = (string)file_get_contents($fixture . '/.env');
check(str_contains($contents, '# keep me: project settings'), 'comments are preserved');
check(str_contains($contents, '# --- MajorDoMo install wizard'), 'new keys live in a managed block');

$quoted = EnvFile::formatValue('p#ss word');
check(Env::parse('X=' . $quoted)['X'] === 'p#ss word', 'formatValue round-trips spaces and #');
$windows = 'C:\\Program Files\\MySQL\\bin';
check(Env::parse('X=' . EnvFile::formatValue($windows))['X'] === $windows, 'formatValue round-trips Windows paths');
check(EnvFile::formatValue('') === '', 'empty value stays empty');

$beforeRemoval = Env::parseFile($fixture . '/.env');
check(isset($beforeRemoval['DB_HOST']), 'key exists before removal');
EnvFile::apply($fixture, array('DB_HOST' => null));
$afterRemoval = Env::parseFile($fixture . '/.env');
check(!array_key_exists('DB_HOST', $afterRemoval), 'null removes the active key');

$backup = EnvFile::backup($fixture);
check(is_string($backup) && is_file($backup), 'backup creates a timestamped copy');
if (is_string($backup)) {
    @unlink($backup);
}

// --- Install marker --------------------------------------------------------
check(!InstallState::isComplete($fixture), 'fresh fixture is not complete');
check(InstallState::markComplete($fixture, array('source' => 'test', 'project_title' => 'Test')), 'markComplete writes the marker');
check(InstallState::isComplete($fixture), 'marker makes the installation complete');
$state = InstallState::state($fixture);
check($state['complete'] === true, 'state reports complete');
check($state['source'] === 'test', 'state exposes the marker source');

// Reset must override an existing legacy marker.
file_put_contents(InstallState::legacyMarkerPath($fixture), 'legacy');
check(InstallState::isComplete($fixture), 'legacy marker counts as installed');
check(InstallState::reset($fixture), 'reset writes an incomplete marker');
check(!InstallState::isComplete($fixture), 'incomplete marker overrides the legacy marker');
@unlink(InstallState::markerPath($fixture));
check(InstallState::isComplete($fixture), 'without a marker the legacy marker works again');

// --- Schema ---------------------------------------------------------------
$schema = InstallSchema::fields();
$sections = InstallSchema::sections();
$configSchema = ConfigSchema::all();
$seen = array();
$sectionSteps = array();
foreach ($schema as $id => $field) {
    check(!isset($seen[$id]), 'field id is unique: ' . $id);
    $seen[$id] = true;
    check(($field['id'] ?? '') === $id, 'field carries its id: ' . $id);
    check(in_array($field['target'] ?? '', array(InstallSchema::TARGET_ENV, InstallSchema::TARGET_DB, InstallSchema::TARGET_META), true), 'field target is valid: ' . $id);
    check(isset($field['label'], $field['type'], $field['step'], $field['section']), 'field has required metadata: ' . $id);
    check(isset($sections[$field['section']]), 'field section exists: ' . $id);
    if (isset($sections[$field['section']])) {
        check((int)$sections[$field['section']]['step'] === (int)$field['step'], 'section and field steps agree: ' . $id);
        $sectionSteps[$field['section']] = true;
    }
    if (($field['target'] ?? '') === InstallSchema::TARGET_ENV) {
        check(array_key_exists($id, $configSchema), 'env field is declared in ConfigSchema: ' . $id);
        if (!empty($configSchema[$id]['secret'])) {
            check(!empty($field['secret']), 'secret env field is marked secret: ' . $id);
        }
    }
}
check(isset($schema['create_schema']), 'schema exposes the automatic table creation switch');
check(!isset($schema['DISABLE_USERS_LIST']), 'removed user-list option is not exposed by the wizard');
check(!array_key_exists('DISABLE_USERS_LIST', $configSchema), 'removed user-list option is not in ConfigSchema');
check(($schema['create_schema']['default'] ?? '') === '1', 'automatic table creation is enabled by default');
check(count($schema) >= 40, 'schema exposes a substantial set of parameters');

// --- Clean installation schema --------------------------------------------
$cleanSchemaPath = dirname(__DIR__) . '/lib/core/Install/schema.sql';
check(is_file($cleanSchemaPath), 'clean installation schema exists');
$cleanSchema = is_file($cleanSchemaPath) ? (string)file_get_contents($cleanSchemaPath) : '';
check(!preg_match('/^\s*INSERT\s+/mi', $cleanSchema), 'clean schema contains no demo data');
check(!preg_match('/AUTO_INCREMENT=\d+/i', $cleanSchema), 'clean schema has no demo AUTO_INCREMENT counters');
foreach (array('actions_log', 'classes', 'objects', 'project_modules', 'properties', 'property_audit_runs', 'property_audit_events', 'settings', 'users') as $requiredTable) {
    check(
        (bool)preg_match('/CREATE TABLE IF NOT EXISTS `' . preg_quote($requiredTable, '/') . '`/i', $cleanSchema),
        'clean schema contains table: ' . $requiredTable
    );
}
check(isset($sectionSteps['database'], $sectionSteps['identity'], $sectionSteps['behavior'], $sectionSteps['performance'], $sectionSteps['redis'], $sectionSteps['security']), 'all main sections are represented');
foreach (array(InstallSchema::STEP_CHECK, InstallSchema::STEP_DATABASE, InstallSchema::STEP_SYSTEM, InstallSchema::STEP_ADVANCED, InstallSchema::STEP_REVIEW) as $step) {
    check(isset(InstallSchema::steps()[$step]), 'step exists: ' . $step);
}
check(in_array('USE_REDIS', InstallSchema::managedEnvKeys(), true), 'managed env keys include USE_REDIS');
check(!array_filter(InstallSchema::managedEnvKeys(), static fn($key) => str_contains(strtolower($key), 'websocket')), 'installer writes no WebSocket configuration');

// Dependency references must point at real fields.
foreach ($schema as $id => $field) {
    if (!empty($field['depends'])) {
        $dep = $field['depends'];
        check(isset($dep['field'], $dep['value']), 'dependency is complete: ' . $id);
        check(isset($schema[$dep['field']]), 'dependency field exists: ' . $id . ' -> ' . $dep['field']);
    }
}

// --- Gate -----------------------------------------------------------------
define('ROOTHTML', '/');
check(InstallGate::isInstallRequest('/install.php'), 'gate accepts /install.php');
check(InstallGate::isInstallRequest('/install'), 'gate accepts /install');
check(InstallGate::isInstallRequest('/install/'), 'gate accepts /install/');
check(!InstallGate::isInstallRequest('/panel/'), 'gate does not accept /panel/');
check(InstallState::installUrl('/') === '/install.php', 'install URL for root');
check(InstallState::installUrl('/sub/') === '/sub/install.php', 'install URL for a subdirectory');

// --- Renderer -------------------------------------------------------------
// MDJUI uses the ROOT constant for its icon catalog. The real entry points get
// it from Config::boot(); the smoke test defines the fixture root first.
if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__) . '/');
}
require_once dirname(__DIR__) . '/lib/ui/MDJUI.php';
$dbHostField = $schema['DB_HOST'];
$html = InstallFormRenderer::field($dbHostField, 'db.local', array('errors' => array('DB_HOST' => 'Неверный хост'), 'secrets' => array()));
check(str_contains($html, 'name="DB_HOST"'), 'renderer emits the input name');
check(str_contains($html, 'value="db.local"'), 'renderer emits the value');
check(str_contains($html, 'Неверный хост'), 'renderer emits the error');

$secretField = $schema['DB_PASSWORD'];
$secretHtml = InstallFormRenderer::field($secretField, '', array('errors' => array(), 'secrets' => array('DB_PASSWORD' => true)));
check(str_contains($secretHtml, 'type="password"'), 'secret field renders a password input');
check(str_contains($secretHtml, 'сохранён'), 'secret field explains the existing value');
check(str_contains($secretHtml, 'DB_PASSWORD__clear'), 'secret field offers an explicit clear action');

$switchField = $schema['DEBUG_MODE'];
$switchHtml = InstallFormRenderer::field($switchField, '1', array('errors' => array(), 'secrets' => array()));
check(str_contains($switchHtml, 'md-switch'), 'switch field uses the design-system control');
check(str_contains($switchHtml, 'checked'), 'switch reflects the current value');

$choiceField = $schema['THEME'];
$choiceHtml = InstallFormRenderer::field($choiceField, 'dark', array('errors' => array(), 'secrets' => array()));
check(str_contains($choiceHtml, 'md-choice-group'), 'choice field uses the design-system tiles');
check(str_contains($choiceHtml, 'value="dark"'), 'choice field renders the selected value');

check(InstallFormRenderer::displayValue($switchField, '1') === 'включено', 'displayValue formats switches');
check(str_contains(InstallFormRenderer::displayValue($secretField, ''), 'не задано'), 'displayValue masks empty secrets');
check(str_contains(InstallFormRenderer::timezoneDatalist(), 'Europe/Moscow'), 'timezone datalist is populated');

// --- Installer without a reachable database -------------------------------
// The fixture points at a local address with an intentionally empty database;
// the constructor must not throw and the wizard must still render defaults.
file_put_contents($fixture . '/.env', implode("\n", array(
    'DB_HOST=127.0.0.1',
    'DB_PORT=3306',
    'DB_NAME=mjd_install_fixture_missing',
    'DB_USER=root',
    'DB_PASSWORD=',
    'PROJECT_TITLE=Fixture Home',
    'TIMEZONE=Europe/Moscow',
    '',
)));
Config::boot($fixture);
$installer = new Installer($fixture);
$values = $installer->formValues();
check(($values['PROJECT_TITLE'] ?? '') === 'Fixture Home', 'installer loads values from .env');
check(!isset($values['websockets_enabled']) && \MajorDoMo\Core\WebSockets\Runtime::PORT === 8001, 'WebSocket is built in with a fixed internal port');
check(($values['redis_enabled'] ?? '') === '0', 'redis defaults to disabled');
$validation = $installer->validate(array());
check(empty($validation['ok']), 'empty form does not pass validation');
check(isset($validation['errors']['_database']), 'database error is reported');
check(isset($validation['errors']['admin_password']), 'admin password is required when there is no administrator');
check((int)$validation['step'] === InstallSchema::STEP_DATABASE, 'validation points at the database step');
$test = $installer->testDatabase(array(
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'mjd_install_fixture_missing',
    'DB_USER' => 'root',
    'DB_PASSWORD' => '',
));
check(isset($test['ok']) && $test['ok'] === false, 'testDatabase reports an unreachable database without throwing');

// --- Cleanup --------------------------------------------------------------
$remove($fixture);

echo PHP_EOL . ($failures === 0 ? 'Install smoke tests passed' : $failures . ' install smoke test(s) failed') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
