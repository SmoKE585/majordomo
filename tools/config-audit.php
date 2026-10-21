<?php
/**
 * Static audit of legacy configuration constants.
 *
 * The migration keeps constants working, but new code must read values through
 * MajorDoMo\Core\Config. This tool lists every remaining constant reference so
 * the team can convert modules incrementally and see the remaining surface.
 *
 * Usage:
 *   php tools/config-audit.php
 *   php tools/config-audit.php --json
 *   php tools/config-audit.php --fail-on-legacy   # non-zero exit for CI
 *   php tools/config-audit.php --no-log
 */

require_once __DIR__ . '/../lib/core.class.php';

$options = array('json' => false, 'no-log' => false, 'fail-on-legacy' => false);
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--json') $options['json'] = true;
    elseif ($argument === '--no-log') $options['no-log'] = true;
    elseif ($argument === '--fail-on-legacy') $options['fail-on-legacy'] = true;
    elseif (str_starts_with($argument, '--help')) {
        echo "Usage: php tools/config-audit.php [--json] [--no-log] [--fail-on-legacy]\n";
        exit(0);
    }
}

$root = dirname(__DIR__);
$schema = \MajorDoMo\Core\ConfigSchema::all();
$removed = \MajorDoMo\Core\ConfigSchema::removed();
$names = array_fill_keys(array_merge(array_keys($schema), array_keys($removed)), true);

// Tests and tools are not modules; they intentionally define fixtures.
$skipDirectories = array('3rdparty', 'node_modules', 'docs', 'cms', 'backup', 'vendor', '.git', '.idea', '.codex', 'tests', 'tools');
$files = array();
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($skipDirectories): bool {
            return !$file->isDir() || !in_array($file->getFilename(), $skipDirectories, true);
        }
    )
);
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $files[] = $file->getPathname();
    }
}
sort($files);

/**
 * @return string relative path with forward slashes
 */
function auditRelative(string $path, string $root): string
{
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
}

$references = array();
$writers = array();
$skippedPrevious = array(
    T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NS_SEPARATOR, T_FUNCTION, T_CLASS, T_NEW,
    T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS, T_INTERFACE, T_TRAIT, T_ENUM, T_USE,
    T_NAMESPACE, T_CONST,
);
if (defined('T_NULLSAFE_OBJECT_OPERATOR')) $skippedPrevious[] = T_NULLSAFE_OBJECT_OPERATOR;

