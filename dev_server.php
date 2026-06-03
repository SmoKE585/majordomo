<?php
/**
 * Local development server launcher/router for PHP built-in web server.
 *
 * Run from PhpStorm as a PHP script, or from terminal:
 *   php dev_server.php --host=127.0.0.1 --port=8080
 *
 * Database note: the current MajorDoMo SQL layer is MySQL/MariaDB-specific.
 * SQLite cannot be used without a separate SQL adapter and query compatibility work.
 */

const DEV_DEFAULT_HOST = '127.0.0.1';
const DEV_DEFAULT_PORT = '8080';
const DEV_DEFAULT_DB_HOST = '127.0.0.1';
const DEV_DEFAULT_DB_NAME = 'db_terminal_dev';
const DEV_DEFAULT_DB_USER = 'root';
const DEV_DEFAULT_DB_PASSWORD = '';

$projectRoot = __DIR__;

if (PHP_SAPI === 'cli') {
    devServerCli($argv, $projectRoot);
    exit;
}

$devEntrypoint = devServerRoute($projectRoot);
if (is_string($devEntrypoint)) {
    if (devEnsureDatabaseAvailable($projectRoot)) {
        try {
            require $devEntrypoint;
        } catch (Throwable $e) {
            devRenderThrowable($e);
        }
    }
}

function devServerCli(array $argv, $projectRoot)
{
    $options = devParseOptions($argv);
    $host = isset($options['host']) ? $options['host'] : devEnv('MAJORDOMO_DEV_HOST', DEV_DEFAULT_HOST);
    $port = isset($options['port']) ? $options['port'] : devEnv('MAJORDOMO_DEV_PORT', DEV_DEFAULT_PORT);

    if (!empty($options['help'])) {
        devPrintHelp();
        return;
    }

    chdir($projectRoot);
    devEnsureRuntimeDirs($projectRoot);

    if (empty($options['no-config'])) {
        devEnsureConfig($projectRoot, $host, $port);
    }

    $phpBinary = PHP_BINARY;
    $address = $host . ':' . $port;
    $command = escapeshellarg($phpBinary) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg(__FILE__);

    echo "MajorDoMo dev server\n";
    echo "URL: http://" . $address . "/\n";
    echo "Admin: http://" . $address . "/admin.php\n";
    echo "Root: " . $projectRoot . "\n";
    echo "Config: " . $projectRoot . DIRECTORY_SEPARATOR . "config.php\n";
    echo "Stop: Ctrl+C\n\n";

    passthru($command, $exitCode);
    exit((int)$exitCode);
}

function devServerRoute($projectRoot)
{
    chdir($projectRoot);
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('html_errors', '0');

    devRegisterFatalHandler();

    $path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
    $path = $path === null || $path === false ? '/' : $path;
    $relativePath = ltrim(rawurldecode($path), '/\\');
    $file = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

    if (devIsDeniedPath($path)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        return true;
    }

    if (strpos($path, '/api/') === 0) {
        return $projectRoot . DIRECTORY_SEPARATOR . 'api.php';
    }

    if ($path === '/' || $path === '') {
        return $projectRoot . DIRECTORY_SEPARATOR . 'index.php';
    }

    $phpEntrypoints = array('index.php', 'admin.php', 'api.php', 'diagnostic.php', 'backup.php', 'command.php', 'nf.php', 'print_all.php');
    $basename = basename($path);
    if (in_array($basename, $phpEntrypoints, true) && is_file($projectRoot . DIRECTORY_SEPARATOR . $basename)) {
        return $projectRoot . DIRECTORY_SEPARATOR . $basename;
    }

    if ($file !== false && strpos($file, realpath($projectRoot)) === 0 && is_file($file)) {
        devServeStaticFile($file);
        return true;
    }

    if (preg_match('/\.(html|xml)$/i', $path) || preg_match('#/module/app_mediabrowser\.#i', $path)) {
        return $projectRoot . DIRECTORY_SEPARATOR . 'nf.php';
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found: " . $path;
    return true;
}

function devEnsureDatabaseAvailable($projectRoot)
{
    $configPath = $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($configPath)) {
        devRenderDatabaseError('config.php not found', 'Run dev_server.php from CLI once to generate local config.php.');
        return false;
    }

    include_once $configPath;

    if (!defined('DB_HOST') || DB_HOST === '') {
        return true;
    }

    if (!function_exists('mysqli_connect')) {
        devRenderDatabaseError('mysqli extension is not enabled', 'Enable mysqli in this PHP installation.');
        return false;
    }

    $previousReportMode = null;
    if (function_exists('mysqli_report')) {
        $previousReportMode = mysqli_report(MYSQLI_REPORT_OFF);
    }

    $link = false;
    $error = '';
    $errno = 0;
    try {
        $link = @mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        if (!$link) {
            $errno = mysqli_connect_errno();
            $error = mysqli_connect_error();
        }
    } catch (Throwable $e) {
        $errno = (int)$e->getCode();
        $error = $e->getMessage();
    }

    if ($previousReportMode !== null) {
        mysqli_report($previousReportMode);
    }

    if ($link) {
        mysqli_close($link);
        return true;
    }

    devRenderDatabaseError(trim($errno . ' ' . $error), 'Start MySQL/MariaDB locally or edit config.php DB_* constants.');
    return false;
}

function devRenderDatabaseError($message, $hint)
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo "MajorDoMo dev database error\n";
    echo $message . "\n\n";
    echo $hint . "\n";
    echo "Current backend requirement: MySQL/MariaDB. SQLite is not supported by the current SQL layer.\n\n";
    echo "Expected config.php constants:\n";
    echo "DB_HOST=" . (defined('DB_HOST') ? DB_HOST : '<not defined>') . "\n";
    echo "DB_NAME=" . (defined('DB_NAME') ? DB_NAME : '<not defined>') . "\n";
    echo "DB_USER=" . (defined('DB_USER') ? DB_USER : '<not defined>') . "\n";
}

