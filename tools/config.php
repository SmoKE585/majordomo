<?php
/**
 * Configuration maintenance CLI.
 *
 * Usage:
 *   php tools/config.php doctor
 *   php tools/config.php cache
 *   php tools/config.php clear
 *   php tools/config.php snapshot
 *   php tools/config.php migrate --from=config.legacy.php [--write] [--force]
 *   php tools/config.php audit
 */

require_once __DIR__ . '/../lib/core.class.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\ConfigCache;
use MajorDoMo\Core\ConfigSchema;

$root = dirname(__DIR__);
$command = $argv[1] ?? 'help';
$options = array();
foreach (array_slice($argv, 2) as $argument) {
    if (!str_starts_with($argument, '--')) {
        continue;
    }
    $argument = substr($argument, 2);
    $separator = strpos($argument, '=');
    if ($separator === false) {
        $options[$argument] = true;
    } else {
        $options[substr($argument, 0, $separator)] = substr($argument, $separator + 1);
    }
}

switch ($command) {
    case 'doctor':
        exit(configDoctor($root));
    case 'cache':
        ConfigCache::clear($root);
        Config::boot($root);
        echo 'Configuration cache rebuilt: ' . ConfigCache::path($root) . PHP_EOL;
        exit(0);
    case 'clear':
        ConfigCache::clear($root);
        echo 'Configuration cache cleared.' . PHP_EOL;
        exit(0);
    case 'snapshot':
        Config::boot($root);
        $constants = get_defined_constants(true)['user'] ?? array();
        $schema = ConfigSchema::all();
        $result = array();
        foreach (array_keys($schema) as $key) {
            if (!array_key_exists($key, $constants)) {
                continue;
            }
            $value = $constants[$key];
            if ($schema[$key]['secret'] ?? false) {
                $value = '***';
            }
            $result[$key] = array('type' => gettype($value), 'value' => $value);
        }
        ksort($result);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    case 'migrate':
        exit(configMigrate($root, $options));
    case 'audit':
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/config-audit.php'), $status);
        exit((int)$status);
    case 'help':
    default:
        echo "Usage:\n"
            . "  php tools/config.php doctor\n"
            . "  php tools/config.php cache\n"
            . "  php tools/config.php clear\n"
            . "  php tools/config.php snapshot\n"
            . "  php tools/config.php audit\n"
            . "  php tools/config.php migrate --from=config.legacy.php [--write] [--force]\n";
        exit($command === 'help' ? 0 : 1);
}

function configDoctor(string $root): int
{
    Config::boot($root);
    $problems = 0;
    $warnings = 0;

    $report = static function (string $status, string $message) use (&$problems, &$warnings): void {
        if ($status === 'FAIL') $problems++;
        if ($status === 'WARN') $warnings++;
        printf("[%s] %s\n", $status, $message);
    };

    $report(is_file($root . '/.env') ? ' OK ' : 'FAIL', '.env ' . (is_file($root . '/.env') ? 'exists' : 'is missing (copy .env.example)'));
    $report(is_file($root . '/.env.example') ? ' OK ' : 'WARN', '.env.example ' . (is_file($root . '/.env.example') ? 'exists' : 'is missing'));

    $configSource = (string)@file_get_contents($root . '/config.php');
    $looksLikeLegacy = (bool)preg_match('/\bdefine\s*\(/i', $configSource);
    $looksLikeShim = str_contains($configSource, 'Config::boot');
    $report($looksLikeLegacy ? 'FAIL' : ' OK ', $looksLikeLegacy
        ? 'config.php still contains define() calls; run `php tools/config.php migrate`'
        : 'config.php is a bootstrap shim');
    if (!$looksLikeShim && !$looksLikeLegacy) {
        $report('WARN', 'config.php does not call Config::boot(); check the file');
    }

    foreach (Config::schema() as $key => $definition) {
        if (!($definition['required'] ?? false)) {
            continue;
        }
        $value = Config::get($key);
        if ($value === null || $value === '') {
            $report('FAIL', 'required key ' . $key . ' is empty');
        }
    }

    $cacheDirectory = $root . '/cms/cached';
    $cacheWritable = is_dir($cacheDirectory) && is_writable($cacheDirectory);
    $report($cacheWritable ? ' OK ' : 'WARN', $cacheWritable
        ? 'cms/cached is writable'
        : 'cms/cached is missing or not writable; configuration will be parsed on every request');

    $cachePath = ConfigCache::path($root);
    if (is_file($cachePath)) {
        $fingerprint = ConfigCache::fingerprint($root, ConfigSchema::all());
        $cached = ConfigCache::read($root, $fingerprint);
        $report($cached !== null ? ' OK ' : 'WARN', $cached !== null
            ? 'configuration cache is fresh'
            : 'configuration cache is stale; it will be rebuilt on the next boot');
    } else {
        $report('WARN', 'configuration cache is not built yet');
    }

    $dbHost = Config::string('DB_HOST');
    $dbPort = Config::string('DB_PORT');
    $dbName = Config::string('DB_NAME');
    if ($dbHost !== '' && $dbName !== '') {
        try {
            $connection = $dbPort !== ''
                ? new mysqli($dbHost, Config::string('DB_USER'), Config::string('DB_PASSWORD'), $dbName, (int)$dbPort)
                : new mysqli($dbHost, Config::string('DB_USER'), Config::string('DB_PASSWORD'), $dbName);
            $report(' OK ', 'database connection works (' . $dbName . '@' . $dbHost . ')');
            $connection->close();
        } catch (\mysqli_sql_exception $e) {
            $report('WARN', 'database connection failed: ' . $e->getMessage());
        }
    } else {
        $report('FAIL', 'DB_HOST or DB_NAME is empty');
    }

    $logDirectory = $root . '/cms/debmes';
    $report(is_dir($logDirectory) && is_writable($logDirectory) ? ' OK ' : 'WARN', 'cms/debmes ' . (is_dir($logDirectory) && is_writable($logDirectory) ? 'is writable' : 'is not writable'));

    echo PHP_EOL . 'Problems: ' . $problems . ', warnings: ' . $warnings . PHP_EOL;
    echo 'Legacy constant audit: php tools/config-audit.php' . PHP_EOL;
    return $problems > 0 ? 1 : 0;
}

