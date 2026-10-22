<?php
/** Isolated real auth/API boundary. Never deploy this router. */
$project = dirname(__DIR__, 2);
$root = getenv('MJD_AUTH_FIXTURE_ROOT');
if (!is_string($root) || realpath(dirname($root)) !== realpath(sys_get_temp_dir()) || !preg_match('/^mjd-auth-http-[a-f0-9]+$/D', basename($root)) || getenv('APP_ENV') !== 'testing') { http_response_code(500); exit; }
define('ROOT', str_replace('\\', '/', $project) . '/');
define('DIR_MODULES', $root . '/modules/');
require_once $project . '/lib/core.class.php';
require_once __DIR__ . '/api_runtime.php';
use MajorDoMo\Core\{Config, Core, ApiApplication};
use MajorDoMo\Core\Auth\{AuthGate, Authentication};
use MajorDoMo\Core\Users\UserAccounts;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/')) {
    $file = realpath($project . '/public' . $path);
    if (!$file || !str_starts_with($file, realpath($project . '/public/assets') . DIRECTORY_SEPARATOR) || !is_file($file)) { http_response_code(404); exit; }
    $types = array('css' => 'text/css', 'js' => 'text/javascript', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf');
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream')); readfile($file); exit;
}
Config::boot($root);
$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_FIXTURE_PEER'] ?? '203.0.113.7';
$db = ApiFixture::pdo($root);
$users = new UserAccounts($db);
if (str_starts_with($path, '/api/')) {
    $_SERVER['SCRIPT_FILENAME'] = $root . '/public/api.php';
    (new ApiApplication($root, static fn($token) => false, static fn() => $users))->run(); exit;
}
$_SERVER['SCRIPT_FILENAME'] = $path === '/admin.php' ? $root . '/public/admin.php'
    : ($path === '/objects/' ? $root . '/objects/index.php'
    : ($path === '/index.php' ? $root . '/public/index.php'
    : (str_starts_with($path, '/modules/') ? $root . $path : $root . '/public/nf.php')));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['auth'] ?? '') === 'login') {
    $probe = Core::session($root);
    header('X-Fixture-Guard: ' . (int)\MajorDoMo\Core\Auth\RequestGuard::allowed($probe, $_SERVER, $_POST));
    header('X-Fixture-Csrf: ' . (int)$probe->checkCsrf($_POST['_md_csrf'] ?? null));
    header('X-Fixture-Origin: ' . (int)\MajorDoMo\Core\Auth\RequestGuard::sameOrigin($_SERVER));
}
(new AuthGate($root, static fn() => $users))->protect();
$session = Core::session($root);
if ($path === '/fixture/expire') { $_SESSION['md_admin']['last'] = time() - 3601; echo 'expired'; exit; }
header('Content-Type: text/html; charset=utf-8');
$csrf = htmlspecialchars($session->csrfToken(), ENT_QUOTES, 'UTF-8');
echo '<!doctype html><html><head><meta name="md-csrf-token" content="' . $csrf . '"><script>window.ROOTHTML="/";</script><script src="/assets/admin/admin.js"></script></head><body class="md-admin-shell"><main>Protected admin</main><form method="post" action="/admin.php?auth=logout"><input type="hidden" name="_md_csrf" value="' . $csrf . '"><button>Выйти</button></form></body></html>';
