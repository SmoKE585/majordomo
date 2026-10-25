<?php
/** 2026-10-25 — реальные службы таймеров, цепочки и формы на SQLite без рабочего окружения. */
require __DIR__ . '/property_audit_smoke.php';
require_once dirname(__DIR__) . '/lib/core.class.php';
require_once __DIR__ . '/fixtures/scheduler.php';

use MajorDoMo\Core\{Core, Config, Database, ProcessRunner, PropertyAudit, Scheduler};

error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$fixtureRoot = sys_get_temp_dir() . '/mjd-scheduler-' . bin2hex(random_bytes(6));
if (!defined('ROOT')) define('ROOT', str_replace('\\', '/', dirname(__DIR__)) . '/');
if (!defined('ROOTHTML')) define('ROOTHTML', '/');
Config::boot($fixtureRoot);
register_shutdown_function(static function () use ($fixtureRoot): void {
    foreach (array('/cms/cached/config.php', '/cms/cached/config.php.lock') as $file) if (is_file($fixtureRoot . $file)) unlink($fixtureRoot . $file);
    foreach (array('/cms/cached', '/cms', '') as $dir) if (is_dir($fixtureRoot . $dir)) rmdir($fixtureRoot . $dir);
});
if (Config::get('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Нет изоляции');
createSchedulerFixture($pdo);
$adapter = new SchedulerTestConnection($pdo);
$db = new Database($adapter);
$audit = new PropertyAudit($db);
$scheduler = new Scheduler($db, new ProcessRunner(), $audit);
$services = new ReflectionProperty(Core::class, 'services');
$services->setValue(null, array('database' => $db, 'audit' => $audit, 'scheduler' => $scheduler));
function DebMes($message, $source = '', $level = '') { $GLOBALS['job_logs'][] = $message; }
function setEvalCode($code = '') {}
PropertyAudit::leave();
$trace = $audit->begin('method', 'Door.open');
$parent = $audit->stepStart('method', 'Door.open');
$id = $scheduler->schedule('Light-on', 'return false;', time());
$scheduled = $scheduler->get($id);
check($scheduled['TRACE_ID'] === $trace && (int)$scheduled['PARENT_EVENT_ID'] > 0, 'Таймер сохраняет цепочку создателя');
$audit->stepFinish($parent);
PropertyAudit::leave();
$scheduler->run(fn($job, $token) => $scheduler->execute($job['ID'], $token));
$failed = $scheduler->get($id);
check((int)$failed['ATTEMPTS'] === 1 && $failed['LAST_ERROR'] !== '' && !$failed['PROCESSED'], 'false оставляет ошибку и первую попытку');
check(strtotime($failed['RUNTIME']) >= time() + 4, 'Первый повтор отложен на пять секунд');
$pdo->exec("UPDATE jobs SET RUNTIME='2000-01-01',COMMANDS='throw new RuntimeException(\"expected\");' WHERE ID=$id");
$scheduler->run(fn($job, $token) => $scheduler->execute($job['ID'], $token));
check((int)$scheduler->get($id)['ATTEMPTS'] === 2 && strtotime($scheduler->get($id)['RUNTIME']) >= time() + 9, 'Исключение даёт второй повтор через десять секунд');
$pdo->exec("UPDATE jobs SET RUNTIME='2000-01-01' WHERE ID=$id");
$scheduler->run(fn($job, $token) => $scheduler->execute($job['ID'], $token));
check((int)$scheduler->get($id)['ATTEMPTS'] === 3 && (int)$scheduler->get($id)['EXPIRED'] === 1, 'Три неуспеха останавливают задание');
$scheduler->run(static function () { throw new LogicException('Четвёртая попытка запрещена'); });
$runs = $audit->trace($trace);
$executions = array_values(array_filter($runs[0]['events'], static fn($event) => $event['execution'] !== null));
check(count($executions) === 3 && $executions[0]['parent_event_id'] === (int)$scheduled['PARENT_EVENT_ID'], 'Попытки находятся в исходной цепочке с родительским шагом');
check($executions[0]['execution']['code'] === 'return false;', 'Сохраняется код на момент выполнения');
$ok = $scheduler->schedule('success', 'return true;', time());
$scheduler->run(fn($job, $token) => $scheduler->execute($job['ID'], $token));
check((int)$scheduler->get($ok)['PROCESSED'] === 1 && (int)$scheduler->get($ok)['ATTEMPTS'] === 1, 'Успешная команда не повторяется');
check(!$scheduler->execute($ok, 'stale')['success'], 'Старый токен не запускает обработанное задание');
$crash = $scheduler->schedule('crash', 'return true;', time());
$pdo->exec("UPDATE jobs SET ATTEMPTS=3,STARTED='2000-01-01',CLAIM_TOKEN='old' WHERE ID=$crash");
$scheduler->run(static function () { throw new LogicException('После трёх аварий нельзя повторять'); });
check((int)$scheduler->get($crash)['EXPIRED'] === 1 && (int)$scheduler->get($crash)['ATTEMPTS'] === 3, 'Аварийные попытки тоже ограничены');
$replace = $scheduler->schedule('replace', 'return 1;', time() + 60);
$next = $scheduler->schedule('replace', 'return 2;', time() + 120);
check($scheduler->get($replace) === null && $scheduler->exists('replace') === $next, 'Именованный таймер заменяется атомарно');
$unit = $db->beginUnit();
$scheduler->schedule('replace', 'return 3;', time());
$db->rollbackUnit($unit);
check($scheduler->exists('replace') === $next, 'Откат восстанавливает предыдущее задание');
check(count($scheduler->listing('failed')['rows']) === 2, 'Список показывает окончательные отказы');
check($scheduler->listing('', "replace' OR 1=1 --")['total'] === 0, 'Поиск не позволяет SQL-инъекцию');
$command = new MajorDoMo\Core\Console\Commands\JobsListCommand(fn() => $scheduler);
$stdout = fopen('php://memory', 'w+'); $stderr = fopen('php://memory', 'w+');
$output = new MajorDoMo\Core\Console\Output($stdout, $stderr);
$output->setJson(true);
$context = new MajorDoMo\Core\Console\Context(ROOT, new MajorDoMo\Core\Console\Application(ROOT));
check($command->execute(new MajorDoMo\Core\Console\Input(array('--status=failed'), $command), $output, $context) === 0, 'CLI читает задания без запуска');
check((new ReflectionProperty($output, 'data'))->getValue($output)['total'] === 2, 'CLI возвращает отказы в JSON-данных');
try {
    $command->execute(new MajorDoMo\Core\Console\Input(array('--status=invalid'), $command), $output, $context);
    throw new LogicException('Неизвестный статус принят');
} catch (InvalidArgumentException $expected) { check(true, 'CLI отклоняет неизвестный статус'); }
fclose($stdout); fclose($stderr);

// Реальный PHP-шаблон и обработчик формы; базовый модуль не поднимает загрузчик приложения.
class module {
    public $id = 0, $mode = '', $view_mode = '', $name, $title, $module_category, $result, $redirected = '';
    public function checkInstalled() {}
    public function parseLinks($value) { return $value; }
    public function redirect($url) { $this->redirected = $url; }
}
require ROOT . 'modules/jobs/jobs.class.php';
$session = new class {
    private array $values = array();
    public function has($key) { return isset($this->values[$key]); }
    public function get($key) { return $this->values[$key]; }
    public function set($key, $value) { $this->values[$key] = $value; }
};
$_GET = $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
unset($id, $mode, $view_mode);
$module = new jobs(); $module->run();
check(str_contains($module->result, 'md-jobs-table') && str_contains($module->result, 'data-md-trace-open'), 'Страница использует MDJUI и общий drawer цепочки');
$html = $module->result;
$before = (int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array('operation' => 'cancel', 'id' => (string)$next, '_token' => 'wrong');
$module->run();
check((int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn() === $before && $scheduler->get($next) !== null, 'Неверный CSRF не отменяет задание');
$_POST = array('operation' => 'add', '_token' => $session->get('jobs_csrf'), 'title' => '<script>test</script>', 'commands' => 'return true;', 'runin' => '1', 'runtype' => 'm');
$module->run();
check($module->redirected === '?saved=1' && $scheduler->exists('<script>test</script>') > 0, 'POST создаёт задание и делает redirect');
$_POST['runin'] = '-1'; $module->redirected = ''; $module->run();
check($module->redirected === '' && str_contains($module->result, '&lt;script&gt;test&lt;/script&gt;'), 'Отрицательная задержка отклоняется, введённый текст экранируется');
$_POST['runin'] = '1'; $_POST['commands'] = 'invalid php !!!'; $module->run();
check($module->redirected === '' && str_contains($module->result, 'Ошибка PHP:'), 'Ошибочная PHP-команда не ставится в очередь');
$response = $module->traceResponse($scheduler, $ok);
check($response['status'] === 'ok' && count($response['runs']) === 1, 'Цепочка доступна и без изменения свойства');
if (in_array('--export-ui', $argv, true)) {
    $file = tempnam(sys_get_temp_dir(), 'mjd-jobs-ui-');
    file_put_contents($file, json_encode(array('html' => $html, 'trace' => $response), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    echo 'UI_FIXTURE=' . $file . PHP_EOL;
}
echo 'Scheduler smoke passed (SQLite, no external services).' . PHP_EOL;
