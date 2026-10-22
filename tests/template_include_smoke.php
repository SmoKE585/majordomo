<?php
/**
 * Template include smoke test (no database required).
 *
 * The engine supports exactly one template syntax (jTemplates) plus PHP
 * partials: `[#inc file.php#]` includes the file with the template data in
 * `$data` and captures its output. Smarty (.tpl) is gone — this test guards
 * both facts and that the legacy class names still resolve.
 *
 * Run: php tests/template_include_smoke.php
 */

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__) . '/');
}
// Production value: the mode where the old .tpl branch used to dump the page.
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$GLOBALS['includeDumps'] = 0;

function startMeasure($name) {}
function endMeasure($name, $save = 0) {}
function dprint($data = 0, $stop = 1, $show_history = 0)
{
    // Must never be reached while rendering includes.
    $GLOBALS['includeDumps']++;
}

require ROOT . 'lib/jTemplate.class.php';
require ROOT . 'lib/parser.class.php';

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

check(class_exists('jTemplate', false), 'legacy global class jTemplate is still available');
check(class_exists('parser', false), 'legacy global class parser is still available');
$empty = array();
check(
    is_a(new jTemplate('', $empty), 'MajorDoMo\\Core\\Template\\JTemplate'),
    'legacy jTemplate is an alias of MajorDoMo\\Core\\Template\\JTemplate'
);
check(
    get_class(new parser('', $empty)) === 'MajorDoMo\\Core\\Template\\Parser',
    'legacy parser is an alias of MajorDoMo\\Core\\Template\\Parser'
);

$tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/mjd-tpl-' . bin2hex(random_bytes(4));
mkdir($tmp);
file_put_contents(
    $tmp . '/partial.php',
    '<?php echo "<b>" . htmlspecialchars((string)($data["TITLE"] ?? ""), ENT_QUOTES, "UTF-8") . "</b>";'
);
file_put_contents($tmp . '/page.html', '[#inc partial.php#]');

$data = array('TITLE' => 'Классы <script>');
$template = new jTemplate($tmp . '/page.html', $data);
$result = (string)$template->result;

check($result === '<b>Классы &lt;script&gt;</b>', 'PHP partial is rendered with DEBUG_MODE off and gets $data');
check($GLOBALS['includeDumps'] === 0, 'includes do not dump data with dprint()');
check(strpos($result, '[#inc') === false, 'include tag is consumed');

$data2 = array('TITLE' => 'Классы <script>');
$core = new \MajorDoMo\Core\Template\JTemplate($tmp . '/page.html', $data2);
check((string)$core->result === $result, 'namespaced engine renders the same output as the alias');

// Smarty support is gone: a .tpl include must not be executed as a template.
file_put_contents($tmp . '/legacy.tpl', '<b>{$TITLE}</b>');
file_put_contents($tmp . '/tpl_page.html', '[#inc legacy.tpl#]');
$tplData = array('TITLE' => 'Классы');
$tplTemplate = new jTemplate($tmp . '/tpl_page.html', $tplData);
$tplResult = (string)$tplTemplate->result;
check(
    strpos($tplResult, 'Template format is not supported') !== false,
    '.tpl include is reported as unsupported'
);
check(strpos($tplResult, '{$TITLE}') === false, '.tpl include is not rendered as template text');

$parserData = array();
$parser = new \MajorDoMo\Core\Template\Parser('layout.tpl', $parserData);
check(
    strpos((string)$parser->result, 'Smarty templates are not supported') !== false,
    '.tpl is reported as unsupported'
);

@unlink($tmp . '/partial.php');
@unlink($tmp . '/page.html');
@unlink($tmp . '/legacy.tpl');
@unlink($tmp . '/tpl_page.html');
@rmdir($tmp);

if ($failures) {
    echo 'Template include smoke tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Template include smoke tests passed' . PHP_EOL;
