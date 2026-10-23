<?php
// 2026-10-23 — HEAD в разрешённых методах и диагностике.
// 2026-10-23 — назначения module/script/code, синтаксис PHP и обновление старой схемы.
/** Public webhook registry, path boundaries and rate limit on disposable SQLite. */
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('DB_HOST='); putenv('DB_NAME='); putenv('USE_REDIS=false');
require_once dirname(__DIR__) . '/lib/core.class.php';
require_once __DIR__ . '/fixtures/api_runtime.php';

use MajorDoMo\Core\{Config, Database};
use MajorDoMo\Core\Api\{HookContext, HookRateLimiter, HookRegistry, HookStore, Schema};

$checks = 0;
function check(bool $condition, string $message): void { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException($message); }
function invalid(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $error) {
        check(true, $label);
        return;
    }
    throw new RuntimeException('Expected validation rejection: ' . $label);
}

$root = sys_get_temp_dir() . '/mjd-api-' . bin2hex(random_bytes(6)); mkdir($root);
try {
    ApiFixture::initialize($root, dirname(__DIR__));
    ApiFixture::boot($root);
    $pdo = $GLOBALS['db']->dbh;
    $store = new HookStore($pdo, $root);
    check(Config::string('APP_ENV') === 'testing' && Config::string('DB_HOST') === '', 'Confirmed isolation');

    $created = $store->create('Telegram fixture', 'fixturehook', 'webhook.php', array('POST'), 'X-Fixture-Secret');
    check(preg_match('/^[a-f0-9]{64}$/D', $created['secret']) === 1, 'URL secret is 256 bits of hex');
    check(preg_match('/^[a-f0-9]{64}$/D', (string)$created['header_secret']) === 1, 'Header secret is 256 bits of hex');
    check($created['header_name'] === 'X-Fixture-Secret', 'Header name stored');
    $rows = $store->all();
    check(count($rows) === 1 && !isset($rows[0]['SECRET_HASH']) && !isset($rows[0]['HEADER_SECRET_HASH']), 'List never exposes hashes');
    check(!str_contains(json_encode($rows), $created['secret']) && !str_contains(json_encode($rows), (string)$created['header_secret']), 'List never exposes secrets');
    check($pdo->query('SELECT SECRET_HASH FROM api_hooks')->fetchColumn() === hash('sha256', $created['secret']), 'Only URL secret hash persisted');
    check($pdo->query('SELECT HEADER_SECRET_HASH FROM api_hooks')->fetchColumn() === hash('sha256', $created['header_secret']), 'Only header secret hash persisted');
    $found = $store->findBySecret($created['secret']);
    check($found !== null && (int)$found['ID'] === $created['id'] && (int)$found['ENABLED'] === 1, 'Secret lookup');
    check($store->findBySecret('bad') === null && $store->findBySecret(str_repeat('a', 64)) === null, 'Unknown secret lookup');

    $sameHeader = $store->update($created['id'], 'Telegram renamed', 'fixturehook', 'webhook.php', array('POST', 'GET', 'HEAD'), 'X-Fixture-Secret');
    check($sameHeader['header_secret'] === null, 'Unchanged header keeps its secret');
    check(HookStore::methods(array('METHODS' => 'POST,GET,HEAD')) === array('POST', 'GET', 'HEAD'), 'HEAD is a supported allowed method');
    check($store->find($created['id'])['HEADER_SECRET_HASH'] === hash('sha256', $created['header_secret']), 'Unchanged header hash stays');
    $newHeader = $store->update($created['id'], 'Telegram renamed', 'fixturehook', 'webhook.php', array('POST'), 'X-Other-Secret');
    check(preg_match('/^[a-f0-9]{64}$/D', (string)$newHeader['header_secret']) === 1, 'Changed header gets a new secret');
    check($store->find($created['id'])['HEADER_SECRET_HASH'] !== hash('sha256', $created['header_secret']), 'Old header secret hash replaced');

    $rotated = $store->rotate($created['id']);
    check($store->findBySecret($created['secret']) === null && $store->findBySecret($rotated['secret']) !== null, 'Rotation invalidates the old URL immediately');
    $rotatedHeader = $store->rotateHeader($created['id']);
    check($store->find($created['id'])['HEADER_SECRET_HASH'] === hash('sha256', $rotatedHeader['header_secret']), 'Header rotation replaces its hash');
    $store->setEnabled($created['id'], false);
    check((int)$store->findBySecret($rotated['secret'])['ENABLED'] === 0, 'Disabled state is read on each lookup');
    $store->setEnabled($created['id'], true);
    $store->touch($created['id'], 429, 'POST');
    $row = $store->find($created['id']);
    check((int)$row['LAST_STATUS'] === 429 && $row['LAST_METHOD'] === 'POST' && !empty($row['LAST_USED_AT']), 'Diagnostics store only status, time and method');
    $store->touch($created['id'], 200, 'HEAD');
    check($store->find($created['id'])['LAST_METHOD'] === 'HEAD', 'Diagnostics keep HEAD as the last method');
    $store->delete($created['id']);
    check($store->findBySecret($rotated['secret']) === null && $store->all() === array(), 'Delete removes the entry');

    invalid(fn() => $store->create('', 'fixturehook', 'webhook.php', array('POST')), 'Empty name');
    invalid(fn() => $store->create('x', '../fixturehook', 'webhook.php', array('POST')), 'Invalid module name');
    invalid(fn() => $store->create('x', 'fixturehook', '../webhook.php', array('POST')), 'Traversal handler');
    invalid(fn() => $store->create('x', 'fixturehook', '/etc/passwd', array('POST')), 'Absolute handler');
    invalid(fn() => $store->create('x', 'fixturehook', 'php://filter/resource=webhook.php', array('POST')), 'Stream wrapper handler');
    invalid(fn() => $store->create('x', 'fixturehook', 'webhook.php', array()), 'No methods');
    invalid(fn() => $store->create('x', 'fixturehook', 'webhook.php', array('TRACE')), 'Unsupported method');
    invalid(fn() => $store->create('x', 'fixturehook', 'webhook.php', array('POST'), 'Host'), 'Denied header name');
    invalid(fn() => $store->create('x', 'missingmodule', 'webhook.php', array('POST')), 'Uninstalled module rejected on save');
    invalid(fn() => $store->create('x', 'fixturehook', 'missing.php', array('POST')), 'Missing handler rejected on save');

    $code = 'return array("marker" => "private-code-marker", "value" => getGlobal("Lamp.level"));';
    $custom = $store->create('Custom PHP', '', '', array('POST'), '', 'code', 0, $code);
    $row = $store->findBySecret($custom['secret']);
    check($row['HANDLER_TYPE'] === 'code' && $row['CODE'] === $code && $row['SCRIPT_ID'] === null && $row['MODULE'] === '', 'Custom code stored without module dependency');
    check(!str_contains(json_encode($store->all()), 'private-code-marker') && !isset($store->all()[0]['CODE']), 'List omits custom code');
    $editable = $store->editable($custom['id']);
    check($editable['CODE'] === $code && !isset($editable['SECRET_HASH'], $editable['HEADER_SECRET_HASH']), 'Editor gets code without secret hashes');
    check($store->scriptOptions() === array(1 => 'Test script'), 'Scenario selector uses existing scenarios');
    $store->update($custom['id'], 'Scenario', '', '', array('POST'), '', 'script', 1);
    $row = $store->findBySecret($custom['secret']);
    check($row['HANDLER_TYPE'] === 'script' && (int)$row['SCRIPT_ID'] === 1 && $row['CODE'] === null, 'Changing target preserves URL and clears inactive code');
    $store->update($custom['id'], 'Module', 'fixturehook', 'webhook.php', array('POST'));
    $row = $store->findBySecret($custom['secret']);
    check($row['HANDLER_TYPE'] === 'module' && $row['SCRIPT_ID'] === null && $row['CODE'] === null, 'Switching back to module preserves legacy arguments');
    invalid(fn() => $store->create('x', '', '', array('POST'), '', 'script', 999), 'Missing scenario rejected');
    invalid(fn() => $store->create('x', '', '', array('POST'), '', 'unknown'), 'Unknown handler type rejected');
    foreach (array('', 'return array(', '<?php return array();', 'echo "x"; ?>HTML', str_repeat(' ', 65536)) as $invalidCode) {
        invalid(fn() => $store->create('x', '', '', array('POST'), '', 'code', 0, $invalidCode), 'Invalid PHP rejected without execution');
    }
    $marker = $root . '/validation-must-not-execute';
    $store->create('Validation', '', '', array('POST'), '', 'code', 0, 'file_put_contents(' . var_export($marker, true) . ', "bad"); return array();');
    check(!is_file($marker), 'Saving PHP never executes it');
    $oldDb = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $oldDb->exec("CREATE TABLE api_hooks (ID INTEGER PRIMARY KEY,MODULE TEXT,HANDLER TEXT); INSERT INTO api_hooks VALUES (1,'fixturehook','webhook.php')");
    $migrationDb = new Database(new ApiFixtureConnection($oldDb));
    Schema::upgradeHookHandlers($migrationDb);
    Schema::upgradeHookHandlers($migrationDb);
    $oldRow = $oldDb->query('SELECT * FROM api_hooks')->fetch(PDO::FETCH_ASSOC);
    check($oldRow['HANDLER_TYPE'] === 'module' && $oldRow['MODULE'] === 'fixturehook' && $oldRow['HANDLER'] === 'webhook.php' && $oldRow['CODE'] === null, 'Idempotent migration keeps old hooks modular');

    check(HookRegistry::resolve($root, 'fixturehook', 'webhook.php') === str_replace('\\', '/', (string)realpath($root . '/modules/fixturehook/webhook.php')), 'Valid handler resolves');
    check(HookRegistry::available($root, 'fixturehook', 'webhook.php'), 'Valid handler is available');
    foreach (array('../config.php', '/etc/passwd', 'php://filter/resource=webhook.php', 'webhook.php/../config.php', 'missing.php') as $handler) {
        check(!HookRegistry::available($root, 'fixturehook', $handler), 'Boundary rejects ' . $handler);
    }
    check(!HookRegistry::available($root, 'missingmodule', 'webhook.php'), 'Uninstalled module rejected');
    unlink($root . '/cms/modules_installed/fixturehook.installed');
    check(!HookRegistry::available($root, 'fixturehook', 'webhook.php'), 'Removed module rejected');
    file_put_contents($root . '/cms/modules_installed/fixturehook.installed', 'fixture');
    rename($root . '/modules/fixturehook/webhook.php', $root . '/modules/fixturehook/webhook.php.bak');
    check(!HookRegistry::available($root, 'fixturehook', 'webhook.php'), 'Missing handler rejected');
    rename($root . '/modules/fixturehook/webhook.php.bak', $root . '/modules/fixturehook/webhook.php');
    $outside = $root . '/outside.php';
    file_put_contents($outside, '<?php echo "outside";');
    $link = $root . '/modules/fixturehook/escape.php';
    if (@symlink($outside, $link)) {
        check(!HookRegistry::available($root, 'fixturehook', 'escape.php'), 'Symlink out of the module rejected');
        unlink($link);
    }
    unlink($outside);

    $limiter = new HookRateLimiter($root, 2, 60);
    check($limiter->hit(1) === 0 && $limiter->hit(1) === 0, 'Rate limit allows the bucket');
    $retry = $limiter->hit(1);
    check($retry > 0 && $retry <= 60, 'Rate limit returns Retry-After');
    check($limiter->hit(2) === 0, 'Rate limit is per entry');

    check(!HookContext::active() && HookContext::hook() === null, 'Hook permission starts closed');
    HookContext::activate(array('ID' => 1));
    check(HookContext::active() && HookContext::hook()['ID'] === 1, 'Hook permission opens only when set');
    HookContext::reset();
    check(!HookContext::active() && HookContext::hook() === null, 'Hook permission resets after the call');

    echo 'API hooks smoke: ' . $checks . " passed\n";
} finally {
    unset($pdo, $store, $GLOBALS['db']);
    (new ReflectionProperty(\MajorDoMo\Core\Core::class, 'services'))->setValue(null, array());
    gc_collect_cycles();
    ApiFixture::remove($root);
}
