<?php
// 2026-10-23 — реальный исполнитель сценариев: результат и ошибки проверенного webhook.
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('DB_HOST='); putenv('DB_NAME='); putenv('USE_REDIS=false'); putenv('LOG_DIRECTORY=');
require_once dirname(__DIR__) . '/lib/core.class.php';
require_once __DIR__ . '/fixtures/api_runtime.php';

use MajorDoMo\Core\Api\HookContext;
use MajorDoMo\Core\{Config, Core};

/** Минимальная оболочка модуля для изолированного запуска. 2026-10-23 — без install/БД проекта. */
class module {
    public string $name = ''; public string $title = ''; public string $module_category = '';
    public function checkInstalled(): void {}
}
function SQLSelectOne($sql) { return $GLOBALS['db']->SelectOne($sql); }
function SQLUpdate($table, $row) { $GLOBALS['script_updates'][] = array($table, $row); return true; }
function DBSafe($value) { return $GLOBALS['db']->DbSafe1($value); }
function isItPythonCode($code) { return false; }
require_once dirname(__DIR__) . '/modules/scripts/scripts.class.php';

$checks = 0;
function check(bool $value, string $label): void { ++$GLOBALS['checks']; if (!$value) throw new RuntimeException($label); }
$root = sys_get_temp_dir() . '/mjd-api-' . bin2hex(random_bytes(8)); mkdir($root);
try {
    ApiFixture::initialize($root, dirname(__DIR__)); ApiFixture::boot($root);
    $pdo = $GLOBALS['db']->dbh;
    check(Config::string('APP_ENV') === 'testing' && Config::string('DB_HOST') === '' && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Подтверждена изоляция');
    $setCode = $pdo->prepare('UPDATE scripts SET CODE=? WHERE ID=1');
    $scenario = new scripts();
    HookContext::activate(array('ID' => 9, 'HANDLER_TYPE' => 'script'));
    $setCode->execute(array('return array("value" => getGlobal("Lamp.level"), "method" => $params["method"]);'));
    check($scenario->runScript(1, array('method' => 'POST')) === array('value' => 0, 'method' => 'POST'), 'Реальный сценарий возвращает массив и видит штатные функции');
    $setCode->execute(array('echo "<p>scenario</p>";'));
    ob_start(); $result = $scenario->runScript(1, array()); $output = ob_get_clean();
    check($result === null && $output === '<p>scenario</p>', 'Реальный сценарий выводит HTML');
    foreach (array('throw new RuntimeException("scenario-failed");', 'missing_webhook_function();') as $code) {
        $setCode->execute(array($code));
        try { $scenario->runScript(1, array()); throw new LogicException('Ошибка сценария потеряна'); }
        catch (RuntimeException $error) { check($error->getMessage() === 'scenario-failed', 'Exception передаётся HTTP-границе'); }
        catch (Error $error) { check(str_contains($error->getMessage(), 'missing_webhook_function'), 'Error передаётся HTTP-границе'); }
    }
    HookContext::activate(array('ID' => 9, 'HANDLER_TYPE' => 'module'));
    $setCode->execute(array('throw new RuntimeException("module-hook-failed");'));
    try { $scenario->runScript(1, array()); throw new LogicException('Изменился контракт модульного webhook'); }
    catch (RuntimeException $error) { check($error->getMessage() === 'Fixture method error', 'Модульный webhook сохраняет прежний registerError'); }
    HookContext::reset();
    $setCode->execute(array('throw new RuntimeException("legacy-failed");'));
    try { $scenario->runScript(1, array()); throw new LogicException('Не вызван штатный обработчик ошибки'); }
    catch (RuntimeException $error) { check($error->getMessage() === 'Fixture method error', 'Обычный запуск сохраняет прежний registerError'); }
    check(count($GLOBALS['script_updates']) === 6, 'Все вызовы проходят штатное обновление диагностики сценария');
    echo 'API hook scenarios smoke: ' . $checks . " passed\n";
} finally {
    HookContext::reset();
    unset($setCode, $pdo, $GLOBALS['db']);
    (new ReflectionProperty(Core::class, 'services'))->setValue(null, array());
    gc_collect_cycles(); ApiFixture::remove($root);
}
