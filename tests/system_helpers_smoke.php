<?php

/** Public helper contracts; SQLite in memory, fake core services, no application boot. */
namespace MajorDoMo\Core {
    final class Core
    {
        public static object $objects;
        public static function objects() { return self::$objects; }
    }
}

namespace {
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
    putenv('USE_REDIS=false');
    date_default_timezone_set('UTC');
    error_reporting(E_ALL);
    set_error_handler(static function ($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        throw new RuntimeException('Test isolation was not established');
    }
    $project = dirname(__DIR__);
    define('ROOT', str_replace('\\', '/', $project) . '/');
    $contracts = json_decode(file_get_contents(__DIR__ . '/fixtures/system_helpers.json'), true, 512, JSON_THROW_ON_ERROR);
    $checks = 0;
    function helpersCheck($condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
        $GLOBALS['checks']++;
    }
    function helperContract(ReflectionFunction $function): array
    {
        $parameters = array();
        foreach ($function->getParameters() as $parameter) {
            $item = array('name' => $parameter->getName(), 'type' => (string)$parameter->getType(),
                'reference' => $parameter->isPassedByReference(), 'variadic' => $parameter->isVariadic());
            if ($parameter->isDefaultValueAvailable()) $item['default'] = $parameter->getDefaultValue();
            $parameters[] = $item;
        }
        return array('parameters' => $parameters, 'return' => (string)$function->getReturnType(), 'reference' => $function->returnsReference());
    }
    $legacy = $argv[1] ?? '';
    if ($legacy !== '') {
        if ($legacy === 'request') {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET = array('helper_fixture' => 'request-value', '_ENV' => 'blocked');
            $_POST = array();
            $_REQUEST = $_GET;
            require $project . '/lib/general.class.php';
            helpersCheck(function_exists('stripit'), 'Legacy request helper remains available');
            helpersCheck($GLOBALS['helper_fixture'] === 'request-value' && !isset($_GET['_ENV']), 'Request initialization is preserved');
            $values = array('nested' => array("a\\'b"));
            stripit($values);
            helpersCheck($values === array('nested' => array("a'b")), 'Legacy stripit still recurses');
        } else {
            helpersCheck(in_array($legacy, array_column($contracts, 'source'), true), 'Known compatibility path');
            require $project . '/lib/' . $legacy;
            foreach ($contracts as $name => $contract) {
                if ($contract['source'] === $legacy) helpersCheck(function_exists($name), 'Legacy path exports ' . $name);
            }
        }
    }
    require_once $project . '/lib/core/Helpers/bootstrap.php';
    require_once $project . '/lib/core/Helpers/bootstrap.php';
    foreach (array_unique(array_column($contracts, 'source')) as $file) require_once $project . '/lib/' . $file;
    helpersCheck(!isset($GLOBALS['db']), 'Loading helpers never creates a database connection');
    foreach ($contracts as $name => $contract) {
        helpersCheck(function_exists($name), 'Public name remains available: ' . $name);
        $function = new ReflectionFunction($name);
        helpersCheck($function->getName() === $name, 'Exact declaration name is preserved: ' . $name);
        helpersCheck(helperContract($function) === array_intersect_key($contract, array_flip(array('parameters', 'return', 'reference'))), 'Parameters, defaults and types are preserved: ' . $name);
        helpersCheck(str_contains(str_replace('\\', '/', $function->getFileName()), '/lib/core/Helpers/'), 'Implementation is centralized: ' . $name);
    }
    foreach (get_defined_functions()['user'] as $name) {
        $function = new ReflectionFunction($name);
        if (str_contains(str_replace('\\', '/', $function->getFileName()), '/lib/core/Helpers/')) {
            helpersCheck(isset($contracts[$function->getName()]), 'New public helpers must be added to the contract and reference: ' . $name);
        }
    }
    if ($legacy !== '') {
        echo 'OK: compatibility entry ' . $legacy . PHP_EOL;
        exit;
    }
    $service = new class {
        public array $calls = array();
        public $result;
        public function value(...$arguments) { $this->calls[] = array('value', $arguments); return $this->result; }
        public function setValue(...$arguments) { $this->calls[] = array('setValue', $arguments); return $this->result; }
        public function call(...$arguments) { $this->calls[] = array('call', $arguments); return $this->result; }
        public function get($name) { return $this; }
        public function callMethod(...$arguments) { $this->calls[] = array('command', $arguments); return $this->result; }
    };
    \MajorDoMo\Core\Core::$objects = $service;
    foreach (array(false, 0, '', null, array('answer' => 42)) as $result) {
        $service->result = $result;
        helpersCheck(getGlobal('Lamp.status') === $result && gg('Lamp.status') === $result, 'Read aliases preserve exact result');
        helpersCheck(setGlobal('Lamp.status', 0, 1, 'test') === $result && sg('Lamp.status', 0, 1, 'test') === $result, 'Write aliases preserve exact result');
        helpersCheck(callMethod('Lamp.on', array('level' => 5)) === $result && cm('Lamp.on', array('level' => 5)) === $result && runMethod('Lamp.on', array('level' => 5)) === $result, 'Method aliases preserve exact result');
        helpersCheck(callMethodSafe('Lamp.on', array('level' => 5)) === $result, 'Safe method passes through its service result');
        $pattern_matched = false;
        helpersCheck(processCommand('fixture') === $result, 'Synchronous command returns its method result');
    }
    helpersCheck(in_array(array('setValue', array('Lamp.status', 0, 1, 'test')), $service->calls, true), 'Write aliases preserve no_linked and source');
    helpersCheck(in_array(array('call', array('Lamp.on', array('level' => 5), false)), $service->calls, true), 'Synchronous method is not converted to asynchronous execution');
    helpersCheck(in_array(array('call', array('Lamp.on', array('level' => 5), true)), $service->calls, true), 'Safe method keeps its separate mode');
    $fixture = sys_get_temp_dir() . '/mjd-helpers-' . bin2hex(random_bytes(8));
    mkdir($fixture . '/scripts', 0700, true);
    define('DIR_MODULES', $fixture . '/');
    file_put_contents($fixture . '/scripts/scripts.class.php', <<<'PHP'
<?php
class scripts {
    public function runScript($id, $params) {
        $GLOBALS['script_calls'][] = array($id, $params);
        if ($GLOBALS['script_result'] instanceof Throwable) throw $GLOBALS['script_result'];
        return $GLOBALS['script_result'];
    }
}
PHP
    );
    try {
        foreach (array(false, 0, '', null, array('answer' => 42)) as $result) {
            $GLOBALS['script_result'] = $result;
            foreach (array('runScript', 'callScript', 'rs') as $function) {
                helpersCheck($function('Fixture', array('x' => 1)) === $result, 'Script alias returns exact synchronous result: ' . $function);
                helpersCheck(end($GLOBALS['script_calls']) === array('Fixture', array('x' => 1)), 'Script parameters reach the executor');
            }
        }
        $GLOBALS['script_result'] = 42;
        callScript('Fixture');
        helpersCheck(end($GLOBALS['script_calls']) === array('Fixture', ''), 'callScript retains its own default');
        rs('Fixture');
        helpersCheck(end($GLOBALS['script_calls']) === array('Fixture', 0), 'rs retains its own default');
        $failure = new RuntimeException('fixture script failure');
        $GLOBALS['script_result'] = $failure;
        try { callScript('Fixture'); throw new LogicException('Exception was swallowed'); }
        catch (RuntimeException $error) { helpersCheck($error === $failure, 'Synchronous alias preserves execution exceptions'); }
    } finally {
        unlink($fixture . '/scripts/scripts.class.php');
        rmdir($fixture . '/scripts');
        rmdir($fixture);
    }
    // The existing SQL adapter is exercised against SQLite, never the real driver.
    class mysql {
        public function __construct(public PDO $pdo) {}
        public function DbSafe($value) { return str_replace("'", "''", (string)$value); }
        public function SelectOne($query) { return $this->pdo->query($query)->fetch(PDO::FETCH_ASSOC) ?: array(); }
        public function Exec($query, $ignoreErrors = false) { return $this->pdo->exec($query) !== false; }
        public function Insert($table, &$record) {
            $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($record)) . ') VALUES (' . implode(',', array_fill(0, count($record), '?')) . ')')->execute(array_values($record));
            return (int)$this->pdo->lastInsertId();
        }
    }
    $db = new mysql($pdo);
    $pdo->exec('CREATE TABLE jobs (ID INTEGER PRIMARY KEY AUTOINCREMENT, TITLE TEXT, COMMANDS TEXT, RUNTIME TEXT, EXPIRE TEXT, PROCESSED INTEGER DEFAULT 0)');
    helpersCheck(timeOutExists('missing') === 0, 'Missing timeout returns zero without a PHP warning');
    $id = addScheduledJob("fixture's job", 'return 42;', time() + 60);
    helpersCheck($id > 0 && timeOutExists("fixture's job") === $id, 'Schedule returns its id and escapes its title');
    $next = addScheduledJob("fixture's job", 'return 7;', time() + 120);
    helpersCheck($next !== $id && $pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn() === 1, 'Schedule still replaces jobs with the same title');
    helpersCheck(clearTimeOut("fixture's job") === true && timeOutExists("fixture's job") === 0, 'Timeout deletion returns the database result');
    $script_started_time = null;
    $id = setTimeOut('timer', 'return 5;', 20);
    helpersCheck($id > 0 && timeOutExists('timer') === $id, 'Timeout creation retains scheduling behavior');
    helpersCheck(deleteScheduledJob($id) === true && timeOutExists('timer') === 0, 'Delete by id returns the database result');
    helpersCheck(clearScheduledJob('missing') === true, 'Deleting an absent job preserves successful SQL result');
    $items = array(array('TITLE' => 'z', 'id' => 1), array('TITLE' => 'a', 'id' => 2), array('TITLE' => 'a', 'id' => 3));
    helpersCheck(array_column(mysort_array($items), 'id') === array(2, 3, 1), 'Sorting handles any positive strcmp result and keeps equal items stable');
    helpersCheck(mysort_array(array()) === array(), 'Empty sorting stays valid');
    helpersCheck(mysort_array(array(array('value' => 'b'), array('value' => 'a')), 'value') === array(array('value' => 'a'), array('value' => 'b')), 'Sorting respects a custom field');
    helpersCheck(preg_match('/^[a-z0-9]{5}$/D', genPassword()) === 1, 'Default password generation works on PHP 8');
    helpersCheck(preg_match('/^[a-z0-9]{16}$/D', genPassword('16')) === 1, 'Password generation retains lowercase alphanumeric output and accepts numeric strings');
    helpersCheck(genPassword(0) === '', 'Zero length returns an empty password');
    try { genPassword(-1); throw new LogicException('Negative length was accepted'); }
    catch (InvalidArgumentException $error) { helpersCheck(true, 'Negative password length is rejected explicitly'); }
    foreach (array('1' => 1, '01' => '01', '-2' => -2, '1.25' => 1.25, 'text' => 'text') as $input => $expected) {
        helpersCheck(returnTypedValue((string)$input) === $expected, 'Property value conversion stays compatible');
    }
    $reference = file_get_contents($project . '/docs/user/system-functions.md');
    foreach ($contracts as $name => $contract) helpersCheck(str_contains($reference, '`' . $contract['signature'] . '`'), 'Reference includes exact public signature: ' . $name);
    require_once $project . '/lib/knowledge_base.php';
    $article = (new MDKnowledgeBase($project . '/docs', '/'))->document('user/system-functions');
    helpersCheck(is_array($article) && $article['audience'] === array('user', 'agent'), 'Reference is discoverable by users and agents in the knowledge base');
    helpersCheck(substr_count($article['html'], '<table class="md-prose__table">') === 43, 'Every helper group renders as a knowledge base table');
    helpersCheck(count($article['toc']) >= 43 && str_contains($article['html'], 'callScript'), 'Reference renders with navigation and examples');
    foreach (array_merge(array_unique(array_column($contracts, 'source')), array('request')) as $entry) {
        $process = proc_open(array(PHP_BINARY, __FILE__, $entry), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $project);
        if (!is_resource($process)) throw new RuntimeException('Cannot start compatibility fixture');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        helpersCheck($exit === 0 && $errors === '', 'Isolated compatibility load failed: ' . $entry . ': ' . $output . $errors);
    }
    echo 'OK: ' . $checks . ' helper checks; ' . count($contracts) . ' signatures and '
        . (count(array_unique(array_column($contracts, 'source'))) + 1) . ' isolated legacy/request entry points' . PHP_EOL;
}