function devRegisterFatalHandler()
{
    register_shutdown_function(function () {
        $error = error_get_last();
        if (!$error) {
            return;
        }

        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo "\n\nMajorDoMo dev fatal error\n";
        echo $error['message'] . "\n";
        echo $error['file'] . ':' . $error['line'] . "\n";
    });
}

function devRenderThrowable(Throwable $e)
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo "MajorDoMo dev error\n";
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n\n";

    if ((int)$e->getCode() === 2002 || stripos($e->getMessage(), 'connect') !== false) {
        echo "Database connection failed.\n";
        echo "Current dev_server.php config expects MySQL/MariaDB, not SQLite.\n";
        echo "Check config.php DB_HOST, DB_NAME, DB_USER, DB_PASSWORD or start MySQL/MariaDB locally.\n\n";
    }

    echo $e->getTraceAsString();
}

function devIsDeniedPath($path)
{
    $basename = basename($path);
    if (in_array($basename, array('config.php', 'debmes.txt'), true)) {
        return true;
    }

    return (bool)preg_match('/\.(htaccess|htpasswd|ini|phps|fla|sql|log|sh|py)$/i', $path);
}

function devServeStaticFile($file)
{
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimeTypes = array(
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'xml' => 'application/xml; charset=utf-8',
    );

    if (!headers_sent()) {
        header('Content-Type: ' . (isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        readfile($file);
    }
}

function devParseOptions(array $argv)
{
    $options = array();
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--no-config') {
            $options['no-config'] = true;
            continue;
        }
        if (strpos($arg, '--host=') === 0) {
            $options['host'] = substr($arg, 7);
            continue;
        }
        if (strpos($arg, '--port=') === 0) {
            $options['port'] = substr($arg, 7);
            continue;
        }
    }
    return $options;
}

function devEnsureConfig($projectRoot, $host, $port)
{
    $configPath = $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    if (is_file($configPath)) {
        return;
    }

    $dbHost = devEnv('MAJORDOMO_DEV_DB_HOST', DEV_DEFAULT_DB_HOST);
    $dbName = devEnv('MAJORDOMO_DEV_DB_NAME', DEV_DEFAULT_DB_NAME);
    $dbUser = devEnv('MAJORDOMO_DEV_DB_USER', DEV_DEFAULT_DB_USER);
    $dbPassword = devEnv('MAJORDOMO_DEV_DB_PASSWORD', DEV_DEFAULT_DB_PASSWORD);
    $baseUrl = 'http://' . $host . ':' . $port;

    $config = "<?php\n"
        . "/**\n"
        . " * Local development config generated by dev_server.php.\n"
        . " * This file is ignored by git. Adjust DB credentials here or via MAJORDOMO_DEV_DB_* env vars.\n"
        . " */\n\n"
        . "Define('DB_HOST', " . var_export($dbHost, true) . ");\n"
        . "Define('DB_NAME', " . var_export($dbName, true) . ");\n"
        . "Define('DB_USER', " . var_export($dbUser, true) . ");\n"
        . "Define('DB_PASSWORD', " . var_export($dbPassword, true) . ");\n\n"
        . "Define('DIR_TEMPLATES', './templates/');\n"
        . "Define('DIR_MODULES', './modules/');\n"
        . "Define('DOC_ROOT', dirname(__FILE__));\n"
        . "Define('SERVER_ROOT', DOC_ROOT);\n"
        . "Define('ROOT', DOC_ROOT . '/');\n"
        . "Define('ROOTHTML', '/');\n"
        . "Define('PATH_TO_PHP', PHP_BINARY);\n"
        . "Define('PATH_TO_MYSQLDUMP', 'mysqldump');\n\n"
        . "Define('DEBUG_MODE', 1);\n"
        . "Define('PROJECT_TITLE', 'MajorDoMo Dev');\n"
        . "Define('PROJECT_BUGTRACK', '');\n"
        . "Define('PROJECT_DOMAIN', isset(\$_SERVER['SERVER_NAME']) ? \$_SERVER['SERVER_NAME'] : php_uname('n'));\n"
        . "Define('BASE_URL', " . var_export($baseUrl, true) . ");\n"
        . "Define('GIT_URL', '');\n"
        . "Define('MASTER_UPDATE_URL', '');\n"
        . "Define('PROJECT_URL', '');\n"
        . "Define('PROJECT_COMMIT_URL', '');\n"
        . "Define('DISABLE_WEBSOCKETS', 1);\n"
        . "date_default_timezone_set('Europe/Moscow');\n\n"
        . "\$restart_threads = array();\n"
        . "\$aditional_git_urls = array();\n";

    file_put_contents($configPath, $config);
    echo "Generated local config.php\n";
}

function devEnsureRuntimeDirs($projectRoot)
{
    $dirs = array(
        'cms',
        'cms/cached',
        'cms/debmes',
        'cms/images',
        'cms/saverestore',
        'cms/texts'
    );

    foreach ($dirs as $dir) {
        $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }
}

function devEnv($name, $default)
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

function devPrintHelp()
{
    echo "Usage: php dev_server.php [--host=127.0.0.1] [--port=8080] [--no-config]\n";
    echo "DB env vars: MAJORDOMO_DEV_DB_HOST, MAJORDOMO_DEV_DB_NAME, MAJORDOMO_DEV_DB_USER, MAJORDOMO_DEV_DB_PASSWORD\n";
}
