<?php
/**
 * Template layer surface smoke test (no database required).
 *
 * Guards the invariants of the single jTemplate engine (plus PHP partials), so
 * a broken include or a stray debug dump is caught before a page does it in
 * production:
 *
 *   - every [#inc file#] resolves (known legacy dead links are allow-listed);
 *   - every [#module name="x"#] has modules/x/x.class.php;
 *   - [#begin X#] has a matching [#end X#];
 *   - no print_r()/var_dump()/dprint() left in templates;
 *   - no .tpl/.xslt templates (both formats are reported as unsupported);
 *   - the parser reports removed formats gracefully instead of crashing;
 *   - legacy class names still resolve to the namespaced core classes.
 *
 * Run: php tests/template_surface_smoke.php
 */

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__) . '/');
}
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

function startMeasure($name) {}
function endMeasure($name, $save = 0) {}
function setEvalCode($code = '') {}
function dprint($data = 0, $stop = 1, $show_history = 0)
{
    $GLOBALS['surfaceDumps']++;
}

require ROOT . 'lib/core.class.php';
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

/**
 * Legacy dead links. They are `[#inc ...#]` calls inside frontend/view branches
 * of modules that were redesigned; the target templates were deleted, so the
 * branch renders an HTML comment. Keep them out of the failure list, but fail on
 * any *new* pair. The proper cleanup is part of the old-frontend removal stage.
 *
 * @return array<string, array<int, string>> template => include targets
 */
function knownMissingIncludes(): array
{
    return array(
        'classes/classes.html' => array('action_usual.html'),
        'events/events.html' => array('action_usual.html'),
        'history/history.html' => array('action_usual.html'),
        'jobs/jobs.html' => array('action_usual.html'),
        'locations/locations.html' => array('action_usual.html'),
        'methods/methods.html' => array('action_usual.html'),
        'mqtt/mqtt.html' => array('action_usual.html'),
        'pinghosts/action_usual.html' => array('pinghosts_view.html'),
        'properties/properties.html' => array('action_usual.html'),
        'pvalues/action_admin.html' => array('pvalues_view.html'),
        'pvalues/pvalues.html' => array('action_usual.html'),
        'saverestore/saverestore.html' => array('action_usual.html'),
        'scripts/action_usual.html' => array('scripts_view.html'),
        'settings/action_admin.html' => array('settings_edit.html', 'settings_view.html'),
        'settings/action_usual.html' => array('settings_edit.html', 'settings_view.html'),
        'shoutbox/action_admin.html' => array('shouts_edit.html', 'shouts_view.html'),
        'shoutbox/action_usual.html' => array('shouts_edit.html', 'shouts_view.html'),
        'system_errors/system_errors.html' => array('action_usual.html'),
    );
}

/**
 * Modules that are optional / installed from the market. The legacy frontend
 * dispatcher (templates/actions.html) references them by action name; when a
 * module is absent the tag renders a "Module not found" notice instead of
 * breaking the page, so it must not fail this test.
 *
 * @return array<string, array<int, string>> template => module names
 */
function knownOptionalModules(): array
{
    return array(
        'actions.html' => array('products', 'tdwiki'),
    );
}

// ---------------------------------------------------------------- templates
$root = ROOT . 'templates';
$templates = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (preg_match('/\.(html|tpl|xslt)$/', $file->getFilename())) {
        $templates[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $file->getPathname();
    }
}

$knownMissing = knownMissingIncludes();
$missing = array();
$badModules = array();
$unbalanced = array();
$dumps = array();
$unsupportedFormats = array();