$referenceCount = 0;
foreach ($files as $path) {
    $relative = auditRelative($path, $root);
    if ($relative === 'tools/config-audit.php') {
        continue;
    }
    $source = @file_get_contents($path);
    if (!is_string($source) || strpos($source, '<?php') === false) {
        continue;
    }
    $tokens = @token_get_all($source);
    if (!is_array($tokens)) {
        continue;
    }
    $count = count($tokens);
    $line = 1;
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token)) {
            $line = $token[2];
        }
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        $name = $token[1];
        if (isset($names[$name])) {
            $previous = $i - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                $previous--;
            }
            $next = $i + 1;
            while ($next < $count && is_array($tokens[$next]) && in_array($tokens[$next][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                $next++;
            }
            $previousToken = $previous >= 0 ? $tokens[$previous] : null;
            $nextToken = $next < $count ? $tokens[$next] : null;
            if (is_array($previousToken) && in_array($previousToken[0], $skippedPrevious, true)) {
                continue;
            }
            if (is_array($nextToken) && $nextToken[0] === T_DOUBLE_COLON) {
                continue;
            }
            if ($nextToken === '(') {
                continue;
            }
            $references[] = array('key' => $name, 'file' => $relative, 'line' => $line, 'kind' => 'read');
            $referenceCount++;
            continue;
        }

        // defined('KEY'), constant('KEY') and define('KEY', ...)
        $function = strtolower($name);
        if (!in_array($function, array('defined', 'constant', 'define'), true)) {
            continue;
        }
        $open = $i + 1;
        while ($open < $count && is_array($tokens[$open]) && in_array($tokens[$open][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            $open++;
        }
        if ($open >= $count || $tokens[$open] !== '(') {
            continue;
        }
        $argument = $open + 1;
        while ($argument < $count && is_array($tokens[$argument]) && in_array($tokens[$argument][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            $argument++;
        }
        if ($argument >= $count || !is_array($tokens[$argument]) || $tokens[$argument][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $name = trim($tokens[$argument][1], "'\"");
        if (!isset($names[$name])) {
            continue;
        }
        if ($function === 'define') {
            $writers[] = array('key' => $name, 'file' => $relative, 'line' => $line);
        } else {
            $references[] = array('key' => $name, 'file' => $relative, 'line' => $line, 'kind' => $function);
            $referenceCount++;
        }
    }
}

$structuralKeys = array('ROOT', 'DOC_ROOT', 'DIR_TEMPLATES', 'DIR_MODULES', 'ROOTHTML', 'SERVER_ROOT', 'PATH_TO_PHP');
$byKey = array();
$byFile = array();
$structuralReferences = 0;
foreach ($references as $reference) {
    $byKey[$reference['key']] = ($byKey[$reference['key']] ?? 0) + 1;
    $byFile[$reference['file']] = ($byFile[$reference['file']] ?? 0) + 1;
    if (in_array($reference['key'], $structuralKeys, true)) {
        $structuralReferences++;
    }
}
arsort($byKey);
arsort($byFile);

$summary = array(
    'generated_at' => date('c'),
    'root' => $root,
    'files_scanned' => count($files),
    'reference_count' => $referenceCount,
    'structural_reference_count' => $structuralReferences,
    'config_reference_count' => $referenceCount - $structuralReferences,
    'files_with_references' => count($byFile),
    'keys' => $byKey,
    'writers' => $writers,
    'references' => $references,
);

if ($options['json']) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo 'Config audit: ' . $referenceCount . ' legacy references in ' . count($byFile) . ' files' . PHP_EOL;
    echo '  structural (ROOT, DIR_*, ...): ' . $structuralReferences . PHP_EOL;
    echo '  config (DB_*, flags, URLs):  ' . ($referenceCount - $structuralReferences) . PHP_EOL;
    echo 'Keys: ' . count($byKey) . ', writers (define): ' . count($writers) . PHP_EOL . PHP_EOL;
    echo 'Top keys:' . PHP_EOL;
    $limit = 0;
    foreach ($byKey as $key => $count) {
        if ($limit++ >= 15) break;
        printf("  %-42s %d\n", $key, $count);
    }
    echo PHP_EOL . 'Top files:' . PHP_EOL;
    $limit = 0;
    foreach ($byFile as $file => $count) {
        if ($limit++ >= 15) break;
        printf("  %-60s %d\n", $file, $count);
    }
    if ($writers) {
        echo PHP_EOL . 'Modules that still call define() for configuration keys:' . PHP_EOL;
        foreach ($writers as $writer) {
            printf("  %-42s %s:%d\n", $writer['key'], $writer['file'], $writer['line']);
        }
    }
}

if (!$options['no-log']) {
    $directory = $root . '/cms/debmes';
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
    $log = '[' . date('Y-m-d H:i:s') . '] config audit: ' . $referenceCount . ' references in ' . count($byFile) . ' files'
        . ' (structural: ' . $structuralReferences . ', config: ' . ($referenceCount - $structuralReferences) . ', keys: ' . count($byKey) . ', writers: ' . count($writers) . ')' . PHP_EOL;
    foreach ($references as $reference) {
        $log .= sprintf(
            "[%s] %-42s %-8s %s:%d\n",
            date('Y-m-d H:i:s'),
            $reference['key'],
            $reference['kind'],
            $reference['file'],
            $reference['line']
        );
    }
    foreach ($writers as $writer) {
        $log .= sprintf("[%s] %-42s define   %s:%d\n", date('Y-m-d H:i:s'), $writer['key'], $writer['file'], $writer['line']);
    }
    @file_put_contents($directory . '/config-audit.log', $log, FILE_APPEND | LOCK_EX);
}

if ($options['fail-on-legacy'] && $referenceCount > 0) {
    exit(1);
}
exit(0);
