<?php
/** 2026-10-25 — формы из реального шаблона закрывают и удаляют записи с валидным CSRF. */
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('USE_REDIS=false');
define('ROOT', str_replace('\\', '/', dirname(__DIR__)) . '/');
define('ROOTHTML', '/');
require ROOT . 'lib/core.class.php';
use MajorDoMo\Core\{Config, Core};
$root = sys_get_temp_dir() . '/mjd-error-actions-' . bin2hex(random_bytes(6));
Config::boot($root);
$session = Core::session($root);
$session->set('system_errors_csrf', str_repeat('a', 64));
$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Изоляция не подтверждена');
$pdo->exec('CREATE TABLE system_errors (ID INTEGER PRIMARY KEY, CODE TEXT, ACTIVE INTEGER); CREATE TABLE system_errors_data (ID INTEGER PRIMARY KEY, ERROR_ID INTEGER)');
#[AllowDynamicProperties]
class module { public function checkInstalled() {} }
class FixtureRedirect extends RuntimeException {}
function gr($key, $type = '') { return $_REQUEST[$key] ?? ''; }
function SQLSelectOne($sql) { return $GLOBALS['pdo']->query($sql)->fetch(PDO::FETCH_ASSOC) ?: array(); }
function SQLExec($sql) { return $GLOBALS['pdo']->exec($sql); }
function startMeasure($name) {} function endMeasure($name) {} function setEvalCode($code = '') {}
require ROOT . 'modules/system_errors/system_errors.class.php';
class ErrorActionsFixture extends system_errors {
    public function __construct() { $this->view_mode = ''; $this->mode = ''; $this->data_source = 'system_errors'; $this->id = 1; }
    public function search_system_errors(&$out) {}
    public function redirect($url, $owner = '') { throw new FixtureRedirect($url); }
}
$checks = 0;
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
$data = array('CSRF_TOKEN' => $session->get('system_errors_csrf'), 'RESULT' => array(array(
    'ID' => 1, 'CODE' => 'fixture', 'TITLE' => 'Ошибка', 'TITLE_DISPLAY' => 'Ошибка', 'STATUS_STATE' => 'new', 'STATUS_LABEL' => 'Новая', 'SOURCE' => 'ядро', 'ACTIVE' => 1,
)), 'ERRORS_TOTAL' => 1);
try {
    $html = (new MajorDoMo\Core\Template\JTemplate(ROOT . 'templates/system_errors/system_errors_search_admin.html', $data))->result;
    $dom = new DOMDocument(); $old = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET); libxml_clear_errors(); libxml_use_internal_errors($old);
    $forms = array();
    foreach ($dom->getElementsByTagName('form') as $form) {
        $values = array();
        foreach ($form->getElementsByTagName('input') as $input) $values[$input->getAttribute('name')] = $input->getAttribute('value');
        if (in_array($values['view_mode'] ?? '', array('clear_system_errors', 'delete_system_errors'), true)) {
            check(($values['_token'] ?? '') === $session->get('system_errors_csrf'), 'CSRF-токен не должен теряться в строке таблицы: ' . $values['view_mode']);
            $forms[$values['view_mode']] = $values;
        }
    }
    check(count($forms) === 2, 'В строке есть формы закрытия и удаления');
    foreach ($forms as $operation => $values) {
        $pdo->exec("DELETE FROM system_errors; DELETE FROM system_errors_data; INSERT INTO system_errors VALUES(1,'fixture',3); INSERT INTO system_errors_data VALUES(1,1)");
        $_SERVER['REQUEST_METHOD'] = 'POST'; $_REQUEST = $_POST = $values;
        $module = new ErrorActionsFixture(); $module->view_mode = $operation; $out = array();
        try { $module->admin($out); } catch (FixtureRedirect $done) {}
        check(empty($out['ERROR_MSG']), 'Реальная форма принимается контроллером: ' . $operation);
        if ($operation === 'clear_system_errors') {
            check((int)$pdo->query('SELECT ACTIVE FROM system_errors WHERE ID=1')->fetchColumn() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM system_errors_data')->fetchColumn() === 1, 'Закрытие сохраняет историю');
        } else check((int)$pdo->query('SELECT COUNT(*) FROM system_errors')->fetchColumn() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM system_errors_data')->fetchColumn() === 0, 'Удаляются ошибка и её история');
    }
    $pdo->exec("INSERT INTO system_errors VALUES(1,'fixture',3)");
    foreach (array(array('GET', $session->get('system_errors_csrf')), array('POST', 'wrong-token')) as [$method, $token]) {
        $_SERVER['REQUEST_METHOD'] = $method; $_REQUEST = array('_token' => $token);
        $module = new ErrorActionsFixture(); $module->view_mode = 'clear_system_errors'; $out = array(); $module->admin($out);
        check(!empty($out['ERROR_MSG']) && (int)$pdo->query('SELECT ACTIVE FROM system_errors WHERE ID=1')->fetchColumn() === 3, 'GET и поддельный токен не меняют данные');
    }
} finally {
    session_destroy();
    $resolved = realpath($root);
    if ($resolved && dirname($resolved) === realpath(sys_get_temp_dir()) && str_starts_with(basename($resolved), 'mjd-error-actions-')) {
        $remove = static function ($path) use (&$remove): void {
            if (!is_dir($path)) { unlink($path); return; }
            foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
            rmdir($path);
        };
        $remove($resolved);
    }
}
echo 'System error actions smoke: ' . $checks . ' passed; SQLite :memory:, реальный шаблон и обработчик.' . PHP_EOL;