foreach ($templates as $name => $path) {
    if (preg_match('/\.(tpl|xslt)$/', $name)) {
        $unsupportedFormats[] = $name;
        continue;
    }

    $content = (string)file_get_contents($path);
    $dir = dirname($name) === '.' ? '' : dirname($name) . '/';

    preg_match_all('/\[#inc\s+(\S+?)#\]/', $content, $includes);
    foreach ($includes[1] as $include) {
        $includePath = $root . '/' . $dir . $include;
        if (!is_file($includePath) && !in_array($include, $knownMissing[$name] ?? array(), true)) {
            $missing[] = $name . ' => ' . $include;
        }
    }

    preg_match_all('/\[#module (.*?)#\]/', $content, $modules);
    foreach ($modules[1] as $attributes) {
        preg_match_all('/(\w+)="(.*?)"/', $attributes, $pairs, PREG_SET_ORDER);
        $moduleName = '';
        foreach ($pairs as $pair) {
            if ($pair[1] === 'name') {
                $moduleName = $pair[2];
            }
        }
        // Dynamic names (<#ACTION#>) are resolved at render time.
        if ($moduleName === '' || str_contains($moduleName, '<#')) {
            continue;
        }
        $optionalModules = knownOptionalModules()[$name] ?? array();
        if (in_array($moduleName, $optionalModules, true)) {
            continue;
        }
        if (!is_file(ROOT . 'modules/' . $moduleName . '/' . $moduleName . '.class.php')) {
            $badModules[] = $name . ' => ' . $moduleName;
        }
    }

    preg_match_all('/\[#begin (.*?)#\]/', $content, $begins);
    preg_match_all('/\[#end (.*?)#\]/', $content, $ends);
    $beginCount = array_count_values(array_map('trim', $begins[1]));
    $endCount = array_count_values(array_map('trim', $ends[1]));
    foreach ($beginCount + $endCount as $blockName => $_) {
        if (($beginCount[$blockName] ?? 0) !== ($endCount[$blockName] ?? 0)) {
            $unbalanced[] = $name . ' => ' . $blockName;
        }
    }

    if (preg_match('/(print_r|var_dump|var_export|dprint)\s*\(/', $content)) {
        $dumps[] = $name;
    }
}

check($templates !== array(), 'templates directory is readable');
check(
    $unsupportedFormats === array(),
    'no .tpl/.xslt templates (unsupported formats): ' . implode(', ', $unsupportedFormats)
);
check($badModules === array(), '[#module#] targets exist: ' . implode(', ', $badModules));
check($unbalanced === array(), '[#begin#]/[#end#] tags are balanced: ' . implode(', ', $unbalanced));
check($dumps === array(), 'no debug dumps in templates: ' . implode(', ', $dumps));
check($missing === array(), '[#inc#] targets resolve: ' . implode(', ', $missing));

// ------------------------------------------------------------------- engine
$jTemplateSource = (string)file_get_contents(ROOT . 'lib/core/Template/JTemplate.php');
$parserSource = (string)file_get_contents(ROOT . 'lib/core/Template/Parser.php');

check(str_contains($jTemplateSource, 'namespace MajorDoMo\\Core\\Template;'), 'engine lives in MajorDoMo\\Core\\Template');
check(!preg_match('/\bxslt_(create|process|free)\s*\(/', $parserSource), 'parser does not call removed Sablotron functions');

$data = array();
$xsltParser = new \MajorDoMo\Core\Template\Parser('layout.xslt', $data);
check(
    str_contains((string)$xsltParser->result, 'XSLT templates are not supported'),
    '.xslt renders an explicit "not supported" marker'
);
check(
    !is_dir(ROOT . '3rdparty/smarty3'),
    'Smarty library is gone: the project has a single template engine'
);

check(class_exists('jTemplate', false), 'legacy class jTemplate resolves');
check(class_exists('parser', false), 'legacy class parser resolves');
$empty = array();
check(
    is_a(new jTemplate('', $empty), 'MajorDoMo\\Core\\Template\\JTemplate'),
    'legacy jTemplate aliases the core engine'
);
check(get_class(new parser('', $empty)) === 'MajorDoMo\\Core\\Template\\Parser', 'legacy parser aliases the core parser');

if ($failures) {
    echo 'Template surface smoke tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Template surface smoke tests passed (' . count($templates) . ' templates) ' . PHP_EOL;
