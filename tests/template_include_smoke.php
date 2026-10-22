<?php
/**
 * Smarty [#inc ...#] include smoke test (no database required).
 *
 * Regression guard: with DEBUG_MODE off the template engine used to call
 * dprint() for .tpl includes, so on production the whole page was replaced by
 * a print_r() of the module data (e.g. /panel/classes.html). The include must
 * render through Smarty and never dump the data.
 *
 * Run: php tests/template_include_smoke.php
 */

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__) . '/');
}
// Production value: this is the mode that used to dump the page.
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}

// Smarty 3.1 is not PHP 8.4-clean. Its deprecation notices are printed while
// fetch() renders and would be captured by its output buffer into the result.
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$GLOBALS['includeDumps'] = 0;

function startMeasure($name) {}
function endMeasure($name, $save = 0) {}
function dprint($data = 0, $stop = 1, $show_history = 0)
{
    // Must never be reached for .tpl includes.
    $GLOBALS['includeDumps']++;
}

require ROOT . 'lib/jTemplate.class.php';

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

$tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/mjd-tpl-' . bin2hex(random_bytes(4));
mkdir($tmp);
file_put_contents($tmp . '/include.tpl', '<b>{$TITLE}</b>');
file_put_contents($tmp . '/page.html', '[#inc include.tpl#]');

$data = array('TITLE' => 'Классы');
$template = new jTemplate($tmp . '/page.html', $data);
$result = (string)$template->result;

check($result === '<b>Классы</b>', 'Smarty include is rendered with DEBUG_MODE off');
check($GLOBALS['includeDumps'] === 0, '.tpl include does not dump data with dprint()');
check(strpos($result, '[#inc') === false, 'include tag is consumed');

@unlink($tmp . '/include.tpl');
@unlink($tmp . '/page.html');
@rmdir($tmp);

if ($failures) {
    echo 'Template include smoke tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Template include smoke tests passed' . PHP_EOL;
