<?php

declare(strict_types=1);

/**
 * Composer/bootstrap contract. No application boot, .env, DB or network access.
 * Run after composer install: php tests/composer_smoke.php
 * Each probe uses a fresh PHP process and a temporary application root.
 */

if (($argv[1] ?? '') === '--probe') {
    $fixture = $argv[2];
    $entry = $argv[3];
    chdir(sys_get_temp_dir());
    require_once $fixture . '/' . $entry;
    $entryDirectory = getcwd();
    $packageValue = class_exists('ComposerSmoke\\Package') ? \ComposerSmoke\Package::value() : null;
    $functionValue = function_exists('composerSmokeValue') ? composerSmokeValue() : null;
    require_once $fixture . '/lib/core.class.php';
    require_once $fixture . '/bootstrap.php';

    echo json_encode(array(
        'core' => \MajorDoMo\Core\Env::parse('VALUE=ok')['VALUE'] === 'ok',
        'nestedCore' => class_exists(\MajorDoMo\Core\Cycles\CycleMaintenance::class),
        'package' => $packageValue,
        'function' => $functionValue,
        'includes' => $GLOBALS['composerSmokeIncludes'] ?? 0,
        'unknown' => class_exists('Unrelated\\MissingClass'),
        'root' => realpath(MD_APP_ROOT) === realpath($fixture),
        'cwd' => realpath(getcwd()) === realpath($fixture),
        'entryCwd' => realpath($entryDirectory)
            === realpath($entry === 'bootstrap.php' ? $fixture : sys_get_temp_dir()),
        'corePath' => (new ReflectionClass(\MajorDoMo\Core\Env::class))->getFileName(),
    ), JSON_THROW_ON_ERROR);
    exit(0);
}

$root = dirname(__DIR__);
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Run composer install before this test.\n");
    exit(1);
}

$failures = 0;
function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) $failures++;
    echo ($condition ? 'ok: ' : 'FAIL: ') . $message . PHP_EOL;
}

function probe(string $fixture, string $entry): array
{
    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', __FILE__, '--probe', $fixture, $entry),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        sys_get_temp_dir()
    );
    if (!is_resource($process)) throw new RuntimeException('Cannot start PHP probe');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return array(proc_close($process), $output, $errors);
}

$fixture = sys_get_temp_dir() . '/mjd-composer-' . bin2hex(random_bytes(6));
mkdir($fixture . '/lib/core/Cycles', 0777, true);
mkdir($fixture . '/package', 0777, true);
copy($root . '/bootstrap.php', $fixture . '/bootstrap.php');
copy($root . '/lib/core.class.php', $fixture . '/lib/core.class.php');
copy($root . '/lib/core/Env.php', $fixture . '/lib/core/Env.php');
copy($root . '/lib/core/Cycles/CycleMaintenance.php', $fixture . '/lib/core/Cycles/CycleMaintenance.php');

try {
    // Check the actual generated loader, not just the local core fallback.
    $loader = require $root . '/vendor/autoload.php';
    check($loader instanceof \Composer\Autoload\ClassLoader, 'Composer install generated a class loader');
    check(
        realpath($loader->findFile(\MajorDoMo\Core\Env::class)) === realpath($root . '/lib/core/Env.php'),
        'Composer maps the core namespace to lib/core'
    );
    check(
        realpath($loader->findFile(\MajorDoMo\Core\Cycles\CycleMaintenance::class))
            === realpath($root . '/lib/core/Cycles/CycleMaintenance.php'),
        'Composer maps nested core namespaces'
    );

    foreach (array(
        'PHPMailer\\PHPMailer\\PHPMailer' => array('php-mailer/Exception.php', 'php-mailer/PHPMailer.php', 'php-mailer/SMTP.php'),
        'Bluerhinos\\phpMQTT' => array('phpmqtt/phpMQTT.php'),
        'JasonGrimes\\Paginator' => array('Paginator/Paginator.php'),
    ) as $class => $files) {
        foreach ($files as $file) require_once $root . '/3rdparty/' . $file;
        check(class_exists($class, false), $class . ' still loads from 3rdparty alongside Composer');
    }

    foreach (array(false, true) as $withVendor) {
        if ($withVendor) {
            mkdir($fixture . '/vendor');
            file_put_contents($fixture . '/package/Package.php', <<<'PHP'
<?php
namespace ComposerSmoke;
final class Package { public static function value(): string { return 'package-ok'; } }
PHP
            );
            file_put_contents($fixture . '/vendor/autoload.php', '<?php' . PHP_EOL
                . '$loader = require ' . var_export($root . '/vendor/autoload.php', true) . ';' . PHP_EOL
                . '$loader->addPsr4("ComposerSmoke\\\\", __DIR__ . "/../package");' . PHP_EOL
                . '$GLOBALS["composerSmokeIncludes"] = ($GLOBALS["composerSmokeIncludes"] ?? 0) + 1;' . PHP_EOL
                . 'function composerSmokeValue(): string { return "function-ok"; }' . PHP_EOL
                . 'return $loader;' . PHP_EOL);
        }

        foreach (array('bootstrap.php', 'lib/core.class.php') as $entry) {
            $label = $entry . ($withVendor ? ' with Composer' : ' without vendor');
            [$code, $output, $errors] = probe($fixture, $entry);
            check($code === 0 && $errors === '', $label . ' exits cleanly');
            if ($code !== 0 || $errors !== '') {
                echo $output . $errors . PHP_EOL;
                continue;
            }
            $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            check($data['core'] && $data['nestedCore'], $label . ' loads core classes');
            $expectedCore = ($withVendor ? $root : $fixture) . '/lib/core/Env.php';
            check(realpath($data['corePath']) === realpath($expectedCore), $label . ' uses the expected loader');
            check($data['package'] === ($withVendor ? 'package-ok' : null), $label . ' resolves package classes');
            check($data['function'] === ($withVendor ? 'function-ok' : null), $label . ' resolves package functions');
            check($data['includes'] === ($withVendor ? 1 : 0), $label . ' includes vendor once');
            check(!$data['unknown'] && $data['root'] && $data['cwd'] && $data['entryCwd'], $label . ' preserves bootstrap contracts');
        }
    }

    file_put_contents($fixture . '/vendor/autoload.php', '<?php throw new RuntimeException("broken-vendor-probe");');
    [$code, $output, $errors] = probe($fixture, 'bootstrap.php');
    check($code !== 0 && str_contains($errors, 'broken-vendor-probe'), 'Broken vendor errors are not hidden');
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($fixture);
}

echo PHP_EOL . ($failures === 0 ? 'Composer smoke tests passed' : $failures . ' Composer check(s) failed') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
