<?php
/**
 * [#if ...#] condition equivalence test (no database required).
 *
 * The engine used to translate a condition into PHP and run it through eval().
 * It now uses MajorDoMo\Core\Template\Condition, a small parser with the same
 * semantics. This test replays the old implementation as a reference and checks
 * both agree on every condition that exists in templates/, over a set of data
 * shapes (missing keys, empty strings, numbers, booleans, random values).
 *
 * Run: php tests/template_condition_smoke.php
 */

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__) . '/');
}

require ROOT . 'lib/core.class.php';

use MajorDoMo\Core\Template\Condition;

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
 * The eval()-based implementation this test guards against regressions from.
 *
 * @param array<string, mixed> $hash
 */
function referenceCondition(string $condition, array $hash): bool
{
    $condition = preg_replace('/^!(\w+)$/', '!(isset($hash[\'\\1\'])?$hash[\'\\1\']:null)', $condition);
    $condition = preg_replace('/^(\w+)$/', '(isset($hash[\'\\1\'])?$hash[\'\\1\']:null)', $condition);
    $condition = preg_replace('/(\w+)(?=[=!<>])/', '(isset($hash[\'\\1\'])?$hash[\'\\1\']:null)', $condition);
    $condition = preg_replace('/(\w+)[[:space:]](?=[=!<>])/', '(isset($hash[\'\\1\'])?$hash[\'\\1\']:null)', $condition);
    $condition = preg_replace('/\((\w+)\)/', '(isset($hash[\'\\1\'])?$hash[\'\\1\']:null)', $condition);
    $condition = preg_replace('/\]=(?=[^\w=])/', ']==', $condition);
    $condition = preg_replace('/:null\)=(?=[^\w=])/', ':null)==', $condition);

    $result = false;
    eval('$result = (bool)(' . $condition . ');');

    return (bool)$result;
}

// --------------------------------------------------------------- conditions
$conditions = array();
$identifiers = array();
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(ROOT . 'templates', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!preg_match('/\.html$/', $file->getFilename())) {
        continue;
    }
    $content = (string)file_get_contents($file->getPathname());
    if (!preg_match_all('/\[#if (.+?)#\]/s', $content, $matches)) {
        continue;
    }
    foreach ($matches[1] as $condition) {
        $condition = trim($condition);
        $conditions[$condition] = true;
        preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\b/', $condition, $words);
        foreach ($words[1] as $word) {
            $upper = strtoupper($word);
            if (in_array($upper, array('AND', 'OR', 'NOT', 'TRUE', 'FALSE', 'NULL'), true)) {
                continue;
            }
            $identifiers[$word] = true;
        }
    }
}
$conditions = array_keys($conditions);
$identifiers = array_keys($identifiers);

check(count($conditions) > 500, 'collected template conditions (' . count($conditions) . ')');
check($identifiers !== array(), 'collected condition identifiers (' . count($identifiers) . ')');

// ------------------------------------------------------------------ fixtures
$fixtures = array(
    'missing keys' => array(),
    'empty strings' => array_fill_keys($identifiers, ''),
    'string "0"' => array_fill_keys($identifiers, '0'),
    'string "1"' => array_fill_keys($identifiers, '1'),
    'strings' => array_fill_keys($identifiers, 'v'),
    'int 0' => array_fill_keys($identifiers, 0),
    'int 1' => array_fill_keys($identifiers, 1),
    'nulls' => array_fill_keys($identifiers, null),
);

$values = array('', '0', '1', 'v', 'admin', 'search_mqtt', 0, 1, 2);
mt_srand(20261022);
for ($fixture = 1; $fixture <= 20; $fixture++) {
    $data = array();
    foreach ($identifiers as $identifier) {
        $data[$identifier] = $values[mt_rand(0, count($values) - 1)];
    }
    $fixtures['random #' . $fixture] = $data;
}

// ------------------------------------------------------------------ compare
$parseErrors = array();
$mismatches = array();
$comparisons = 0;
$referenceErrors = array();

foreach ($conditions as $condition) {
    try {
        Condition::evaluate($condition, array());
    } catch (\Throwable $e) {
        $parseErrors[] = $condition . ' — ' . $e->getMessage();
    }
}

foreach ($fixtures as $fixtureName => $data) {
    foreach ($conditions as $condition) {
        $comparisons++;

        $expected = null;
        try {
            $expected = referenceCondition($condition, $data);
        } catch (\Throwable $e) {
            $referenceErrors[$condition] = $e->getMessage();
        }

        $actual = null;
        $error = null;
        try {
            $actual = Condition::evaluate($condition, $data);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($expected === null) {
            continue; // old implementation could not evaluate it at all
        }

        if ($error !== null || $actual !== $expected) {
            if (count($mismatches) < 10) {
                $mismatches[] = $condition . ' [' . $fixtureName . '] expected '
                    . var_export($expected, true) . ', got '
                    . ($error !== null ? 'error: ' . $error : var_export($actual, true));
            }
        }
    }
}

check($parseErrors === array(), 'every condition parses: ' . implode('; ', array_slice($parseErrors, 0, 5)));
check($mismatches === array(), 'condition results match the old eval() implementation: ' . implode('; ', $mismatches));

// Escaping filter: [#VAR|html#] must produce entities, [#VAR#] must not.
$data = array('TITLE' => '<b>"x" & \'y\'</b>', 'ROW' => array('FIELD' => '<i>z</i>'));
$plain = '[#TITLE#]';
$escaped = '[#TITLE|html#]';
$nested = '[#ROW.FIELD|html#]';

$render = static function (string $source, array $vars): string {
    $template = new \MajorDoMo\Core\Template\JTemplate($source, $vars);
    return (string)$template->result;
};

check($render($escaped, $data) === '&lt;b&gt;&quot;x&quot; &amp; &#039;y&#039;&lt;/b&gt;', '[#VAR|html#] escapes HTML');
check($render($nested, $data) === '&lt;i&gt;z&lt;/i&gt;', '[#ARRAY.FIELD|html#] escapes HTML');
check($render($plain, array('TITLE' => '<b>x</b>')) === '<b>x</b>', '[#VAR#] stays raw for legacy templates');
check(
    $render('[#TITLE#]', array('TITLE' => '[#TITLE#]')) === '&#091#TITLE#&#093',
    'raw values cannot inject template tags'
);

echo 'Compared ' . count($conditions) . ' conditions × ' . count($fixtures) . ' fixtures = '
    . $comparisons . ' evaluations' . PHP_EOL;
if ($referenceErrors !== array()) {
    echo 'Note: ' . count($referenceErrors) . ' condition(s) could not be evaluated by the old code at all' . PHP_EOL;
}

if ($failures) {
    echo 'Template condition smoke tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Template condition smoke tests passed' . PHP_EOL;
