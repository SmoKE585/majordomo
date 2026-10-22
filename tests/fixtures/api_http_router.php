<?php
/** Loopback-only HTTP/visual fixture, never a deployment router. */
$project = dirname(__DIR__, 2);
$root = getenv('MJD_API_FIXTURE_ROOT');
if (!is_string($root) || realpath(dirname($root)) !== realpath(sys_get_temp_dir()) || !preg_match('/^mjd-api-[a-f0-9]+$/D', basename($root)) || getenv('APP_ENV') !== 'testing') { http_response_code(500); exit; }
define('ROOT', str_replace('\\', '/', $project) . '/');
define('DIR_MODULES', $root . '/modules/');
require_once $project . '/lib/core.class.php';
require_once __DIR__ . '/api_runtime.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/')) {
    $file = realpath($project . '/public' . $path);
    $assets = realpath($project . '/public/assets') . DIRECTORY_SEPARATOR;
    if (!$file || !str_starts_with($file, $assets) || !is_file($file)) { http_response_code(404); exit; }
    $types = array('css' => 'text/css', 'js' => 'text/javascript', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf');
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream')); readfile($file); exit;
}
if ($path === '/panel/api.html' || $path === '/preview') {
    chdir($root); ApiFixture::boot($root);
    session_save_path($root . '/sessions'); session_start();
    $GLOBALS['session'] = new class {
        public array $data;
        public function __construct() { if (!isset($_SESSION['data'])) $_SESSION['data'] = array(); $this->data =& $_SESSION['data']; }
        public function save(): void {}
    };
    // Only the shell module contract is substituted; the real API module and
    // template run against the same SQLite token service as HTTP API tests.
    class module {
        public string $name; public string $title; public string $module_category; public string $result;
        public function checkInstalled(): void {}
        public function parseLinks($value) { return $value; }
    }
    require ROOT . 'modules/api/api.class.php';
    $module = new api(); $module->run();
    $theme = ($_GET['theme'] ?? '') === 'dark' ? 'dark' : 'light';
    echo '<!doctype html><html lang="ru" data-bs-theme="' . $theme . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>API fixture</title><link rel="stylesheet" href="/assets/admin/admin.css"><script>window.ROOTHTML="/";</script><script src="/assets/admin/admin.js"></script></head><body class="md-admin-shell"><main class="p-3">' . $module->result . '</main></body></html>';
    exit;
}
$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_FIXTURE_PEER'] ?? '127.0.0.1';
if (isset($_SERVER['HTTP_X_FIXTURE_UNSETUP'])) unlink($root . '/cms/modules_installed/control_modules.installed');
$store = new MajorDoMo\Core\Api\TokenStore(ApiFixture::pdo($root));
(new MajorDoMo\Core\ApiApplication($root, static fn($token) => $store->verify($token)))->run();
