<?php
/**
 * Template render smoke test (no database required).
 *
 * Renders every template that does not need a database — i.e. the ones whose
 * include graph has no [#module ...#] and no missing [#inc ...#] — through the
 * jTemplate engine in production mode (DEBUG_MODE off), with synthetic data
 * derived from the placeholders.
 *
 * It catches the class of bugs that only show up in one mode: unrendered tags,
 * broken includes, warnings from the engine, and stray debug output.
 *
 * Run: php tests/template_render_smoke.php
 */

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__) . '/');
}
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$GLOBALS['renderDumps'] = 0;
$GLOBALS['renderWarnings'] = array();

function startMeasure($name) {}
function endMeasure($name, $save = 0) {}
function setEvalCode($code = '') {}
function dprint($data = 0, $stop = 1, $show_history = 0)
{
    $GLOBALS['renderDumps']++;
}

require ROOT . 'lib/core.class.php';
// PHP config partials must never read the working installation's .env.
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('DB_HOST=');
putenv('DB_NAME=');
putenv('USE_REDIS=false');
$templateConfigRoot = sys_get_temp_dir() . '/mjd-template-config-' . bin2hex(random_bytes(6));
mkdir($templateConfigRoot, 0700, true);
\MajorDoMo\Core\Config::boot($templateConfigRoot);
register_shutdown_function(static function () use ($templateConfigRoot): void {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path)) { foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name); rmdir($path); }
        else unlink($path);
    };
    $remove($templateConfigRoot);
});
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

/**
 * Modules need the database and are covered by the HTTP smoke checks, so the
 * render test replaces their tags with a marker instead of running them.
 */
class ProbeTemplate extends \MajorDoMo\Core\Template\JTemplate
{
    public function parseModules(&$res, &$hash, $dir)
    {
        $res = preg_replace('/\[#module .*?#\]/', '<!--module-->', $res);
    }
}

/**
 * Build data for every placeholder the template knows about, so tags are
 * consumed instead of being left in the output.
 */
function synthesizeData(string $text): array
{
    $data = array();

    // Loops: [#begin NAME#] ... [#end NAME#] with <#NAME.FIELD#> inside.
    preg_match_all('/\[#begin (\w+?)#\]/', $text, $blocks);
    foreach (array_unique($blocks[1]) as $name) {
        preg_match_all('/<#' . preg_quote($name, '/') . '\.(\w+?)#>/', $text, $fields);
        $item = array();
        foreach (array_unique($fields[1]) as $field) {
            $item[$field] = 'v';
        }
        $data[$name] = $item === array() ? array() : array($item, $item);
    }

    // [#NAME.FIELD#] hashes.
    preg_match_all('/\[#(\w+?)\.(\w+?)#\]/', $text, $dotted);
    foreach ($dotted[1] as $index => $name) {
        if (!isset($data[$name])) {
            $data[$name] = array($dotted[2][$index] => 'v');
        }
    }

    // Scalar [#NAME#] placeholders.
    preg_match_all('/\[#(\w+?)#\]/', $text, $plain);
    foreach ($plain[1] as $name) {
        if (!isset($data[$name])) {
            $data[$name] = 'v';
        }
    }

    // Identifiers that appear only inside [#if ...#] conditions.
    preg_match_all('/\[#if (.+?)#\]/', $text, $conditions);
    foreach ($conditions[1] as $condition) {
        if (preg_match_all('/\b([A-Z][A-Z0-9_]{2,})\b/', $condition, $identifiers)) {
            foreach ($identifiers[1] as $identifier) {
                if (!isset($data[$identifier])) {
                    $data[$identifier] = 'v';
                }
            }
        }
    }

    return $data;
}

$templatesRoot = ROOT . 'templates';
$files = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templatesRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (preg_match('/\.(html|tpl)$/', $file->getFilename())) {
        $files[str_replace('\\', '/', $file->getPathname())] = (string)file_get_contents($file->getPathname());
    }
}

$includes = array();
$needsModules = array();
foreach ($files as $path => $content) {
    preg_match_all('/\[#inc\s+(\S+?)#\]/', $content, $matches);
    $includes[$path] = $matches[1];
    $needsModules[$path] = (bool)preg_match('/\[#module\s/', $content);
}

/**
 * @return array{0: bool, 1: bool} [uses modules, has unresolved includes]
 */
function inspectTemplate(string $path, array $files, array $includes, array $needsModules, array &$seen): array
{
    if (isset($seen[$path])) {
        return array(false, false);
    }
    $seen[$path] = true;

    $module = $needsModules[$path];
    $missing = false;
    foreach ($includes[$path] as $child) {
        $childPath = dirname($path) . '/' . $child;
        if (!isset($files[$childPath])) {
            $missing = true;
            continue;
        }
        list($childModule, $childMissing) = inspectTemplate($childPath, $files, $includes, $needsModules, $seen);
        $module = $module || $childModule;
        $missing = $missing || $childMissing;
    }

    return array($module, $missing);
}

// Warnings from the engine are regressions too.
set_error_handler(static function (int $severity, string $message, string $file, int $line) {
    if ((error_reporting() & $severity) === 0) {
        return false; // respect legacy @-suppression
    }
    $GLOBALS['renderWarnings'][] = $message . ' in ' . basename($file) . ':' . $line;
    return true;
});

$rendered = 0;
$skipped = 0;
$problems = array();
foreach ($files as $path => $content) {
    $seen = array();
    list($module, $missing) = inspectTemplate($path, $files, $includes, $needsModules, $seen);
    if ($module || $missing) {
        $skipped++;
        continue;
    }

    $name = substr($path, strlen($templatesRoot) + 1);
    $GLOBALS['renderDumps'] = 0;
    $GLOBALS['renderWarnings'] = array();

    try {
        $data = synthesizeData($content);
        $template = new ProbeTemplate($path, $data);
        $output = (string)$template->result;
    } catch (\Throwable $e) {
        $problems[] = $name . ': ' . get_class($e) . ': ' . $e->getMessage();
        continue;
    }

    $rendered++;

    // [#...#] is the documented "content goes here" placeholder of the block
    // wrappers (templates/blocks/*.html), not an unrendered variable.
    $checked = str_replace('[#...#]', '', $output);

    if (str_contains($checked, '[#')) {
        $problems[] = $name . ': unrendered [# tag in output';
    }
    if (preg_match('/<#[A-Za-z_]/', $checked, $leftover)) {
        $problems[] = $name . ': unrendered <# tag in output';
    }
    if (str_contains($checked, 'Cannot find file')) {
        $problems[] = $name . ': include was not found';
    }
    if ($GLOBALS['renderDumps'] > 0) {
        $problems[] = $name . ': dprint() called';
    }
    if ($GLOBALS['renderWarnings'] !== array()) {
        $problems[] = $name . ': ' . implode(' | ', array_slice($GLOBALS['renderWarnings'], 0, 3));
    }
}

restore_error_handler();

check($rendered > 60, 'rendered a meaningful number of templates (' . $rendered . ')');
check($problems === array(), 'templates render cleanly: ' . implode('; ', array_slice($problems, 0, 10)));

echo 'Rendered ' . $rendered . ' templates, skipped ' . $skipped . ' (modules/missing includes)' . PHP_EOL;

if ($failures) {
    echo 'Template render smoke tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Template render smoke tests passed' . PHP_EOL;