/**
 * @param array<string, mixed> $options
 */
function configMigrate(string $root, array $options): int
{
    $source = (string)($options['from'] ?? 'config.legacy.php');
    if (!is_file($source)) {
        $source = $root . '/' . $source;
    }
    if (!is_file($source)) {
        fwrite(STDERR, 'Legacy config not found: ' . $source . PHP_EOL);
        return 2;
    }

    $runner = __DIR__ . '/config-migrate-runner.php';
    $descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $process = @proc_open(array(PHP_BINARY, $runner, $source), $descriptors, $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, 'Unable to start the migration runner.' . PHP_EOL);
        return 2;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fwrite(STDERR, 'Migration runner failed: ' . trim($stderr) . PHP_EOL);
        return 2;
    }

    $data = json_decode((string)$stdout, true);
    if (!is_array($data) || !isset($data['constants']) || !is_array($data['constants'])) {
        fwrite(STDERR, 'Unexpected migration output: ' . trim($stdout) . PHP_EOL);
        return 2;
    }

    $schema = ConfigSchema::all();
    $migrated = array();
    $skipped = array();
    foreach ($data['constants'] as $key => $value) {
        if (!isset($schema[$key])) {
            $skipped[] = $key . ' (not in schema)';
            continue;
        }
        if (in_array($key, array('DOC_ROOT', 'ROOT', 'SERVER_ROOT', 'PATH_TO_PHP'), true)) {
            $skipped[] = $key . ' (computed from the project path)';
            continue;
        }
        if (is_array($value)) {
            $migrated[$key] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            continue;
        }
        if (is_bool($value)) {
            $migrated[$key] = $value ? '1' : '0';
        } else {
            $migrated[$key] = (string)$value;
        }
    }
    if (!empty($data['timezone'])) {
        $migrated['TIMEZONE'] = (string)$data['timezone'];
    }
    if (!empty($data['restart_threads'])) {
        $migrated['RESTART_THREADS'] = implode(', ', array_map('strval', $data['restart_threads']));
    }
    if (!empty($data['aditional_git_urls'])) {
        $migrated['ADITIONAL_GIT_URLS'] = json_encode($data['aditional_git_urls'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    ksort($migrated);

    echo 'Migrated keys: ' . count($migrated) . PHP_EOL;
    foreach ($migrated as $key => $value) {
        $display = ($schema[$key]['secret'] ?? false) ? '***' : $value;
        echo '  ' . $key . '=' . $display . PHP_EOL;
    }
    if ($skipped) {
        echo 'Skipped: ' . implode(', ', $skipped) . PHP_EOL;
    }

    if (empty($options['write'])) {
        echo PHP_EOL . 'Dry run. Re-run with --write to create .env.' . PHP_EOL;
        return 0;
    }

    $envPath = $root . '/.env';
    if (is_file($envPath) && empty($options['force'])) {
        fwrite(STDERR, '.env already exists; use --force to overwrite (a backup will be created).' . PHP_EOL);
        return 2;
    }
    if (is_file($envPath)) {
        @copy($envPath, $envPath . '.bak.' . date('Ymd-His'));
    }

    $lines = array(
        '# Generated by `php tools/config.php migrate` from ' . basename($source) . '.',
        '# See .env.example for the full documented list.',
        '',
    );
    foreach ($migrated as $key => $value) {
        $lines[] = $key . '=' . configEnvValue($value);
    }
    $lines[] = '';

    if (@file_put_contents($envPath, implode(PHP_EOL, $lines), LOCK_EX) === false) {
        fwrite(STDERR, 'Unable to write ' . $envPath . PHP_EOL);
        return 2;
    }
    @copy($source, $root . '/config.php.bak.' . date('Ymd-His'));

    $cacheFile = ConfigCache::path($root);
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }

    echo PHP_EOL . 'Written: ' . $envPath . PHP_EOL;
    echo 'Backup:  ' . $root . '/config.php.bak.' . date('Ymd-His') . PHP_EOL;
    echo 'Next:    replace config.php with the shim, then run `php tools/config.php doctor`.' . PHP_EOL;
    return 0;
}

function configEnvValue(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (preg_match('/[\s#"\']/', $value)) {
        return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $value) . '"';
    }
    return $value;
}
